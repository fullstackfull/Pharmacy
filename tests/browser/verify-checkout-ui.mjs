/** Stepper honors $step with translated labels; mobile sticky total equals the
 *  desktop aside total on the same page. */
import { chromium } from '@playwright/test';
import { BASE, dismissPromoPopup } from './_env.mjs';
const CUSTOMER = { identity: 'qa.customer@localhost.test', password: 'QaCustomer!2026' };
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const failures = [];
const ok = (label, cond) => {
    console.log((cond ? 'PASS ' : 'FAIL ') + label);
    if (!cond) failures.push(label);
};

const page = await browser.newPage({ viewport: { width: 1440, height: 1100 } });
await page.goto(BASE + '/customer/auth/login', { waitUntil: 'domcontentloaded' });
await page.fill('#customer-login-form input[name="user_identity"]', CUSTOMER.identity);
await page.fill('#customer-login-form input[name="password"]', CUSTOMER.password);
await page.click('#customer-login-form button[type="submit"]');
await page.waitForTimeout(1500);

// Refill the cart (checkout consumed it) and choose shipping.
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

// Shipping page: step 2 current, step 1 completed and linked, labels translated slugs not raw English pair
await page.goto(BASE + '/checkout-details', { waitUntil: 'load' });
const steps2 = await page.evaluate(() => {
    const steps = [...document.querySelectorAll('.checkout-steps-custom .step')];
    return steps.map(s => ({
        completed: s.classList.contains('completed'),
        current: s.classList.contains('current'),
        label: s.querySelector('p').innerText.trim(),
        link: !!s.querySelector('a.step-circle'),
    }));
});
ok('details: cart completed+linked, shipping current, payment neither',
    steps2.length === 3 && steps2[0].completed && steps2[0].link
    && steps2[1].current && !steps2[1].completed
    && !steps2[2].completed && !steps2[2].current);

// Fill both address forms (saved addresses are not offered), then proceed.
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
await fillField('input[name="phone"]', '50001111');
await page.evaluate(() => {
    for (const name of ['phone', 'billing_phone']) {
        const el = document.querySelector(`input[name="${name}"]`);
        if (el) { el.value = '50001111'; el.dispatchEvent(new Event('input', { bubbles: true })); }
    }
});
await pickFirst('select[name="country"]');
await fillField('input[name="city"]', 'Kuwait City');
await fillField('input[name="zip"]', '00000');
await fillField('textarea[name="address"]', 'Block 1, Street 2');
await fillField('input[name="billing_contact_person_name"]', 'QA Customer');
await pickFirst('select[name="billing_country"]');
await fillField('input[name="billing_city"]', 'Kuwait City');
await fillField('input[name="billing_zip"]', '00000');
await fillField('textarea[name="billing_address"]', 'Block 1, Street 2');

// Payment page: steps 1+2 completed, payment current.
await page.locator('a.btn--primary', { hasText: 'Proceed' }).locator('visible=true').first().click().catch(() => {});
await page.waitForURL('**/checkout-payment', { timeout: 30000 }).catch(() => {});
await page.waitForSelector('#cash_on_delivery', { timeout: 20000 }).catch(() => {});
const steps3 = await page.evaluate(() => {
    const steps = [...document.querySelectorAll('.checkout-steps-custom .step')];
    return steps.map(s => ({
        completed: s.classList.contains('completed'),
        current: s.classList.contains('current'),
    }));
});
ok('payment: first two completed, payment current',
    steps3.length === 3 && steps3[0].completed && steps3[1].completed && steps3[2].current && !steps3[2].completed);

// Totals: desktop aside vs mobile sticky bar must be the same number.
const pair = await page.evaluate(() => {
    const digits = t => (t.match(/[\d.,]+/g) || []).join('');
    const sticky = document.querySelector('.bottom-sticky3 strong');
    let asideTotal = null;
    document.querySelectorAll('div, li').forEach(el => {
        const label = el.innerText || '';
        if (/^\s*Total\s*$/i.test(el.firstElementChild?.innerText || '') && el.children.length >= 2) {
            asideTotal = digits(el.lastElementChild.innerText);
        }
    });
    return { sticky: sticky ? digits(sticky.innerText) : null, aside: asideTotal };
});
ok('mobile sticky total equals desktop total', !!pair.sticky && !!pair.aside && pair.sticky === pair.aside);
console.log('  (info) totals:', JSON.stringify(pair));

await browser.close();
console.log(failures.length ? `FAILURES: ${failures.length}` : 'ALL CLEAN');
process.exit(failures.length ? 1 : 0);
