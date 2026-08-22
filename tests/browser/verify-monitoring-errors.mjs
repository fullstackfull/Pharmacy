import {BASE, SHOTS, loginAdmin, watch, hasServerError} from './_env.mjs';
import {chromium} from 'playwright';

const browser = await chromium.launch({executablePath: '/opt/pw-browsers/chromium'});
const page = await browser.newPage({viewport: {width: 1600, height: 1100}});
await page.emulateMedia({reducedMotion: 'reduce'});
const problems = watch(page);
await loginAdmin(page);

async function open(label, url) {
    const res = await page.goto(BASE + url, {waitUntil: 'domcontentloaded'});
    console.log(label, '->', res.status());
    if (res.status() >= 400) problems.push(label + ' returned ' + res.status());
    if (await hasServerError(page)) problems.push(label + ' rendered a server error');
    await page.waitForTimeout(500);
    await page.evaluate(() => document.querySelectorAll('.phpdebugbar,.modal,.modal-backdrop,.alert--container').forEach((n) => n.remove()));
    const body = await page.evaluate(() => document.body.innerText);
    for (const word of ['Warning:', 'Deprecated:', 'Undefined ', 'Fatal error', 'ErrorException']) {
        if (body.includes(word)) problems.push(label + ' printed a PHP ' + word.trim());
    }
    return body;
}

await open('GET /admin/monitoring/errors?range=24h', '/admin/monitoring/errors?range=24h');
const rows = await page.locator('.k-table tbody tr').count();
const stats = await page.locator('.k-stat').count();
const pager = await page.locator('.k-pager').count();
console.log('group rows:', rows, '| stat tiles:', stats, '| pagers:', pager);
if (rows !== 25) problems.push('expected 25 rows on page one, got ' + rows);
if (stats < 4) problems.push('missing stat tiles: ' + stats);
await page.screenshot({path: SHOTS + '/monitoring-errors.png', fullPage: true});

await open('page 2', '/admin/monitoring/errors?range=24h&page=2');
console.log('page 2 rows:', await page.locator('.k-table tbody tr').count());

const detail = await open('selected group', '/admin/monitoring/errors?range=24h&status=all&group=1');
const trace = await page.locator('#mon-error-group .mon-pre').innerText();
console.log('detail card:', await page.locator('#mon-error-group').count(), '| trace chars:', trace.length,
    '| occurrence rows:', await page.locator('#mon-error-group .k-table tbody tr').count());
if (!trace.includes('#0 /var/www')) problems.push('stack trace missing from the detail card');
for (const secret of ['sk_live_', 'hunter2', '4111 1111']) {
    if (detail.includes(secret)) problems.push('UNREDACTED SECRET on the page: ' + secret);
}
if (!trace.includes('[redacted]')) problems.push('trace was not redacted');
await page.screenshot({path: SHOTS + '/monitoring-errors-group.png', fullPage: true});

await open('search filter', '/admin/monitoring/errors?range=24h&status=all&q=Lock%20wait');
console.log('search rows:', await page.locator('.k-table tbody tr').count());

await open('wildcard search is literal', '/admin/monitoring/errors?range=24h&status=all&q=%25');
console.log('percent-search rows:', await page.locator('.k-table tbody tr').count(),
    '| empty state:', await page.locator('.k-empty__title').count());

await open('group with no occurrence rows', '/admin/monitoring/errors?range=24h&status=all&group=33');
console.log('no-occurrence note:', (await page.locator('#mon-error-group .mon-note').allInnerTexts()).slice(-1)[0]);

await open('filtered to nothing', '/admin/monitoring/errors?range=24h&channel=nowhere');
console.log('empty title:', await page.locator('.k-empty__title').innerText());

await open('deep page is capped', '/admin/monitoring/errors?range=24h&page=99999');
console.log('capped page rows:', await page.locator('.k-table tbody tr').count());

await open('json payload', '/admin/monitoring/errors?range=24h&json=1');

console.log(problems.length ? '\nPROBLEMS:\n' + problems.join('\n') : '\nall good');
await browser.close();
process.exit(problems.length ? 1 : 0);
