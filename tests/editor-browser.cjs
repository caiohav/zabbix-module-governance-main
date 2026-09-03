/* Local visual/interaction QA. Requires a loopback browser-preview.php server and Playwright. */
const {chromium} = require('playwright');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const assert = require('node:assert/strict');
(async () => {
    const output = fs.mkdtempSync(path.join(os.tmpdir(), 'governance-editor-qa-'));
    const browser = await chromium.launch({channel: 'msedge', headless: true});
    try {
        const page = await browser.newPage({viewport:{width:1440,height:1000}});
        const errors = []; page.on('pageerror', e => errors.push(e.message));
        const posts = []; page.on('request', r => { if(r.method()==='POST') posts.push(r.url()); });
        let lastResponse = ''; page.on('response', async r => { if(r.request().method()==='POST') lastResponse=(await r.text()).slice(0,1500); });
        await page.route('**/*', route => new URL(route.request().url()).hostname === '127.0.0.1' ? route.continue() : route.abort());
        for (const [name, query] of [['quality-dark','?quality&edit'],['quality-light','?quality&edit&light&en'],['availability-dark','?edit'],['availability-light','?edit&light&en']]) {
            const priorPosts = posts.length;
            await page.goto('http://127.0.0.1:8771/' + query);
            await page.locator(name.startsWith('quality') ? '#gov-save:not([disabled])' : '#gav-save:not([disabled])').waitFor();
            if (name.startsWith('quality')) {
                const card = page.locator('.gqp-card').first();
                await card.locator('.gqp-add-condition').click();
                let row = card.locator('.gqp-conditions tr').first();
                await row.locator('[data-field=condition_name]').fill('Departamento');
                await row.locator('[data-field=condition_value]').fill('Banco de Dados');
                await card.locator('.gqp-add-condition').click();
                row = card.locator('.gqp-conditions tr').nth(1);
                await row.locator('[data-field=condition_type]').selectOption('group');
                await row.locator('[data-field=condition_value]').fill('Equipes');
                await card.locator('[data-field=type]').selectOption('templates');
                await card.locator('[data-field=template_names]').fill('Linux by Zabbix agent');
                const draft = JSON.parse(await page.locator('#gov-quality-payload').inputValue());
                assert.equal(draft[0].cards[0].selection.conditions.length,2);
                assert.equal(draft[0].cards[0].selection.mode,'all');
                await card.locator('.gqp-add-condition').click();
                const third = card.locator('.gqp-conditions tr').nth(2);
                await third.locator('[data-field=condition_type]').selectOption('inventory');
                await third.locator('[data-field=condition_inventory]').selectOption('location');
                await card.locator('[data-field=selection_mode]').selectOption('custom');
                await card.locator('[data-field=selection_formula]').fill('(A or B) and C');
                assert.equal(JSON.parse(await page.locator('#gov-quality-payload').inputValue())[0].cards[0].selection.formula,'(A or B) and C');
                assert.equal(posts.length,priorPosts,'No automatic preview requests');
                // Catalog opens without a request and adds names, preserving subgroup semantics.
                await row.locator('[data-field=condition_value]').fill('');
                await row.locator('.gqp-catalog-open').click();
                const dialog = page.locator('.gqp-catalog-dialog');
                await dialog.waitFor();
                assert.equal(posts.length,priorPosts,'Opening catalog does not query');
                await dialog.locator('input').fill('Equipes');
                await dialog.locator('.gqp-catalog-search').click();
                await dialog.locator('.gqp-catalog-result').click();
                assert.equal(await row.locator('[data-field=condition_value]').inputValue(),'Equipes');
                assert.equal(await row.locator('[data-field=condition_subgroups]').inputValue(),'1');
                await card.locator('[data-field=template_names]').fill('');
                await card.locator('[data-for-type=templates] .gqp-catalog-open').click();
                await dialog.locator('input').fill('Linux');
                await dialog.locator('input').press('Enter');
                await dialog.locator('.gqp-catalog-result').waitFor();
                await page.screenshot({path:path.join(output,name+'-catalog.png'),fullPage:true});
                await dialog.locator('.gqp-catalog-result').click();
                assert.equal(await card.locator('[data-field=template_names]').inputValue(),'Linux by Zabbix agent');
                assert.equal(posts.length,priorPosts+2,'Exactly one request per explicit search');
                await card.locator('[data-for-type=templates] .gqp-catalog-open').click();
                await dialog.locator('input').fill('Linux');
                await dialog.locator('.gqp-catalog-search').click();
                await dialog.locator('.gqp-catalog-result').click();
                assert.equal(await card.locator('[data-field=template_names]').inputValue(),'Linux by Zabbix agent','Selection never duplicates a name');
                await row.locator('.gqp-catalog-open').click();
                await dialog.locator('input').fill('Equipes');
                await dialog.locator('.gqp-catalog-search').click();
                await dialog.locator('.gqp-catalog-result').waitFor();
                await dialog.locator('input').fill('New query');
                assert.equal(await dialog.locator('.gqp-catalog-result').count(),0,'Editing search discards old results');
                await dialog.locator('input').press('Escape');
                assert.equal(await dialog.count(),0,'Escape closes lookup');
                assert.equal(await row.locator('[data-field=condition_value]').inputValue(),'Equipes','Closing preserves condition');
                await card.locator('.gqp-preview > button').first().click();
                await card.locator('.gqp-preview-output table').waitFor({timeout:10000}).catch(async e => { throw new Error((await card.locator('.gqp-preview-output').innerText())+'; '+lastResponse+'; '+e.message); });
                assert.equal(await card.locator('.gqp-preview-output table tr').count(),51,'50 real synthetic sample rows plus header');
                assert.ok((await card.locator('.gqp-preview-output').innerText()).includes('251'),'Exact total independent of sample');
                await card.locator('[data-field=title]').fill('Hosts com template Linux');
                assert.equal(await card.locator('.gqp-preview-output').innerText(),'','Edits invalidate preview');
            }
            await page.screenshot({path:path.join(output,name+'.png'),fullPage:true});
            console.log(name, await page.evaluate(() => ({width:innerWidth,scroll:document.documentElement.scrollWidth,inputs:[...document.querySelectorAll('input[type=text],select')].filter(x=>x.getBoundingClientRect().width>0).slice(0,4).map(x=>getComputedStyle(x).backgroundColor)})));
            await page.setViewportSize({width:700,height:1000});
            assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth <= innerWidth + 1), name+' mobile overflow');
            await page.screenshot({path:path.join(output,name+'-mobile.png'),fullPage:true});
            await page.setViewportSize({width:1440,height:1000});
        }
        assert.deepEqual(errors,[]);
        console.log('PASS: local browser editors, PT/EN, light/dark, desktop/mobile. Screenshots: '+output);
    } finally { await browser.close(); }
})().catch(e=>{console.error(e);process.exitCode=1;});
