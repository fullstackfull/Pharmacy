/** Quick-view: repeated opens must not stack handlers (one qty tap = +1), and
 *  its variant_price payload must carry ONE form's fields, not two merged. */
import { chromium } from '@playwright/test';
import { BASE, dismissPromoPopup } from './_env.mjs';
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
const failures = [];
const ok = (label, cond) => {
    console.log((cond ? 'PASS ' : 'FAIL ') + label);
    if (!cond) failures.push(label);
};
const payloads = [];
page.on('request', r => {
    if (r.url().includes('cart/variant_price')) payloads.push(r.postData() || '');
});

// Home has cards with quick-view buttons.
await page.goto(BASE + '/', { waitUntil: 'load' });
await dismissPromoPopup(page);

// Open and close quick-view three times to give handlers a chance to stack.
for (let i = 0; i < 3; i++) {
    await page.locator('.action-product-quick-view').first().click();
    await page.waitForSelector('#quick-view .add-to-cart-details-form', { timeout: 15000 });
    await page.waitForTimeout(900);
    await page.keyboard.press('Escape');
    await page.waitForTimeout(600);
}

// Open once more and tap + exactly once.
await page.locator('.action-product-quick-view').first().click();
await page.waitForSelector('#quick-view .add-to-cart-details-form', { timeout: 15000 });
await page.waitForTimeout(900);
const before = await page.inputValue('#quick-view .input-number');
payloads.length = 0;
await page.click('#quick-view .btn-number[data-type="plus"]');
await page.waitForTimeout(1600);
const after = await page.inputValue('#quick-view .input-number');
ok('after 4 opens, one + tap moves quantity by exactly 1', Number(after) === Number(before) + 1);
console.log('  (info) qty', before, '→', after, '| variant_price calls:', payloads.length);
ok('at most one variant_price call for the tap', payloads.length <= 1);
if (payloads.length) {
    const idCount = (payloads[0].match(/(^|&)id=/g) || []).length;
    ok('payload carries exactly one form (single id field)', idCount === 1);
}

await browser.close();
console.log(failures.length ? `FAILURES: ${failures.length}` : 'ALL CLEAN');
process.exit(failures.length ? 1 : 0);
