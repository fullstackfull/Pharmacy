/**
 * Third admin batch: customer wallet + loyalty reports (stat cards and filter
 * cards stay, transaction tables move), refund-transaction report (payment
 * filter select in the toolbar), the updated-product shipping approvals, and
 * the contact-messages table partial.
 */
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
const checkListPage = async (label, url, extra = async () => {}) => {
    await page.goto(BASE + url, { waitUntil: 'load' });
    ok(label + ': renders', !(await hasServerError(page)));
    ok(label + ': data-view or k-table present', (await page.locator('.k-view, .k-table').first().count()) >= 1);
    ok(label + ': rows or honest empty', (await page.locator('.k-table tbody tr').count()) >= 1
        || (await page.locator('.k-empty').count()) >= 1);
    await extra();
};

await page.goto(BASE + '/login/employee', { waitUntil: 'domcontentloaded' });
await page.fill('input[name="email"]', ADMIN.email);
await page.fill('input[name="password"]', ADMIN.password);
await page.click('button[type="submit"]');
await page.waitForTimeout(2000);

await checkListPage('wallet', '/admin/customer/wallet/report', async () => {
    ok('wallet: export action present', (await page.locator('.k-view a[href*="wallet/export"]').count()) === 1);
});
await checkListPage('loyalty', '/admin/customer/loyalty/report', async () => {
    ok('loyalty: export action present', (await page.locator('.k-view a[href*="loyalty/export"]').count()) === 1);
});
await checkListPage('refund-txn', '/admin/report/transaction/refund-transaction-list', async () => {
    ok('refund-txn: payment filter + formUrlChange hook', (await page.locator('.k-view #payment_method').count()) === 1
        && (await page.locator('.k-view #formUrlChange').count()) === 1);
});
await checkListPage('updated-products', '/admin/products/updated-product-list/all', async () => {
    ok('updated-products: filter offcanvas present', (await page.locator('#offcanvasProductFilter').count()) === 1);
});
await checkListPage('contacts', '/admin/contact/list');

const known = problems.filter(p => !p.includes('Element not found'));
ok('no console/JS/HTTP problems', known.length === 0);
if (known.length) console.log('  problems:', known.slice(0, 8));
await browser.close();
console.log(failures.length ? `FAILURES: ${failures.length}` : 'ALL CLEAN');
process.exit(failures.length ? 1 : 0);
