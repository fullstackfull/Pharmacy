import {BASE, loginAdmin} from './_env.mjs';
import {chromium} from 'playwright';

const browser = await chromium.launch({executablePath: '/opt/pw-browsers/chromium'});
const page = await browser.newPage({viewport: {width: 1400, height: 900}});
await loginAdmin(page);
for (let i = 0; i < 6; i++) {
    await page.goto(BASE + '/admin/monitoring/storage', {waitUntil: 'domcontentloaded'});
    await page.waitForTimeout(300);
    const r = await page.evaluate(() => {
        const text = document.body.innerText;
        return {
            bars: document.querySelectorAll('.mon-usage').length,
            devices: (text.match(/Of the interval had at least one request in flight/g) || []).length,
            gap: (text.match(/Block devices\s*\n\s*(.*)/) || [])[1] || '',
            tooRecent: text.includes('too recent to derive a rate'),
            firstSample: text.includes('Collecting the first sample'),
        };
    });
    console.log(i, JSON.stringify(r));
}
await browser.close();
