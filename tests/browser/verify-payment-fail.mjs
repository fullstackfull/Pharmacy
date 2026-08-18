/** payment-fail: browsers get a real page with a retry path; JSON callers keep
 *  their 403. Address dedupe: two identical proceeds add one anonymous row. */
import { chromium } from '@playwright/test';
import { BASE, dismissPromoPopup } from './_env.mjs';
const CUSTOMER = { identity: 'qa.customer@localhost.test', password: 'QaCustomer!2026' };
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
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

// Browser navigation → page with retry links.
await page.goto(BASE + '/payment-fail', { waitUntil: 'load' });
ok('browser gets the failed page', (await page.locator('h1').innerText()).length > 0
    && await page.locator('a[href*="checkout-payment"]').count() === 1
    && await page.locator('a[href*="shop-cart"]').count() >= 1);

// JSON caller keeps the contract.
const json = await page.evaluate(async () => {
    const res = await fetch('/payment-fail', { headers: { Accept: 'application/json' } });
    return { status: res.status, body: await res.json().catch(() => null) };
});
ok('json caller still gets 403 JSON', json.status === 403 && json.body && /failed/i.test(json.body.message));

// Address dedupe: proceed twice with the same address, count anonymous rows via the page? — counted in tinker after.
const fillAll = async () => {
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
    await fillField('input[name="contact_person_name"]', 'Dedupe Probe');
    await page.evaluate(() => {
        for (const name of ['phone', 'billing_phone']) {
            const el = document.querySelector(`input[name="${name}"]`);
            if (el) { el.value = '50002222'; el.dispatchEvent(new Event('input', { bubbles: true })); }
        }
    });
    await pickFirst('select[name="country"]');
    await fillField('input[name="city"]', 'Dedupe City');
    await fillField('input[name="zip"]', '11111');
    await fillField('textarea[name="address"]', 'Dedupe Street 9');
    await fillField('input[name="billing_contact_person_name"]', 'Dedupe Probe');
    await pickFirst('select[name="billing_country"]');
    await fillField('input[name="billing_city"]', 'Dedupe City');
    await fillField('input[name="billing_zip"]', '11111');
    await fillField('textarea[name="billing_address"]', 'Dedupe Street 9');
    await page.locator('a.btn--primary', { hasText: 'Proceed' }).locator('visible=true').first().click();
    await page.waitForURL('**/checkout-payment', { timeout: 30000 }).catch(() => {});
};

// cart must be non-empty with shipping chosen
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
await fillAll();
ok('first proceed reached payment', page.url().includes('checkout-payment'));
await page.goto(BASE + '/checkout-details', { waitUntil: 'load' });
await fillAll();
ok('second identical proceed reached payment', page.url().includes('checkout-payment'));

await browser.close();
console.log(failures.length ? `FAILURES: ${failures.length}` : 'ALL CLEAN');
process.exit(failures.length ? 1 : 0);
