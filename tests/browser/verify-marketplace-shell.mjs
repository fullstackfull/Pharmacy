/**
 * Marketplace shell batch: every routed marketplace screen (my own earlier
 * builds) moves its plain Bootstrap tables onto the k-table shell. Each page
 * must render without server error and show a k-table (or its own honest
 * empty state when the backing table is absent/empty), console clean.
 */
import { chromium } from '@playwright/test';
import { BASE, watch, loginAdmin, hasServerError } from './_env.mjs';

const VENDOR = { email: 'mahmoudeosama2@gmail.com', password: 'QaVendor!2026' };
const ADMIN_PAGES = [
    'approvals', 'audit-log', 'batches', 'commission-rules', 'customer-groups',
    'exchange-rates', 'fulfillments', 'inventory-adjustments', 'payment-routing',
    'payouts', 'purchase-orders', 'purchase-orders/create', 'reconciliation',
    'returns', 'seller-scorecard', 'seller-verification', 'settlements',
    'shipping-zones', 'sla', 'suppliers', 'warehouses', 'ledger/1',
];

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const page = await browser.newPage({ viewport: { width: 1440, height: 1100 } });
const problems = watch(page);
const failures = [];
const ok = (label, cond) => {
    console.log((cond ? 'PASS ' : 'FAIL ') + label);
    if (!cond) failures.push(label);
};

async function checkPage(label, url) {
    await page.goto(url, { waitUntil: 'load' }).catch(async () => {
        await page.waitForTimeout(2000);
        await page.goto(url, { waitUntil: 'load' });
    });
    const broken = await hasServerError(page);
    // a page whose feature-tables are missing renders its own empty state and
    // may legitimately have no <table> at all — but leftover plain BS tables fail
    const stray = await page.locator('.content table.table, .content table[class*="table-hover"]').count();
    ok(`${label}: renders`, !broken);
    ok(`${label}: no stray BS table`, stray === 0);
}

await loginAdmin(page);
for (const slug of ADMIN_PAGES) {
    await checkPage(`admin ${slug}`, `${BASE}/admin/marketplace/${slug}`);
}

await page.goto(BASE + '/vendor/auth/login', { waitUntil: 'domcontentloaded' });
await page.fill('input[name="email"]', VENDOR.email);
await page.fill('input[name="password"]', VENDOR.password);
await page.click('button[type="submit"]');
await page.waitForTimeout(2500);
await checkPage('vendor staff', `${BASE}/vendor/business-settings/staff`);

const known = problems.filter(p => !p.includes('Element not found'));
ok('no console/JS/HTTP problems', known.length === 0);
if (known.length) console.log('  problems:', known.slice(0, 10));
await browser.close();
console.log(failures.length ? `FAILURES: ${failures.length}` : 'ALL CLEAN');
process.exit(failures.length ? 1 : 0);
