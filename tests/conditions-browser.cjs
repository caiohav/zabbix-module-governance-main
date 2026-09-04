/* Interaction QA of the real condition editor. Synthetic loopback fixture only. */
'use strict';
const {chromium} = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs'), os = require('node:os'), path = require('node:path');
(async () => {
    const output = fs.mkdtempSync(path.join(os.tmpdir(),'governance-conditions-qa-'));
    const browser = await chromium.launch({channel:'msedge',headless:true});
    try {
        for (const light of [false,true]) {
            const page = await browser.newPage({viewport:{width:1440,height:1000}});
            const errors = [], requests = [];
            page.on('pageerror', e => errors.push(e.message));
            page.on('request', r => { if(r.method()==='POST') requests.push(r); });
            await page.route('**/*', r => new URL(r.request().url()).hostname === '127.0.0.1' ? r.continue() : r.abort());
            await page.goto('http://127.0.0.1:8771/?quality&edit'+(light?'&light&en':''));
            await page.locator('#gov-save:not([disabled])').waitFor();
            const card = page.locator('.gqp-card').first(), rows = card.locator('.gqp-conditions tr');
            const dialog = page.locator('.gqp-condition-dialog');
            const snapshot = () => page.locator('#gov-quality-payload').inputValue();
            const field = name => dialog.locator('[data-field=condition_'+name+']');
            const open = async () => { await card.locator('.gqp-add-condition').click(); await dialog.waitFor(); };
            const apply = async () => { await dialog.locator('.gqp-condition-apply').click(); };
            const before = await snapshot();
            await open();
            assert.equal(await field('type').evaluate(e => e===document.activeElement),true,'Focus starts at type');
            await field('name').fill('Departamento');
            await field('value').fill('cancelled');
            assert.equal(await snapshot(),before,'Modal draft never updates page JSON');
            await page.keyboard.press('Escape');
            assert.equal(await dialog.count(),0);
            assert.equal(await card.locator('.gqp-add-condition').evaluate(e => e===document.activeElement),true,'Escape restores opener focus');
            assert.equal(await snapshot(),before);
            await open(); await apply();
            assert.equal(await dialog.count(),1,'Missing tag name rejected');
            await field('name').fill('Departamento');
            await field('value').fill('DBD');
            await page.keyboard.press('Enter');
            assert.equal(await dialog.count(),0,'Enter applies only the condition');
            assert.equal(requests.length,0,'Enter never submits parent form');
            assert.equal(await rows.count(),1);
            assert.equal(await rows.locator('input,select').count(),0,'Readonly table');
            assert.ok((await rows.first().innerText()).includes('Departamento'));
            await open(); await field('type').selectOption('group'); await field('value').fill(', ,'); await apply();
            assert.equal(await dialog.count(),1,'Comma-only group rejected');
            assert.equal(await dialog.locator('[role=alert]').isVisible(),true);
            await field('value').fill('DBD,42'); await field('subgroups').selectOption('0'); await apply();
            assert.equal(await rows.count(),2);
            assert.ok((await rows.nth(1).innerText()).includes(light?'exact groups':'grupos exatos'));
            await card.locator('[data-field=selection_mode]').selectOption('custom');
            await card.locator('[data-field=selection_formula]').fill('A and B');
            let previous = await snapshot();
            await rows.first().locator('.gqp-edit-condition').click();
            await field('value').fill('<svg onload=alert(1)>');
            await apply();
            assert.equal(await card.locator('[data-field=selection_formula]').inputValue(),'A and B','Edit preserves formula');
            assert.equal(await rows.first().locator('svg').count(),0,'User values render only as text');
            assert.ok((await rows.first().innerText()).includes('<svg onload=alert(1)>'));
            await rows.first().locator('.gqp-edit-condition').click(); await field('value').fill(''); await apply();
            assert.ok((await rows.first().innerText()).includes(light?'empty value':'valor vazio'),'Empty exact tag value is explicit');
            previous = await snapshot();
            await open(); await field('name').fill('not applied');
            await dialog.locator('.gqp-modal-close').click();
            assert.equal(await snapshot(),previous,'X leaves condition list and formula intact');
            await rows.nth(1).locator('.gqp-edit-condition').click();
            await field('subgroups').selectOption('1');
            await dialog.locator('.gqp-catalog-open').click();
            const catalog = page.locator('.gqp-catalog-dialog');
            assert.equal(await catalog.locator('input').evaluate(e => e===document.activeElement),true);
            await catalog.locator('.gqp-modal-footer button').focus();
            await page.keyboard.press('Tab');
            assert.equal(await catalog.locator('.gqp-modal-close').evaluate(e => e===document.activeElement),true,'Top modal traps Tab');
            await page.keyboard.press('Escape');
            assert.equal(await catalog.count(),0); assert.equal(await dialog.count(),1,'Escape does not close parent condition');
            assert.equal(await dialog.locator('.gqp-catalog-open').evaluate(e => e===document.activeElement),true);
            assert.equal(requests.length,0,'Opening catalog never fetches');
            await dialog.locator('.gqp-condition-cancel').click();
            assert.equal(await snapshot(),previous,'Nested catalog cancellation keeps original subgroup rule');
            await open(); await field('type').selectOption('inventory'); await field('inventory').selectOption('os_full');
            await field('operator').selectOption('not_exists'); await apply();
            assert.equal(await card.locator('[data-field=selection_formula]').inputValue(),'','Applied insertion clears formula');
            await card.locator('[data-field=selection_formula]').fill('A and B and C');
            await rows.nth(1).locator('.gqp-remove-condition').click();
            assert.equal(await card.locator('[data-field=selection_formula]').inputValue(),'','Removal clears formula');
            assert.equal(await rows.nth(1).locator('td').first().innerText(),'B','Labels remain consecutive');
            await card.locator('[data-field=selection_mode]').selectOption('all');
            await open(); await field('type').selectOption('template'); await field('value').fill('Linux,Windows');
            for (const width of [1440,700,390]) {
                await page.setViewportSize({width,height:900});
                const box=await dialog.boundingBox();
                assert.ok(box.x>=0 && box.x+box.width<=width+1,'Dialog fits viewport');
                assert.equal(await dialog.locator('.gqp-modal-body').evaluate(e=>e.scrollWidth<=e.clientWidth+1),true,'No internal horizontal overflow');
                await page.screenshot({path:path.join(output,(light?'light':'dark')+'-dialog-'+width+'.png')});
            }
            await page.setViewportSize({width:1440,height:1000}); await apply();
            await card.scrollIntoViewIfNeeded();
            await page.screenshot({path:path.join(output,(light?'light':'dark')+'-table.png')});
            // No automatic preview, saving or catalog lookup throughout all these interactions.
            assert.equal(requests.length,0);
            for(let i=await rows.count();i<20;i++) { await open(); await field('name').fill('tag'+i); await field('operator').selectOption('exists'); await apply(); }
            assert.equal(await rows.count(),20); assert.equal(await card.locator('.gqp-add-condition').isDisabled(),true);
            await rows.last().locator('.gqp-remove-condition').click();
            assert.equal(await card.locator('.gqp-add-condition').isEnabled(),true);
            // Closing a nested lookup aborts its request; late results cannot alter a new or applied draft.
            const stable = await snapshot();
            let release, settled;
            const held = new Promise(resolve => { release=resolve; });
            const finished = new Promise(resolve => { settled=resolve; });
            await page.route('**/*action=governance.quality.run*', async r => {
                await held;
                try { await r.fulfill({contentType:'application/json',body:JSON.stringify({status:'complete',items:[{id:'42',name:'Late group'}],has_more:false})}); }
                catch (_) { /* Request was aborted by closing the dialog. */ }
                finally { settled(); }
            });
            await rows.first().locator('.gqp-edit-condition').click();
            await field('type').selectOption('group'); await dialog.locator('.gqp-catalog-open').click();
            await catalog.locator('input').fill('Late');
            const requestSent=page.waitForRequest(r=>r.method()==='POST');
            await catalog.locator('.gqp-catalog-search').click(); await requestSent;
            await page.keyboard.press('Escape'); await dialog.locator('.gqp-condition-cancel').click();
            release(); await finished;
            assert.equal(await page.locator('.gqp-modal').count(),0);
            assert.equal(await snapshot(),stable,'Late catalog data cannot change applied conditions');
            assert.equal(requests.length,1,'Only the explicit lookup queried the server');
            assert.deepEqual(errors,[]);
            await page.close();
        }
        console.log('PASS: condition dialogs, draft isolation, validation, exact/subgroup rules, formula labels, keyboard/nested focus, 20-limit and 390px layout. Screenshots: '+output);
    } finally { await browser.close(); }
})().catch(e=>{console.error(e);process.exitCode=1;});
