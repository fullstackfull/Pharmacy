/** Captures the Monitoring, Analytics and Developer pages for the record. */
import { chromium } from '@playwright/test';
import { BASE, ADMIN, SHOTS } from './_env.mjs';
import { mkdirSync } from 'node:fs';

mkdirSync(SHOTS, { recursive: true });
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const page = await browser.newPage({ viewport: { width: 1440, height: 1200 } });
await page.goto(BASE + ADMIN.loginPath, { waitUntil: 'domcontentloaded' });
await page.fill('input[name="email"]', ADMIN.email);
await page.fill('input[name="password"]', ADMIN.password);
await page.click('button[type="submit"]');
await page.waitForTimeout(1500);
for (const [name, path] of [['monitoring', '/admin/monitoring'], ['analytics', '/admin/analytics'], ['developer', '/admin/developer']]) {
    await page.goto(BASE + path, { waitUntil: 'load' });
    await page.waitForTimeout(1200);
    await page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: false });
    console.log('wrote', name);
}
await browser.close();
