import {BASE, loginAdmin} from '/home/user/Pharmacy/tests/browser/_env.mjs';
import {chromium} from 'playwright';
const browser = await chromium.launch({executablePath: '/opt/pw-browsers/chromium'});
const page = await browser.newPage({viewport: {width: 1600, height: 1100}});
await loginAdmin(page);
await page.goto(BASE + '/admin/monitoring/errors?range=24h&status=all', {waitUntil: 'domcontentloaded'});
await page.waitForTimeout(400);
console.log(await page.evaluate(() => {
    const wrap = document.querySelector('.mon-main .k-table-wrap');
    const table = wrap.querySelector('table');
    const widths = [...table.querySelectorAll('thead th')].map((th) => th.textContent.trim().slice(0, 12) + ':' + Math.round(th.getBoundingClientRect().width));
    return {wrap: wrap.clientWidth, table: table.scrollWidth, widths};
}));
await browser.close();
