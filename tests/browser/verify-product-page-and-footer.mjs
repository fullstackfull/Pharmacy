/**
 * The product page's above-the-fold block and the storefront footer, in both directions.
 *
 * Checks the brand chip, stock state, trust/deal badges, the saved percentage, the admin-driven
 * viewers line (including that it does NOT jump between two loads of the same product) and the
 * short description; then that the footer draws its four columns, legal identifiers, social
 * circles and payment strip without overflowing its own width.
 *
 * Usage: node tests/browser/verify-product-page-and-footer.mjs <product-slug>
 */
import { chromium } from 'playwright';
import { BASE, watch, hasServerError, setDirection, SHOTS } from './_env.mjs';

const slug = process.argv[2];
if (!slug) {
    console.log('Pass a product slug: node tests/browser/verify-product-page-and-footer.mjs <slug>');
    process.exit(2);
}

const problems = [];
const note = (message) => { problems.push(message); console.log('  ✗ ' + message); };
const ok = (message) => console.log('  ✓ ' + message);

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });

const strip = (page) => page.evaluate(() => document
    .querySelectorAll('.phpdebugbar,.alert--container,#popup-modal,.modal.show,.modal-backdrop')
    .forEach(node => node.remove()));

for (const direction of ['ltr', 'rtl']) {
    console.log(`\n[${direction}]`);
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    const page = await context.newPage();
    const watched = watch(page);
    if (direction === 'rtl') await setDirection(page, 'rtl');

    // ---- product page -------------------------------------------------------------------------
    await page.goto(`${BASE}/product/${slug}`, { waitUntil: 'networkidle' });
    if (await hasServerError(page)) note(`${direction}: the product page returned a server error`);
    await strip(page);

    const head = await page.evaluate(() => ({
        brand: document.querySelector('.pd-brand b')?.textContent.trim() || null,
        stock: document.querySelector('.pd-stock')?.textContent.trim() || null,
        badges: [...document.querySelectorAll('.pd-badge')].map(n => n.textContent.trim()),
        save: document.querySelector('.pd-save')?.textContent.trim() || null,
        viewers: document.querySelector('.pd-viewers b')?.textContent.trim() || null,
        short: document.querySelector('.pd-short')?.textContent.trim() || null,
    }));

    head.stock ? ok(`stock state shown (${head.stock})`) : note(`${direction}: no stock state on the product page`);
    head.short ? ok('short description renders under the price') : note(`${direction}: no short description`);
    head.viewers ? ok(`viewers line renders (${head.viewers})`) : note(`${direction}: no viewers line — is the setting on?`);
    head.brand ? ok(`brand chip renders (${head.brand})`) : console.log('  · this product carries no brand');
    head.save ? ok(`saved percentage renders (${head.save})`) : console.log('  · this product is not discounted');
    head.badges.length ? ok(`trust badges render (${head.badges.length})`) : console.log('  · no trust badge configured');

    // The number must be steady across a reload, or it reads as fake.
    await page.reload({ waitUntil: 'networkidle' });
    const again = await page.$eval('.pd-viewers b', n => n.textContent.trim()).catch(() => null);
    again === head.viewers
        ? ok('the viewers count is steady across a reload')
        : note(`${direction}: the viewers count jumped on reload (${head.viewers} -> ${again})`);

    await page.screenshot({ path: `${SHOTS}/pdp-${direction}.png` });

    // ---- footer -------------------------------------------------------------------------------
    await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    await strip(page);
    const footer = await page.$('.k-foot');
    if (!footer) {
        note(`${direction}: the built-in footer did not render`);
    } else {
        await footer.scrollIntoViewIfNeeded();
        const anatomy = await footer.evaluate(node => ({
            columns: node.querySelectorAll('.k-foot__col').length,
            legal: node.querySelectorAll('.k-foot__legal li').length,
            social: node.querySelectorAll('.k-foot__social a').length,
            pay: node.querySelectorAll('.k-foot__pay li').length,
            copy: (node.querySelector('.k-foot__copy')?.textContent || '').trim().length > 0,
            overflows: node.scrollWidth > node.clientWidth + 1,
        }));

        anatomy.columns >= 3 ? ok(`footer draws its columns (${anatomy.columns})`) : note(`${direction}: the footer is missing columns`);
        anatomy.copy ? ok('the copyright line survives the redesign') : note(`${direction}: no copyright line in the footer`);
        !anatomy.overflows ? ok('the footer does not overflow its width') : note(`${direction}: the footer overflows horizontally`);
        anatomy.legal ? ok(`legal identifiers render (${anatomy.legal})`) : console.log('  · no legal identifiers filled in');
        anatomy.social ? ok(`social links render (${anatomy.social})`) : console.log('  · no social links configured');
        anatomy.pay ? ok(`payment methods render (${anatomy.pay})`) : console.log('  · no payment method enabled');

        await footer.screenshot({ path: `${SHOTS}/footer-${direction}.png` });
    }

    if (watched.length) console.log('  page problems: ' + watched.slice(0, 4).join(' | '));
    await context.close();
}

await browser.close();

console.log(problems.length ? `\nFAILED (${problems.length})` : '\nPASSED');
process.exit(problems.length ? 1 : 0);
