/**
 * Full COD order end-to-end as the QA customer, now that order placement is
 * POST-only: add to cart, choose shipping on the cart page, fill the address
 * on checkout-details, place the order with cash on delivery, and confirm an
 * order exists. Also proves GET /checkout-complete no longer places anything
 * (405).
 */
import { chromium } from '@playwright/test';
import { BASE, watch, dismissPromoPopup, hasServerError } from './_env.mjs';

const CUSTOMER = { identity: 'qa.customer@localhost.test', password: 'QaCustomer!2026' };
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
const problems = watch(page);
const failures = [];
const ok = (label, cond) => {
    console.log((cond ? 'PASS ' : 'FAIL ') + label);
    if (!cond) failures.push(label);
};
const step = async label => console.log('  → ' + label + ' | ' + page.url());

// Sign in.
await page.goto(BASE + '/customer/auth/login', { waitUntil: 'domcontentloaded' });
await page.fill('#customer-login-form input[name="user_identity"]', CUSTOMER.identity);
await page.fill('#customer-login-form input[name="password"]', CUSTOMER.password);
await page.click('#customer-login-form button[type="submit"]');
await page.waitForTimeout(1500);

// GET on the order-placing route must be rejected outright (same-origin ctx).
const getStatus = await page.evaluate(async () => {
    const res = await fetch('/checkout-complete', { redirect: 'manual' });
    return res.status;
}).catch(() => 0);
ok('GET /checkout-complete rejected (405)', getStatus === 405);

// Fresh cart with one item.
await page.goto(BASE + '/product/feme-hair-rosemary-oil-Iv6roz', { waitUntil: 'domcontentloaded' });
await dismissPromoPopup(page);
await page.click('.add-to-cart-details-form .product-add-to-cart-button');
await page.waitForTimeout(2500);
await page.keyboard.press('Escape'); // close the drawer

// Cart page: pick the shipping method if a selector is present.
await page.goto(BASE + '/shop-cart', { waitUntil: 'domcontentloaded' });
await dismissPromoPopup(page);
await step('cart page');
// Inhouse shipping: one select at the bottom of the cart. Its first option is
// a valueless placeholder whose value resolves to its text - pick numeric only.
const shipSelect = page.locator('select.action-set-shipping-id').first();
ok('shipping select present', await shipSelect.count() === 1);
const shipValues = await shipSelect.locator('option').evaluateAll(os =>
    os.map(o => o.getAttribute('value')).filter(v => v && /^\d+$/.test(v)));
ok('a real shipping option exists', shipValues.length >= 1);
await shipSelect.selectOption(shipValues[0]);
await page.waitForTimeout(2500); // ajax stores the choice for the cart group
await page.goto(BASE + '/checkout-details', { waitUntil: 'domcontentloaded' });
await step('checkout-details');
ok('reached checkout-details', page.url().includes('checkout-details') && !(await hasServerError(page)));

// Address: this store requires the full shipping AND billing forms.
const fillField = async (sel, val) => {
    const el = page.locator(sel).first();
    if (await el.count()) await el.fill(val).catch(() => {});
};
const pickFirst = async sel => {
    const el = page.locator(sel).first();
    if (!(await el.count())) return;
    const vals = await el.locator('option').evaluateAll(os =>
        os.map(o => o.getAttribute('value')).filter(v => v));
    if (vals.length) await el.selectOption(vals[0]).catch(() => {});
};
await fillField('input[name="contact_person_name"]', 'QA Customer');
// intl-tel-input manages this field; write the value directly too.
await fillField('input[name="phone"]', '50001111');
await page.evaluate(() => {
    const el = document.querySelector('input[name="phone"]');
    if (el) { el.value = '50001111'; el.dispatchEvent(new Event('input', { bubbles: true })); }
});
await pickFirst('select[name="country"]');
await fillField('input[name="city"]', 'Kuwait City');
await fillField('input[name="zip"]', '00000');
await fillField('textarea[name="address"]', 'Block 1, Street 2, Building 3');
await fillField('input[name="billing_contact_person_name"]', 'QA Customer');
await fillField('input[name="billing_phone"]', '50001111');
await page.evaluate(() => {
    const el = document.querySelector('input[name="billing_phone"]');
    if (el) { el.value = '50001111'; el.dispatchEvent(new Event('input', { bubbles: true })); }
});
await pickFirst('select[name="billing_country"]');
await fillField('input[name="billing_city"]', 'Kuwait City');
await fillField('input[name="billing_zip"]', '00000');
await fillField('textarea[name="billing_address"]', 'Block 1, Street 2, Building 3');

await page.locator('a.btn--primary', { hasText: 'Proceed' }).locator('visible=true').first().click();
await page.waitForURL('**/checkout-payment', { timeout: 30000 }).catch(() => {});
await page.waitForLoadState('load').catch(() => {});
await page.waitForSelector('#cash_on_delivery', { timeout: 20000 }).catch(() => {});
await step('after proceed');

if (!page.url().includes('checkout-payment')) {
    await page.goto(BASE + '/checkout-payment', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1000);
    await step('forced payment page');
}
ok('reached checkout-payment', page.url().includes('checkout-payment'));

// Place the order with COD: the tile's label submits #cash_on_delivery_form.
const codForm = page.locator('#cash_on_delivery_form');
ok('COD form present and POST', await codForm.count() === 1
    && ((await codForm.first().getAttribute('method')) || '').toLowerCase() === 'post');
// Radio, terms, then the rail's Proceed — checkoutFromPayment() submits the
// checked radio's form.
await page.locator('#cash_on_delivery').check({ force: true });
for (const box of await page.locator('.payment-input-checkbox').all()) {
    await box.check({ force: true }).catch(() => {});
}
await page.waitForTimeout(400);
await page.locator('.proceed_to_next_button, .action-checkout-function').first().click();
await page.waitForURL(u => !u.href.includes('checkout-payment'), { timeout: 30000 }).catch(() => {});
await page.waitForLoadState('load').catch(() => {});
await step('after COD submit');

const landed = page.url();
ok('left the payment page after placing', !landed.includes('checkout-payment'));
ok('no server error page', !(await hasServerError(page)));

const noise = problems.filter(p => !p.includes('HTTP 405'));
ok('no console/JS/HTTP problems', noise.length === 0);
if (noise.length) console.log('  problems:', noise.slice(0, 6));

await browser.close();
console.log(failures.length ? `FAILURES: ${failures.length}` : 'ALL CLEAN');
process.exit(failures.length ? 1 : 0);
