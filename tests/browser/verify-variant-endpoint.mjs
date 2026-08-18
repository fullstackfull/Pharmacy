/** getVariantPrice hardening: valid id works, bad id 422s, unknown id 404s —
 *  no silent 500s. Runs in-page so CSRF and session are real. */
import { chromium } from '@playwright/test';
import { BASE, dismissPromoPopup } from './_env.mjs';

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const page = await browser.newPage();
const failures = [];
const ok = (label, cond) => {
    console.log((cond ? 'PASS ' : 'FAIL ') + label);
    if (!cond) failures.push(label);
};

await page.goto(BASE + '/product/feme-hair-rosemary-oil-Iv6roz', { waitUntil: 'domcontentloaded' });
await dismissPromoPopup(page);

const post = data => page.evaluate(async (payload) => {
    const token = document.querySelector('meta[name="_token"]').getAttribute('content');
    const body = new URLSearchParams(payload);
    const res = await fetch('/cart/variant_price', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': token },
        body,
    });
    let json = null;
    try { json = await res.json(); } catch (e) { /* non-json */ }
    return { status: res.status, hasPrice: !!(json && json.price !== undefined) };
}, data);

const pid = await page.evaluate(() =>
    document.querySelector('.add-to-cart-details-form input[name="id"]')?.value);
ok('product id found on PDP', !!pid);

const good = await post({ id: pid, quantity: 1 });
ok('valid request returns price payload', good.status === 200 && good.hasPrice);

const missing = await post({ quantity: 1 });
ok('missing id → 422, not 500', missing.status === 422);

const unknown = await post({ id: 99999999, quantity: 1 });
ok('unknown id → 404, not 500', unknown.status === 404);

await browser.close();
console.log(failures.length ? `FAILURES: ${failures.length}` : 'ALL CLEAN');
process.exit(failures.length ? 1 : 0);
