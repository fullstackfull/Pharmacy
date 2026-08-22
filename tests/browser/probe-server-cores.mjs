import {BASE, loginAdmin} from './_env.mjs';
import {chromium} from 'playwright';
const browser = await chromium.launch({executablePath: '/opt/pw-browsers/chromium'});
const page = await browser.newPage({viewport: {width: 1600, height: 1100}});
await loginAdmin(page);
for (let i = 1; i <= 3; i++) {
    await page.goto(BASE + '/admin/monitoring/server', {waitUntil: 'domcontentloaded'});
    await page.waitForTimeout(600);
    const bars = await page.locator('.mon-cores__row').count();
    const card = await page.locator('.k-card, .mon-card').nth(1).innerText();
    console.log('load', i, '| core bars:', bars, '|', card.split('\n').slice(0, 8).join(' / '));
}
await browser.close();
