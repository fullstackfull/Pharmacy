/** Captures the open cart drawer (LTR and RTL) for the visual record. */
import { chromium } from '@playwright/test';
import { BASE, SHOTS, setDirection, dismissPromoPopup } from './_env.mjs';
import { mkdirSync } from 'node:fs';

mkdirSync(SHOTS, { recursive: true });
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });

for (const dir of ['ltr', 'rtl']) {
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    await setDirection(page, dir);
    await page.goto(BASE + '/product/feme-hair-rosemary-oil-Iv6roz', { waitUntil: 'domcontentloaded' });
    await dismissPromoPopup(page);
    await page.click('.add-to-cart-details-form .product-add-to-cart-button');
    await page.waitForTimeout(2500);
    await page.screenshot({ path: `${SHOTS}/cart-drawer-${dir}.png` });
    console.log(`wrote ${SHOTS}/cart-drawer-${dir}.png`);
    await page.close();
}
await browser.close();
