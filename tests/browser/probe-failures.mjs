/** One-off: name the resources whose loads fail on the storefront. */
import { chromium } from '@playwright/test';
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const page = await browser.newPage();
page.on('requestfailed', r => console.log('FAILED', r.failure()?.errorText, r.url().slice(0, 130)));
await page.goto('http://127.0.0.1:8000/', { waitUntil: 'networkidle' }).catch(() => {});
await page.waitForTimeout(2500);
await browser.close();
