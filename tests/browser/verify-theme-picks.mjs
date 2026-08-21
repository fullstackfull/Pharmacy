/**
 * The theme sections must show what the merchant PICKED, and sell it.
 *
 * Checks the storefront renders the hand-picked product slider, the chosen category's showcase
 * (banner + sub-category chips + its products), and that the card's add-to-cart button really adds
 * a line to the cart through the storefront's own cart flow.
 */
import { chromium } from 'playwright';
import { BASE, watch, hasServerError, SHOTS } from './_env.mjs';

const problems = [];
const note = (message) => { problems.push(message); console.log('  ✗ ' + message); };
const ok = (message) => console.log('  ✓ ' + message);

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
const page = await context.newPage();
const watched = watch(page);

await page.goto(BASE + '/', { waitUntil: 'networkidle' });
if (await hasServerError(page)) note('storefront returned a server error');
await page.evaluate(() => document.querySelectorAll('.phpdebugbar,.alert--container,#popup-modal,.modal.show,.modal-backdrop').forEach(n => n.remove()));

// ---- the picked sections render ---------------------------------------------------------------
const showcase = await page.$('.tbs-category_showcase');
showcase ? ok('the category showcase renders') : note('no category showcase section on the page');

if (showcase) {
    const chips = await page.$$eval('.tbs-category_showcase .ml-chips a', nodes => nodes.map(n => n.textContent.trim()));
    const products = await page.$$('.tbs-category_showcase .ml-card');
    chips.length ? ok(`sub-category chips render (${chips.length})`) : console.log('  · the chosen category has no sub-categories');
    products.length ? ok(`the category's products render (${products.length})`) : note('the showcase shows no products');
}

const sliderCounts = await page.$$eval('.tbs-product_slider', nodes => nodes.map(n => ({
    title: n.querySelector('h2')?.textContent.trim() || '',
    cards: n.querySelectorAll('.ml-card').length,
})));
console.log('  · product sliders: ' + JSON.stringify(sliderCounts));
const manual = sliderCounts.find(s => s.title === 'Hand picked');
manual && manual.cards === 3
    ? ok('the hand-picked slider shows exactly the 3 picked products')
    : note('the hand-picked slider does not show exactly the picked products: ' + JSON.stringify(manual));

// ---- add to cart --------------------------------------------------------------------------------
const cartButton = await page.$('.ml-cart-btn.product-add-to-cart-button');
if (!cartButton) {
    note('no add-to-cart button on any themed card');
} else {
    const cartText = () => page.$eval('#cart_items', n => n.textContent.replace(/\s+/g, ' ').trim()).catch(() => null);
    const before = await cartText();
    await cartButton.scrollIntoViewIfNeeded();
    // Staging pops a stock-alert modal whose backdrop swallows clicks.
    await page.evaluate(() => document.querySelectorAll('.modal.show,.modal-backdrop,.alert--container').forEach(n => n.remove()));
    await cartButton.click();
    await page.waitForTimeout(2500);
    const after = await cartText();
    before !== after
        ? ok('add to cart works — the nav cart changed after the click')
        : note('the nav cart did not change after clicking add to cart');
    await page.screenshot({ path: SHOTS + '/theme-picks-cart.png' });
}

console.log(watched.length ? '\nPage problems:\n' + watched.join('\n') : '\nNo page errors.');
await browser.close();

console.log(problems.length ? `\nFAILED (${problems.length})` : '\nPASSED');
process.exit(problems.length ? 1 : 0);
