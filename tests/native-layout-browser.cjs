/* Run with browser-preview.php on 127.0.0.1:8771 and official 6.0 theme CSS in the temp fixture directory. */
'use strict';
const {chromium} = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
(async () => {
    const output = fs.mkdtempSync(path.join(os.tmpdir(), 'governance-native-qa-'));
    const browser = await chromium.launch({channel:'msedge', headless:true});
    try {
        const page = await browser.newPage({viewport:{width:1440,height:1000}, acceptDownloads:true});
        const errors = []; page.on('pageerror', e => errors.push(e.message));
        await page.route('**/*', r => new URL(r.request().url()).hostname === '127.0.0.1' ? r.continue() : r.abort());
        for (const theme of ['', '&light&en']) {
            for (const [name, query] of [['quality','?quality'],['quality-config','?quality&edit'],['availability','?sample'],['availability-config','?edit']]) {
                await page.goto('http://127.0.0.1:8771/' + query + theme);
                const header = page.locator('header.header-title');
                await header.locator('.gov-action-link').waitFor();
                assert.equal(await page.locator('main .gov-page-actions').count(),0);
                assert.equal(await header.locator('h1').count(),1);
                assert.equal(await header.locator('.gov-action-link').evaluate(e => e.getBoundingClientRect().height),24);
                await page.mouse.move(0,0);
                assert.equal(await header.locator('.gov-action-link').evaluate(e => getComputedStyle(e).color),theme ? 'rgb(2, 117, 184)' : 'rgb(118, 141, 153)', 'Link uses native secondary-button color');
                await header.locator('.gov-action-link').hover();
                await page.waitForFunction(color => getComputedStyle(document.querySelector('.gov-action-link')).color === color,theme ? 'rgb(255, 255, 255)' : 'rgb(242, 242, 242)');
                assert.equal(await header.locator('.gov-action-link').evaluate(e => getComputedStyle(e).color),theme ? 'rgb(255, 255, 255)' : 'rgb(242, 242, 242)', 'Readable native hover');
                await page.mouse.move(0,0);
                assert.equal(await page.locator('.gqp-heading,.gav-page-heading').count(),0);
                if (name === 'quality-config') {
                    await page.locator('#gov-save:not([disabled])').waitFor();
                    await page.locator('#gov-config-pages [data-page-id=network]').click();
                    assert.match(await header.locator('#gov-back-dashboard').getAttribute('href'), /page=network$/);
                    await page.locator('#gov-add-page').click();
                    assert.match(await header.locator('#gov-back-dashboard').getAttribute('href'), /page=main$/);
                }
                if (name === 'availability') {
                    await header.locator('#gav-export:not([disabled])').waitFor();
                    assert.equal(await header.locator('#gav-print:not([disabled])').count(),1);
                    const download = page.waitForEvent('download');
                    await header.locator('#gav-export').click();
                    const result = await download;
                    const report = JSON.parse(fs.readFileSync(await result.path(),'utf8'));
                    assert.equal(report.format,'governance-availability-v3');
                    assert.ok(report.report.departments.length > 0, 'Export retains report data');
                    await page.evaluate(() => { window.print = () => { window.__didPrint = true; }; });
                    await header.locator('#gav-print').click();
                    assert.equal(await page.evaluate(() => window.__didPrint),true);
                }
                for (const width of [1440,1024,700]) {
                    await page.setViewportSize({width,height:1000});
                    await header.scrollIntoViewIfNeeded();
                    // Zabbix 6's own header has min-width:1200px. Do not change native layout globally.
                    if (width >= 1440) assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1),name+' fits desktop');
                    assert.ok(await header.locator('.gov-action-link').isVisible());
                    await page.screenshot({path:path.join(output,name+(theme?'-light':'-dark')+'-'+width+'.png')});
                }
                await page.setViewportSize({width:1440,height:1000});
                if (name === 'availability') {
                    await page.route('**/*action=governance.availability.run*', r => r.abort());
                    await page.locator('[name=department]').selectOption('0');
                    await page.locator('#gav-calculate').click();
                    assert.equal(await header.locator('#gav-filter-actions').isVisible(),false,'Starting a new calculation invalidates report actions');
                    assert.equal(await header.locator('.gov-action-link').isVisible(),true,'Configuration stays available');
                }
            }
        }
        assert.deepEqual(errors,[]);
        console.log('PASS: four native headers, two themes/languages, 1440/1024/700px, saved-page navigation, export/print and stale actions. Screenshots: '+output);
    } finally { await browser.close(); }
})().catch(e => {console.error(e);process.exitCode=1;});
