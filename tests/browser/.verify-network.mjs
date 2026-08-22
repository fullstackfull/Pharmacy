import {BASE, SHOTS, loginAdmin, watch, hasServerError, setDirection} from './_env.mjs';
import {chromium} from 'playwright';

const URL = BASE + '/admin/monitoring/network';
const browser = await chromium.launch({executablePath: '/opt/pw-browsers/chromium'});
const page = await browser.newPage({viewport: {width: 1600, height: 1100}});
await page.emulateMedia({reducedMotion: 'reduce'});
const problems = watch(page);
await loginAdmin(page);

const res = await page.goto(URL, {waitUntil: 'domcontentloaded'});
console.log('GET /admin/monitoring/network ->', res.status());
if (res.status() >= 400) problems.push('network returned ' + res.status());
if (await hasServerError(page)) problems.push('network rendered a server error');

await page.waitForTimeout(1400);
await page.evaluate(() => document.querySelectorAll('.phpdebugbar,.modal,.modal-backdrop,.alert--container').forEach((n) => n.remove()));

const active = await page.locator('.mon-rail__link.is-active').innerText();
const cards = await page.locator('.k-card').count();
const charts = await page.locator('.mon-chart svg').count();
const chartsTotal = await page.locator('.mon-chart').count();
const metrics = await page.locator('.mon-metric').count();
const empties = await page.locator('.k-empty').count();
const pills = await page.locator('.mon-pill').allInnerTexts();
console.log('active rail link:', active.trim(), '| k-cards:', cards, '| metrics:', metrics,
    '| charts drawn:', charts + '/' + chartsTotal, '| empty states:', empties, '| link pills:', JSON.stringify(pills));
if (cards < 5) problems.push('too few cards: ' + cards);
if (metrics < 10) problems.push('too few metric rows: ' + metrics);

const headings = await page.locator('.mon-heading').allInnerTexts();
console.log('headings:', JSON.stringify(headings));
const cardTitles = await page.locator('.k-card__title').allInnerTexts();
console.log('card titles:', JSON.stringify(cardTitles));

const warn = await page.evaluate(() => {
    const body = document.body.innerText;
    return ['Warning:', 'Notice:', 'Deprecated:', 'Undefined ', 'htmlspecialchars('].filter((n) => body.includes(n));
});
if (warn.length) problems.push('PHP diagnostic in page body: ' + warn.join(', '));
console.log('php diagnostics in body:', JSON.stringify(warn));

const json = await page.evaluate(async (u) => {
    const r = await fetch(u + '?json=1', {headers: {Accept: 'application/json'}});
    const d = await r.json();
    return {status: r.status, state: d.data.state, keys: Object.keys(d.data)};
}, URL);
console.log('json payload:', JSON.stringify(json));
if (json.state !== 'ok') problems.push('panel state is ' + json.state);

await page.screenshot({path: SHOTS + '/monitoring-network.png', fullPage: true});

await setDirection(page, 'rtl');
await page.goto(URL, {waitUntil: 'domcontentloaded'});
await page.waitForTimeout(1000);
await page.evaluate(() => document.querySelectorAll('.phpdebugbar,.modal,.modal-backdrop,.alert--container').forEach((n) => n.remove()));
const rtlOverflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 4);
console.log('RTL horizontal overflow:', rtlOverflow);
if (rtlOverflow) problems.push('the page scrolls horizontally in RTL');
await page.screenshot({path: SHOTS + '/monitoring-network-rtl.png', fullPage: true});
await setDirection(page, 'ltr');

console.log(problems.length ? '\nPROBLEMS:\n' + problems.join('\n') : '\nall good');
await browser.close();
process.exit(problems.length ? 1 : 0);
