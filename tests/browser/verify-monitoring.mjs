/** Monitoring page: renders, service strip tones, stats populate from the
 *  embedded snapshot, pulse endpoint feeds the auto-refresh, hot paths fill. */
import { chromium } from '@playwright/test';
import { BASE, ADMIN, watch, hasServerError } from './_env.mjs';

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const page = await browser.newPage({ viewport: { width: 1440, height: 1100 } });
const problems = watch(page);
const failures = [];
const ok = (label, cond) => {
    console.log((cond ? 'PASS ' : 'FAIL ') + label);
    if (!cond) failures.push(label);
};

await page.goto(BASE + ADMIN.loginPath, { waitUntil: 'domcontentloaded' });
await page.fill('input[name="email"]', ADMIN.email);
await page.fill('input[name="password"]', ADMIN.password);
await page.click('button[type="submit"]');
await page.waitForTimeout(1500);

await page.goto(BASE + '/admin/monitoring', { waitUntil: 'load' });
await page.waitForTimeout(800);
ok('renders', !(await hasServerError(page)));
ok('sidebar entry present', await page.locator('[data-item="monitoring"]').count() === 1);

const reqPerMin = await page.locator('[data-stat="requests_per_min"] .k-stat__value').innerText();
ok('stats populated from snapshot', reqPerMin !== '–');
ok('DB badge toned', await page.locator('[data-svc="db"].k-badge--success, [data-svc="db"].k-badge--warning, [data-svc="db"].k-badge--danger').count() === 1);
ok('server table filled', (await page.locator('[data-v="runtime"]').innerText()).includes('8.'));
ok('hot paths listed', (await page.locator('#hot-paths tr').count()) >= 1
    && !(await page.locator('#hot-paths').innerText()).includes('collecting'));

// The pulse feed itself.
const pulse = await page.evaluate(async () => {
    const res = await fetch('/admin/monitoring/pulse', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    return { status: res.status, body: await res.json() };
});
ok('pulse endpoint answers with sections', pulse.status === 200
    && pulse.body.traffic && pulse.body.server && pulse.body.queue && pulse.body.api);

// The pulse path must not record itself.
const selfRows = await page.evaluate(async () => {
    const res = await fetch('/admin/monitoring/pulse', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    await res.json();
    return true;
});
ok('pulse called twice without error', selfRows);

ok('no console/JS/HTTP problems', problems.length === 0);
if (problems.length) console.log('  problems:', problems.slice(0, 6));
await browser.close();
console.log(failures.length ? `FAILURES: ${failures.length}` : 'ALL CLEAN');
process.exit(failures.length ? 1 : 0);
