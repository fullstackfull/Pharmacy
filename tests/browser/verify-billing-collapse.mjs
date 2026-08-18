/** Billing collapses behind a default-checked same-as-shipping box; unticking
 *  reveals it; the COD flow completes with same-as-shipping. */
import { chromium } from '@playwright/test';
import { BASE, dismissPromoPopup, hasServerError } from './_env.mjs';
const CUSTOMER = { identity: 'qa.customer@localhost.test', password: 'QaCustomer!2026' };
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const page = await browser.newPage({ viewport: { width: 1440, height: 1100 } });
const failures = [];
const ok = (label, cond) => {
    console.log((cond ? 'PASS ' : 'FAIL ') + label);
    if (!cond) failures.push(label);
};

await page.goto(BASE + '/customer/auth/login', { waitUntil: 'domcontentloaded' });
await page.fill('#customer-login-form input[name="user_identity"]', CUSTOMER.identity);
await page.fill('#customer-login-form input[name="password"]', CUSTOMER.password);
await page.click('#customer-login-form button[type="submit"]');
await page.waitForTimeout(1500);

// Cart + shipping method.
await page.goto(BASE + '/product/feme-hair-rosemary-oil-Iv6roz', { waitUntil: 'domcontentloaded' });
await dismissPromoPopup(page);
await page.click('.add-to-cart-details-form .product-add-to-cart-button');
await page.waitForTimeout(2500);
await page.keyboard.press('Escape');
await page.goto(BASE + '/shop-cart', { waitUntil: 'domcontentloaded' });
await dismissPromoPopup(page);
const shipSelect = page.locator('select.action-set-shipping-id').first();
const shipValues = await shipSelect.locator('option').evaluateAll(os =>
    os.map(o => o.getAttribute('value')).filter(v => v && /^\d+$/.test(v)));
await shipSelect.selectOption(shipValues[0]);
await page.waitForTimeout(2500);

await page.goto(BASE + '/checkout-details', { waitUntil: 'load' });
await page.waitForTimeout(800);

ok('same-as-shipping checked by default', await page.isChecked('#same_as_shipping_address'));
ok('billing form hidden at load', !(await page.locator('#hide_billing_address').isVisible()));
const visibleAtLoad = await page.locator('form input:visible, form select:visible, form textarea:visible').count();
console.log('  (info) visible form fields at load:', visibleAtLoad);
ok('visible fields roughly halved (<14)', visibleAtLoad < 14);

// Untick reveals billing.
await page.click('#same_as_shipping_address');
await page.waitForTimeout(500);
ok('unticking reveals billing form', await page.locator('#hide_billing_address').isVisible());
await page.click('#same_as_shipping_address');
await page.waitForTimeout(500);

// Proceed with same-as-shipping only (shipping form filled, billing untouched).
const fillField = async (sel, val) => {
    const el = page.locator(sel).first();
    if (await el.count()) await el.fill(val).catch(() => {});
};
await fillField('input[name="contact_person_name"]', 'QA Same As Ship');
await page.evaluate(() => {
    const el = document.querySelector('input[name="phone"]');
    if (el) { el.value = '50003333'; el.dispatchEvent(new Event('input', { bubbles: true })); }
});
const country = page.locator('select[name="country"]').first();
const countryVals = await country.locator('option').evaluateAll(os =>
    os.map(o => o.getAttribute('value')).filter(v => v));
await country.selectOption(countryVals[0]);
await fillField('input[name="city"]', 'Kuwait City');
await fillField('input[name="zip"]', '22222');
await fillField('textarea[name="address"]', 'Same As Shipping St 1');

await page.locator('a.btn--primary', { hasText: 'Proceed' }).locator('visible=true').first().click();
await page.waitForURL('**/checkout-payment', { timeout: 30000 }).catch(() => {});
ok('proceed reaches payment with billing collapsed', page.url().includes('checkout-payment'));
ok('no server error page', !(await hasServerError(page)));

await browser.close();
console.log(failures.length ? `FAILURES: ${failures.length}` : 'ALL CLEAN');
process.exit(failures.length ? 1 : 0);
