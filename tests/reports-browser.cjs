/* Local synthetic QA. Run browser-preview.php on 8771 and availability-observed-preview.php on 8772. */
'use strict';
const {chromium} = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const luminance = hex => hex.replace('#', '').match(/../g).map(value => parseInt(value, 16) / 255)
    .map(value => value <= .04045 ? value / 12.92 : ((value + .055) / 1.055) ** 2.4)
    .reduce((sum, value, index) => sum + value * [.2126, .7152, .0722][index], 0);
const contrast = (first, second) => (Math.max(luminance(first), luminance(second)) + .05)
    / (Math.min(luminance(first), luminance(second)) + .05);

(async () => {
    const output = fs.mkdtempSync(path.join(os.tmpdir(), 'governance-reports-qa-'));
    const browser = await chromium.launch({channel: 'msedge', headless: true});
    try {
        const page = await browser.newPage({viewport: {width: 1440, height: 1000}});
        page.setDefaultTimeout(10000);
        const errors = []; const queries = [];
        page.on('pageerror', error => errors.push(error.message));
        page.on('request', request => { if (request.method() === 'POST') queries.push(request.url()); });
        await page.route('**/*', route => new URL(route.request().url()).hostname === '127.0.0.1' ? route.continue() : route.abort());
        for (const light of [false, true]) {
            const suffix = light ? '&light&en' : '';
            const theme = light ? 'light' : 'dark';
            await page.goto('http://127.0.0.1:8771/?quality' + suffix);
            await page.locator('#gqp-refresh:not([disabled])').waitFor();
            await page.waitForFunction(() => document.querySelector('[_echarts_instance_]'));
            assert.equal(await page.locator('#gqp-diagnostics').getAttribute('open'), null);
            assert.equal(await page.locator('#gqp-timing').isVisible(), false);
            assert.equal(await page.locator('#gqp-message').isVisible(), true);
            assert.equal(await page.locator('#gqp-cards').getAttribute('aria-busy'), 'false');
            assert.equal(await page.locator('.gov-card-header h3').first().evaluate(node => getComputedStyle(node).fontSize), '13px');
            const palette = await page.locator('#gqp-dashboard').evaluate(node => {
                const style = getComputedStyle(node);
                return ['good', 'warning', 'critical', 'coverage', 'native-slo', 'muted'].map(key => style.getPropertyValue('--gov-' + key).trim());
            });
            palette.forEach(color => assert.ok(contrast(color, light ? '#eceeef' : '#2b2b2b') >= 4.5, color + ': readable report semantic text'));
            const before = queries.length;
            await page.locator('#gqp-diagnostics > summary').click();
            assert.match(await page.locator('#gqp-timing').innerText(), /API/);
            await page.locator('#gqp-diagnostics > summary').click();
            assert.equal(queries.length, before, 'Query details are already available, no new query');
            const gauges = await page.locator('[_echarts_instance_]').evaluateAll(nodes => nodes.map(node => {
                const option = echarts.getInstanceByDom(node).getOption();
                return [option.series[0].type, option.series[0].min, option.series[0].max, option.backgroundColor];
            }));
            assert.ok(gauges.length > 0);
            gauges.forEach(gauge => assert.deepEqual(gauge, ['gauge', 0, 100, 'transparent']));
            await page.screenshot({path: path.join(output, 'quality-' + theme + '.png'), fullPage: true});

            for (const scenario of ['trend', 'notqueried', 'seed', 'allunknown', 'observed90']) {
                await page.goto('http://127.0.0.1:8772/?case=' + scenario + suffix);
                await page.locator('#gav-export:not([disabled])').waitFor();
                const technology = page.locator('.gav-tech-detail').first();
                await technology.locator(':scope > summary').click();
                const source = technology.locator('.gav-source').first();
                const details = page.locator('.gav-source-details').first();
                assert.equal(await details.getAttribute('open'), null);
                assert.equal(await page.locator('.gav-hosts-table tr:first-child td:nth-child(2)').evaluate(node => getComputedStyle(node).textAlign), 'right');
                if (scenario === 'trend') {
                    assert.match(await source.innerText(), light ? /Conservative hourly trends/ : /Trends horárias conservadoras/);
                    assert.doesNotMatch(await source.innerText(), /Amostras no período|Samples in period|nenhuma amostra|no sample/);
                    await details.locator(':scope > summary').click();
                    assert.match(await details.innerText(), light ? /Trend hours:/ : /Horas com trend:/);
                    await details.locator(':scope > summary').click();
                }
                if (scenario === 'notqueried') assert.equal(await source.locator('.gav-source-alert').isVisible(), true);
                if (scenario === 'seed') assert.equal(await source.locator('.gav-source-alert').count(), 0, 'Valid seed is not called missing data');
                const beforePrint = queries.length;
                await page.evaluate(() => window.dispatchEvent(new Event('beforeprint')));
                assert.equal(await details.getAttribute('open'), '');
                await page.evaluate(() => window.dispatchEvent(new Event('afterprint')));
                assert.equal(await details.getAttribute('open'), null, 'Print restores collapsed metadata');
                assert.equal(queries.length, beforePrint, 'Printing/disclosure never recalculates');
                const bars = await page.locator('.gav-chart').first().evaluate(node => {
                    const option = echarts.getInstanceByDom(node).getOption();
                    return [option.series[0].type, option.yAxis[0].min, option.yAxis[0].max, option.backgroundColor];
                });
                assert.deepEqual(bars, ['bar', 0, 100, 'transparent']);
                if (scenario === 'trend' || scenario === 'notqueried') await page.screenshot({path: path.join(output, scenario + '-' + theme + '.png'), fullPage: true});
            }
        }
        for (const scenario of ['failure', 'empty']) {
            await page.goto('http://127.0.0.1:8771/zabbix.php?action=governance.quality.view&preview_case=' + scenario);
            await page.locator('#gqp-refresh:not([disabled])').waitFor();
            assert.equal(await page.locator('#gqp-message').isVisible(), true);
            assert.equal(await page.locator('#gqp-diagnostics').getAttribute('open'), null);
            if (scenario === 'empty') assert.match(await page.locator('#gqp-score-help').innerText(), /Nenhum host/);
            else assert.match(await page.locator('#gqp-message').innerText(), /falharam|falhou|indisponív/i);
        }
        await page.route('**/*action=governance.quality.run*', route => route.abort());
        await page.goto('http://127.0.0.1:8771/?quality');
        await page.locator('#gqp-retry:visible').waitFor();
        assert.match(await page.locator('#gqp-message').innerText(), /Falha de comunicação/);
        assert.equal(await page.locator('#gqp-message').evaluate(node => getComputedStyle(node).color), 'rgb(241, 143, 143)', 'Query error stays visible in the dark-theme error color');
        assert.equal(await page.locator('#gqp-diagnostics').getAttribute('open'), null);
        assert.deepEqual(errors, []);
        console.log('PASS: report diagnostics, source warnings, seed/trends/missing data, themes/PT/EN, gauge/bar 0–100 and print restoration. Screenshots: ' + output);
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
