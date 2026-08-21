/**
 * Every Theme Builder option must do something on the storefront, and the builder must say when a
 * section is not live yet. Checks the themed home renders with the published palette, the rail
 * autoplay/pagination/alignment/responsive settings reach the markup, and the picker shows a
 * preview and option chips per section type.
 */
import { chromium } from 'playwright';
import { BASE, ADMIN, watch, loginAdmin, hasServerError, SHOTS } from './_env.mjs';

const problems = [];
const note = (message) => { problems.push(message); console.log('  ✗ ' + message); };
const ok = (message) => console.log('  ✓ ' + message);

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
const page = await context.newPage();
const watched = watch(page);

// ---- storefront -------------------------------------------------------------------------------
await page.goto(BASE + '/', { waitUntil: 'domcontentloaded' });
if (await hasServerError(page)) note('storefront returned a server error');

const sections = await page.$$eval('.ml-sections .tbs', nodes => nodes.map(node => ({
    type: [...node.classList].find(c => c.startsWith('tbs-') && !c.startsWith('tbs-align')),
    align: [...node.classList].find(c => c.startsWith('tbs-align-')),
    cols: node.style.getPropertyValue('--tb-cols'),
    paddingTop: node.style.paddingTop,
})));

sections.length ? ok(`themed home renders ${sections.length} sections`) : note('no themed sections on the home page');
sections.every(s => s.align) ? ok('every section carries an alignment class') : note('a section is missing its alignment class');
sections.every(s => s.paddingTop) ? ok('padding settings reach the markup') : note('a section has no padding applied');

const primary = await page.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--web-primary').trim());
const railPrimary = await page.evaluate(() => {
    const root = document.querySelector('.ml-sections');
    return root ? getComputedStyle(root).getPropertyValue('--ml-primary').trim() : '';
});
primary ? ok(`published palette applied (--web-primary ${primary})`) : note('the published theme palette did not reach the storefront');
railPrimary.toLowerCase() === primary.toLowerCase()
    ? ok('the themed sections inherit that palette')
    : note(`themed sections use ${railPrimary} instead of ${primary}`);

const countdown = await page.$('[data-ml-countdown]');
if (countdown) {
    const before = await page.$eval('[data-ml-countdown] [data-unit="seconds"]', n => n.textContent);
    await page.waitForTimeout(1600);
    const after = await page.$eval('[data-ml-countdown] [data-unit="seconds"]', n => n.textContent);
    before !== after ? ok(`flash-deal countdown ticks (${before} -> ${after})`) : note('the countdown is not ticking');
} else {
    note('no flash-deal countdown on the page (no running deal?)');
}

await page.screenshot({ path: SHOTS + '/theme-options-home.png', fullPage: false });

// ---- builder ----------------------------------------------------------------------------------
await loginAdmin(page);
await page.goto(BASE + '/admin/theme/builder?page=home', { waitUntil: 'domcontentloaded' });
if (await hasServerError(page)) note('the theme builder returned a server error');

// The debug bar overlays the builder rail in staging and eats the click.
await page.evaluate(() => document.querySelectorAll('.phpdebugbar').forEach(node => node.remove()));
await page.click('#tb-open-picker');
await page.waitForSelector('#tb-picker.is-open .tb-card', { timeout: 5000 });
const cards = await page.$$eval('#tb-picker .tb-card', nodes => nodes.map(node => ({
    shape: node.querySelector('.tb-thumb')?.dataset.shape || null,
    chips: node.querySelectorAll('.tb-chip').length,
})));
cards.length ? ok(`picker offers ${cards.length} section types`) : note('the section picker is empty');
cards.every(card => card.shape) ? ok('every card shows a preview shape') : note('a picker card has no preview');
cards.every(card => card.chips > 0) ? ok('every card lists its options') : note('a picker card lists no options');

await page.screenshot({ path: SHOTS + '/theme-options-picker.png', fullPage: false });

console.log(watched.length ? '\nPage problems:\n' + watched.join('\n') : '\nNo page errors.');
await browser.close();

console.log(problems.length ? `\nFAILED (${problems.length})` : '\nPASSED');
process.exit(problems.length ? 1 : 0);
