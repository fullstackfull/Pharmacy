import {BASE, loginAdmin} from './_env.mjs';
import {chromium} from 'playwright';
const browser = await chromium.launch({executablePath: '/opt/pw-browsers/chromium'});
const page = await browser.newPage();
await loginAdmin(page);
for (const range of ['live', '15m', '1h', '6h', '24h', '7d', '30d', '90d']) {
    const res = await page.goto(BASE + '/admin/monitoring/server?range=' + range, {waitUntil: 'domcontentloaded'});
    const body = await page.evaluate(() => document.body.innerText);
    const bad = ['Warning:', 'Notice:', 'Deprecated:', 'Undefined ', 'Attempt to read', 'htmlspecialchars(', 'Array to string', 'Division by zero']
        .filter(n => body.includes(n));
    console.log(range, '->', res.status(), bad.length ? 'DIAGNOSTIC ' + bad.join(',') : 'clean');
}
await browser.close();
