import {BASE, SHOTS, loginAdmin} from './_env.mjs';
import {chromium} from 'playwright';
const browser = await chromium.launch({executablePath: '/opt/pw-browsers/chromium'});
const page = await browser.newPage({viewport: {width: 1500, height: 1000}});
await loginAdmin(page);
await page.goto(BASE + '/admin/monitoring/queues?range=' + (process.env.RANGE || '1h'), {waitUntil: 'domcontentloaded'});
await page.waitForTimeout(1500);
await page.evaluate(() => document.querySelectorAll('.phpdebugbar,.modal,.modal-backdrop,.alert--container').forEach((n) => n.remove()));
const cards = page.locator('.mon-section .k-card, .k-card');
const n = await cards.count();
for (let i = 0; i < n; i++) {
    await cards.nth(i).screenshot({path: `${SHOTS}/q-card-${i}.png`}).catch(() => {});
}
console.log('cards shot:', n);
await browser.close();
