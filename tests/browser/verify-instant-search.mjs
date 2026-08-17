/**
 * Header search typeahead:
 *   - typing a word fires ONE debounced request, not one per keystroke
 *   - the full-page #loading overlay stays hidden while typing
 *   - suggestions render; ArrowDown highlights and fills the input
 *   - Enter submits the highlighted suggestion to /products
 *   - Escape restores the typed text and closes the card
 *   - RTL: the card opens and suggestions render
 */
import { chromium } from '@playwright/test';
import { BASE, watch, setDirection, dismissPromoPopup } from './_env.mjs';

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const failures = [];
const ok = (label, cond) => {
    console.log((cond ? 'PASS ' : 'FAIL ') + label);
    if (!cond) failures.push(label);
};

// ---- Desktop LTR --------------------------------------------------------
{
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    const problems = watch(page);
    let searchRequests = 0;
    page.on('request', r => { if (r.url().includes('searched-products')) searchRequests++; });

    await page.goto(BASE + '/', { waitUntil: 'domcontentloaded' });
    await dismissPromoPopup(page);

    let loaderShown = false;
    const loaderWatch = setInterval(async () => {
        const visible = await page.evaluate(() => {
            const el = document.getElementById('loading');
            return el && getComputedStyle(el).display !== 'none';
        }).catch(() => false);
        if (visible) loaderShown = true;
    }, 120);

    await page.click('.search-bar-input');
    await page.type('.search-bar-input', 'rosemary', { delay: 90 });
    await page.waitForTimeout(1600);
    clearInterval(loaderWatch);

    ok('one debounced request for 8 keystrokes (≤2)', searchRequests >= 1 && searchRequests <= 2);
    ok('full-page loader never flashed', !loaderShown);

    const rows = await page.locator('.search-result-product').count();
    ok('suggestions rendered', rows >= 1);

    await page.keyboard.press('ArrowDown');
    await page.waitForTimeout(150);
    const highlighted = await page.locator('.search-result-product.is-active').count();
    const inputValue = await page.inputValue('.search-bar-input');
    const firstName = await page.locator('.search-result-product').first().getAttribute('data-product-name');
    ok('arrow-down highlights first row', highlighted === 1);
    ok('input follows the highlight', inputValue === firstName);

    await page.keyboard.press('Escape');
    await page.waitForTimeout(150);
    ok('escape restores the typed text', (await page.inputValue('.search-bar-input')) === 'rosemary');
    ok('escape hides the card', await page.evaluate(() =>
        getComputedStyle(document.querySelector('.search-card')).display === 'none'));

    // Highlight again, submit, land on the products page for that name.
    await page.type('.search-bar-input', ' ', { delay: 50 });
    await page.waitForTimeout(900);
    await page.keyboard.press('ArrowDown');
    await page.keyboard.press('Enter');
    await page.waitForLoadState('domcontentloaded');
    await page.waitForTimeout(1500);
    ok('enter submits to /products', page.url().includes('/products') && page.url().includes('name='));
    if (!page.url().includes('/products')) console.log('  landed on:', page.url());

    ok('no console/JS/HTTP problems', problems.length === 0);
    if (problems.length) console.log('  problems:', problems.slice(0, 5));
    await page.close();
}

// ---- RTL ----------------------------------------------------------------
{
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    await setDirection(page, 'rtl');
    await page.goto(BASE + '/', { waitUntil: 'domcontentloaded' });
    await dismissPromoPopup(page);
    ok('rtl: direction applied', await page.getAttribute('html', 'dir') === 'rtl');

    await page.click('.search-bar-input');
    // Playwright inserts non-ASCII text without key events, so the handler's
    // keyup never fires from type() alone; a trailing space + backspace are
    // real keystrokes, as an Arabic keyboard would produce.
    await page.type('.search-bar-input', 'شامبو', { delay: 80 });
    await page.keyboard.press('Space');
    await page.keyboard.press('Backspace');
    await page.waitForTimeout(1400);
    ok('rtl: card open', await page.evaluate(() =>
        getComputedStyle(document.querySelector('.search-card')).display !== 'none'));
    ok('rtl: suggestions rendered', await page.locator('.search-result-product').count() >= 1);
    await setDirection(page, 'ltr');
    await page.close();
}

await browser.close();
console.log(failures.length ? `FAILURES: ${failures.length}` : 'ALL CLEAN');
process.exit(failures.length ? 1 : 0);
