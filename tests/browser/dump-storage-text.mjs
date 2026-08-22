import {BASE, loginAdmin} from './_env.mjs';
import {chromium} from 'playwright';

const browser = await chromium.launch({executablePath: '/opt/pw-browsers/chromium'});
const page = await browser.newPage({viewport: {width: 1600, height: 1100}});
await loginAdmin(page);
await page.goto(BASE + '/admin/monitoring/' + (process.argv[2] || 'storage'), {waitUntil: 'domcontentloaded'});
await page.waitForTimeout(1200);
console.log(await page.evaluate(() => document.querySelector('.mon-section, .mon-body, main')?.innerText || document.body.innerText));
await browser.close();
