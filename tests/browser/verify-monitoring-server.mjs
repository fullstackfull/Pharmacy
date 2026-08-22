import {BASE, SHOTS, loginAdmin, watch, hasServerError, setDirection} from './_env.mjs';
import {chromium} from 'playwright';

const browser = await chromium.launch({executablePath: '/opt/pw-browsers/chromium'});
const page = await browser.newPage({viewport: {width: 1600, height: 1100}});
await page.emulateMedia({reducedMotion: 'reduce'});
const problems = watch(page);
await loginAdmin(page);

const res = await page.goto(BASE + '/admin/monitoring/server', {waitUntil: 'domcontentloaded'});
console.log('GET /admin/monitoring/server ->', res.status());
if (res.status() >= 400) problems.push('server returned ' + res.status());
if (await hasServerError(page)) problems.push('server rendered a server error');

await page.waitForTimeout(1200);
await page.evaluate(() => document.querySelectorAll('.phpdebugbar,.modal,.modal-backdrop,.alert--container').forEach((n) => n.remove()));

const body = await page.evaluate(() => document.body.innerText);
for (const needle of ['Warning:', 'Notice:', 'Deprecated:', 'Undefined ', 'htmlspecialchars(', 'Array to string', 'Attempt to read']) {
    if (body.includes(needle)) problems.push('PHP diagnostic on page: ' + needle);
}

console.log('cards:', await page.locator('.k-card, .mon-card').count(),
    '| tables:', await page.locator('table.k-table').count(),
    '| metric tiles:', await page.locator('.mon-metric').count(),
    '| states:', await page.locator('.mon-metric__state').count(),
    '| core bars:', await page.locator('.mon-cores__row').count(),
    '| charts:', await page.locator('.mon-chart').count());

await page.screenshot({path: SHOTS + '/monitoring-server.png', fullPage: true});

// The same payload is served as JSON to the page's own refresh, so it is checked too.
const json = await page.evaluate(async (base) => {
    const r = await fetch(base + '/admin/monitoring/server?json=1', {headers: {'X-Requested-With': 'XMLHttpRequest'}, credentials: 'same-origin'});
    return {status: r.status, body: await r.json()};
}, BASE);
console.log('JSON ->', json.status, '| panel state:', json.body?.data?.state);
if (json.status !== 200) problems.push('json payload returned ' + json.status);
if (json.body?.data?.state !== 'ok') problems.push('panel state is ' + json.body?.data?.state);

await setDirection(page, 'rtl');
await page.goto(BASE + '/admin/monitoring/server', {waitUntil: 'domcontentloaded'});
await page.waitForTimeout(900);
await page.evaluate(() => document.querySelectorAll('.phpdebugbar,.modal,.modal-backdrop,.alert--container').forEach((n) => n.remove()));
const rtlOverflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 4);
console.log('RTL horizontal overflow:', rtlOverflow);
if (rtlOverflow) problems.push('the page scrolls horizontally in RTL');
await page.screenshot({path: SHOTS + '/monitoring-server-rtl.png', fullPage: true});
await setDirection(page, 'ltr');

console.log(problems.length ? '\nPROBLEMS:\n' + problems.join('\n') : '\nall good');
await browser.close();
process.exit(problems.length ? 1 : 0);
