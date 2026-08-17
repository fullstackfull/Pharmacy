import { chromium } from '@playwright/test';
import { BASE, ADMIN, SHOTS, watch } from './_env.mjs';
import { mkdirSync } from 'node:fs';

mkdirSync(SHOTS, { recursive: true });
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
const page = await ctx.newPage();
const problems = watch(page);

await page.goto(BASE + ADMIN.loginPath, { waitUntil: 'domcontentloaded' });
const role = await page.getAttribute('input[name="role"]', 'value').catch(() => null);
console.log('role field value:', role);

await page.fill('input[name="email"]', ADMIN.email);
await page.fill('input[name="password"]', ADMIN.password);
await page.click('button[type="submit"]');
await page.waitForLoadState('networkidle').catch(() => {});
await page.waitForTimeout(2000);

console.log('URL:', page.url());
const alerts = await page.$$eval('.alert, .invalid-feedback, .text-danger, [role="alert"]',
    els => els.map(e => e.textContent.trim()).filter(Boolean).slice(0, 6));
console.log('ALERTS:', alerts);
await page.screenshot({ path: `${SHOTS}/01-after-login.png` });
if (page.url().includes('/admin')) await ctx.storageState({ path: `${SHOTS}/admin-state.json` });
console.log('PROBLEMS:', problems.slice(0, 6));
await browser.close();
