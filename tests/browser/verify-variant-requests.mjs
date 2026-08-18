/** One option change must produce exactly one variant_price request. */
import { chromium } from '@playwright/test';
import { BASE, dismissPromoPopup } from './_env.mjs';
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const page = await browser.newPage();
let hits = 0;
page.on('request', r => { if (r.url().includes('cart/variant_price')) hits++; });
await page.goto(BASE + '/product/feme-hair-rosemary-oil-Iv6roz', { waitUntil: 'load' });
await dismissPromoPopup(page);
await page.waitForTimeout(800);
hits = 0;
await page.click('.add-to-cart-details-form .btn-number[data-type="plus"]');
await page.waitForTimeout(1500);
console.log('variant_price requests after one quantity change:', hits);
await browser.close();
process.exit(0);
