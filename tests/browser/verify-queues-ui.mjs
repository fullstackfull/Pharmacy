import {BASE, SHOTS, loginAdmin, watch, hasServerError, setDirection} from './_env.mjs';
import {chromium} from 'playwright';

const browser = await chromium.launch({executablePath: '/opt/pw-browsers/chromium'});
const page = await browser.newPage({viewport: {width: 1600, height: 1100}});
await page.emulateMedia({reducedMotion: 'reduce'});
const problems = watch(page);
await loginAdmin(page);

const res = await page.goto(BASE + '/admin/monitoring/queues', {waitUntil: 'domcontentloaded'});
console.log('GET /admin/monitoring/queues ->', res.status());
if (res.status() >= 400) problems.push('queues returned ' + res.status());
if (await hasServerError(page)) problems.push('queues rendered a server error');

await page.waitForTimeout(1200);
await page.evaluate(() => document.querySelectorAll('.phpdebugbar,.modal,.modal-backdrop,.alert--container').forEach((n) => n.remove()));

const body = await page.evaluate(() => document.body.innerText);
for (const needle of ['Warning:', 'Notice:', 'Deprecated:', 'Undefined ', 'htmlspecialchars(', 'Array to string']) {
    if (body.includes(needle)) problems.push('PHP diagnostic on page: ' + needle);
}
console.log('cards:', await page.locator('.k-card, .mon-card').count(),
    '| tables:', await page.locator('table.k-table').count(),
    '| metric tiles:', await page.locator('.mon-metric').count(),
    '| states:', await page.locator('.mon-metric__state').count());

await page.screenshot({path: SHOTS + '/monitoring-queues.png', fullPage: true});

await setDirection(page, 'rtl');
await page.goto(BASE + '/admin/monitoring/queues', {waitUntil: 'domcontentloaded'});
await page.waitForTimeout(900);
await page.evaluate(() => document.querySelectorAll('.phpdebugbar,.modal,.modal-backdrop,.alert--container').forEach((n) => n.remove()));
const rtlOverflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 4);
console.log('RTL horizontal overflow:', rtlOverflow);
if (rtlOverflow) problems.push('the page scrolls horizontally in RTL');
await page.screenshot({path: SHOTS + '/monitoring-queues-rtl.png', fullPage: true});
await setDirection(page, 'ltr');

console.log(problems.length ? '\nPROBLEMS:\n' + problems.join('\n') : '\nall good');
await browser.close();
process.exit(problems.length ? 1 : 0);
