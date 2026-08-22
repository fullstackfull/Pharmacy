import {BASE, SHOTS, loginAdmin, watch, hasServerError, setDirection} from './_env.mjs';
import {chromium} from 'playwright';

const URL = BASE + '/admin/monitoring/storage';
const browser = await chromium.launch({executablePath: '/opt/pw-browsers/chromium'});
const page = await browser.newPage({viewport: {width: 1600, height: 1100}});
await page.emulateMedia({reducedMotion: 'reduce'});
const problems = watch(page);
await loginAdmin(page);

const res = await page.goto(URL, {waitUntil: 'domcontentloaded'});
console.log('GET /admin/monitoring/storage ->', res.status());
if (res.status() >= 400) problems.push('storage returned ' + res.status());
if (await hasServerError(page)) problems.push('storage rendered a server error');

await page.waitForTimeout(1200);
await page.evaluate(() => document.querySelectorAll('.phpdebugbar,.modal,.modal-backdrop,.alert--container').forEach((n) => n.remove()));

// A PHP notice/warning is printed into the markup, not thrown: look for it.
const body = await page.evaluate(() => document.body.innerText);
for (const needle of ['Undefined array key', 'Undefined variable', 'Undefined index', 'Warning:', 'Deprecated:', 'Notice:', 'Attempt to read property', 'htmlspecialchars(', 'Array to string conversion']) {
    if (body.includes(needle)) problems.push('PHP diagnostic in page: ' + needle);
}

const counts = await page.evaluate(() => ({
    usage: document.querySelectorAll('.mon-usage').length,
    charts: document.querySelectorAll('.mon-chart').length,
    drawn: document.querySelectorAll('.mon-chart svg').length,
    metrics: document.querySelectorAll('.mon-metric').length,
    empties: document.querySelectorAll('.k-empty').length,
    dashes: (document.body.innerText.match(/—/g) || []).length,
}));
console.log('usage bars:', counts.usage, '| chart slots:', counts.charts, '| charts drawn:', counts.drawn,
    '| metric rows:', counts.metrics, '| empty states:', counts.empties);

await page.screenshot({path: SHOTS + '/monitoring-storage.png', fullPage: true});

// The merchant runs the panel in Arabic, so the layout has to hold in RTL as well as LTR.
await setDirection(page, 'rtl');
await page.goto(URL, {waitUntil: 'domcontentloaded'});
await page.waitForTimeout(900);
await page.evaluate(() => document.querySelectorAll('.phpdebugbar,.modal,.modal-backdrop,.alert--container').forEach((n) => n.remove()));
const rtlOverflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 4);
console.log('RTL horizontal overflow:', rtlOverflow);
if (rtlOverflow) problems.push('the page scrolls horizontally in RTL');
await page.screenshot({path: SHOTS + '/monitoring-storage-rtl.png', fullPage: true});
await setDirection(page, 'ltr');

console.log(problems.length ? '\nPROBLEMS:\n' + problems.join('\n') : '\nall good');
await browser.close();
process.exit(problems.length ? 1 : 0);
