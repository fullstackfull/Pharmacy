import {BASE, loginAdmin, watch} from './_env.mjs';
import {chromium} from 'playwright';
const browser = await chromium.launch({executablePath: '/opt/pw-browsers/chromium'});
const page = await browser.newPage({viewport: {width: 1400, height: 900}});
watch(page);
await loginAdmin(page);
for (const q of ['?captured[]=a', '?route[]=b', '?trace[]=c', '?min_ms[]=9', '']) {
  const res = await page.goto(BASE + '/admin/monitoring/traces' + q, {waitUntil: 'domcontentloaded'});
  const main = await page.evaluate(() => {
    const el = document.querySelector('.mon-section, .k-view__body, main') || document.body;
    return (el.innerText || '').replace(/\s+/g,' ').slice(0, 300);
  });
  console.log(JSON.stringify(q), res.status(), '=>', main);
}
const j = await page.evaluate(async (base) => {
  const r = await fetch(base + '/admin/monitoring/traces?captured[]=a&json=1', {headers:{'Accept':'application/json'}});
  return (await r.text()).slice(0, 500);
}, BASE);
console.log('JSON:', j);
await browser.close();
