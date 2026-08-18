import { chromium } from '@playwright/test';
import { BASE } from './_env.mjs';
const CUSTOMER = { identity: 'qa.customer@localhost.test', password: 'QaCustomer!2026' };
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const page = await browser.newPage();
page.on('pageerror', e => console.log('JSERR @', page.url().slice(-40), '::', String(e).slice(0, 160)));
await page.goto(BASE + '/customer/auth/login', { waitUntil: 'domcontentloaded' });
await page.fill('#customer-login-form input[name="user_identity"]', CUSTOMER.identity);
await page.fill('#customer-login-form input[name="password"]', CUSTOMER.password);
await page.click('#customer-login-form button[type="submit"]');
await page.waitForTimeout(1200);
for (const path of ['/account-oder', '/checkout-payment', '/user-account']) {
    await page.goto(BASE + path, { waitUntil: 'load' }).catch(() => {});
    await page.waitForTimeout(1200);
}
await browser.close();
