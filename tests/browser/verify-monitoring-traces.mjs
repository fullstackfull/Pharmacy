import {BASE, SHOTS, loginAdmin, watch, hasServerError, setDirection} from './_env.mjs';
import {chromium} from 'playwright';

const TRACE = process.env.TRACE_ID || '';
const browser = await chromium.launch({executablePath: '/opt/pw-browsers/chromium'});
const page = await browser.newPage({viewport: {width: 1600, height: 1100}});
await page.emulateMedia({reducedMotion: 'reduce'});
const problems = watch(page);
await loginAdmin(page);

async function visit(label, path, shot) {
    const res = await page.goto(BASE + path, {waitUntil: 'domcontentloaded'});
    console.log(label, '->', res.status(), path);
    if (res.status() >= 400) problems.push(label + ' returned ' + res.status());
    if (await hasServerError(page)) problems.push(label + ' rendered a server error');
    await page.waitForTimeout(900);
    await page.evaluate(() => document.querySelectorAll('.phpdebugbar,.modal,.modal-backdrop,.alert--container').forEach(n => n.remove()));
    // A PHP warning/notice printed above the layout would land in the body text.
    const noisy = await page.evaluate(() => {
        const t = document.body.innerText || '';
        const m = t.match(/(Warning|Notice|Deprecated|Fatal error|Undefined (array key|variable|index)|Trying to access array offset)[^\n]{0,140}/);
        return m ? m[0] : null;
    });
    if (noisy) problems.push(label + ' PHP diagnostic in page: ' + noisy);
    if (shot) await page.screenshot({path: SHOTS + '/' + shot, fullPage: true});
    return page;
}

await visit('traces default', '/admin/monitoring/traces', 'traces-default.png');
const stats = await page.locator('.k-stats .k-stat, .k-stats > *').allInnerTexts();
console.log('stat tiles:', JSON.stringify(stats.map(s => s.replace(/\s+/g, ' ').trim())));
const rows = await page.locator('.k-table tbody tr').count();
console.log('trace rows:', rows);

await visit('traces 24h', '/admin/monitoring/traces?range=24h', 'traces-24h.png');
await visit('traces 30d (beyond retention)', '/admin/monitoring/traces?range=30d', 'traces-30d.png');
await visit('traces filtered (captured=error)', '/admin/monitoring/traces?range=24h&captured=error', 'traces-filtered.png');
await visit('traces min_ms filter', '/admin/monitoring/traces?range=24h&min_ms=100000', 'traces-minms.png');
await visit('traces unknown id', '/admin/monitoring/traces?range=24h&trace=deadbeefdeadbeefdeadbeefdeadbeef', 'traces-unknown.png');
await visit('traces junk id', '/admin/monitoring/traces?range=24h&trace=%3Cscript%3E&route=%27%22--&min_ms=-5', 'traces-junk.png');

if (TRACE) {
    await visit('traces selected', '/admin/monitoring/traces?range=24h&trace=' + TRACE, 'traces-selected.png');
    const spans = await page.locator('.mon-waterfall__row').count();
    const legend = await page.locator('.mon-waterfall-split__key').allInnerTexts();
    const bars = await page.evaluate(() => [...document.querySelectorAll('.mon-waterfall__bar')]
        .slice(0, 8).map(b => b.getAttribute('style')));
    const splitParts = await page.evaluate(() => [...document.querySelectorAll('.mon-waterfall-split__part')]
        .map(b => b.getAttribute('title') + ' | ' + b.getAttribute('style')));
    console.log('span rows:', spans);
    console.log('split parts:', JSON.stringify(splitParts, null, 1));
    console.log('legend:', JSON.stringify(legend.map(s => s.replace(/\s+/g, ' ').trim()), null, 1));
    console.log('first bars:', JSON.stringify(bars, null, 1));
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 4);
    console.log('LTR horizontal overflow:', overflow);
    if (overflow) problems.push('the selected-trace page scrolls horizontally in LTR');
}

await setDirection(page, 'rtl');
await visit('traces RTL', '/admin/monitoring/traces?range=24h' + (TRACE ? '&trace=' + TRACE : ''), 'traces-rtl.png');
const rtlOverflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 4);
console.log('RTL horizontal overflow:', rtlOverflow);
if (rtlOverflow) problems.push('the page scrolls horizontally in RTL');
await setDirection(page, 'ltr');

console.log(problems.length ? '\nPROBLEMS:\n' + problems.join('\n') : '\nall good');
await browser.close();
process.exit(problems.length ? 1 : 0);
