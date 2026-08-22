import {BASE, loginAdmin} from './_env.mjs';
import {chromium} from 'playwright';
const browser = await chromium.launch({executablePath: '/opt/pw-browsers/chromium'});
const page = await browser.newPage();
await loginAdmin(page);
for (const range of (process.env.RANGES || '1h').split(',')) {
    const json = await page.evaluate(async ({base, range}) => {
        const r = await fetch(base + '/admin/monitoring/queues?json=1&range=' + range, {headers: {'X-Requested-With': 'XMLHttpRequest'}});
        return {status: r.status, body: await r.text()};
    }, {base: BASE, range});
    console.log('=== range', range, 'status', json.status);
    console.log(json.body);
}
await browser.close();
