import {BASE, SHOTS, loginAdmin, watch, hasServerError, setDirection} from './_env.mjs';
import {chromium} from 'playwright';

const browser = await chromium.launch({executablePath: '/opt/pw-browsers/chromium'});
const page = await browser.newPage({viewport: {width: 1600, height: 1100}});
await page.emulateMedia({reducedMotion: 'reduce'});
const problems = watch(page);
await loginAdmin(page);

const res = await page.goto(BASE + '/admin/monitoring', {waitUntil: 'domcontentloaded'});
console.log('GET /admin/monitoring ->', res.status());
if (res.status() >= 400) problems.push('monitoring returned ' + res.status());
if (await hasServerError(page)) problems.push('monitoring rendered a server error');

await page.waitForTimeout(1200);
await page.evaluate(() => document.querySelectorAll('.phpdebugbar,.modal,.modal-backdrop,.alert--container').forEach((n) => n.remove()));

const status = await page.locator('#mon-status').getAttribute('data-state');
const score = await page.locator('[data-mon="score"]').innerText();
const cards = await page.locator('.mon-card').count();
const railLinks = await page.locator('.mon-rail__link').count();
console.log('status:', status, '| score:', score.trim(), '| service cards:', cards, '| rail sections:', railLinks);
if (cards < 10) problems.push('too few service cards: ' + cards);
if (railLinks < 25) problems.push('rail is missing sections: ' + railLinks);

await page.screenshot({path: SHOTS + '/monitoring-overview.png', fullPage: true});

// The merchant runs the panel in Arabic, so the layout has to hold in RTL as well as LTR.
await setDirection(page, 'rtl');
await page.goto(BASE + '/admin/monitoring', {waitUntil: 'domcontentloaded'});
await page.waitForTimeout(900);
await page.evaluate(() => document.querySelectorAll('.phpdebugbar,.modal,.modal-backdrop,.alert--container').forEach((n) => n.remove()));
const rtlOverflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 4);
console.log('RTL horizontal overflow:', rtlOverflow);
if (rtlOverflow) problems.push('the page scrolls horizontally in RTL');
await page.screenshot({path: SHOTS + '/monitoring-overview-rtl.png', fullPage: true});
await setDirection(page, 'ltr');

console.log(problems.length ? '\nPROBLEMS:\n' + problems.join('\n') : '\nall good');
await browser.close();
process.exit(problems.length ? 1 : 0);
