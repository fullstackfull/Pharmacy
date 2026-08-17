/**
 * Capture the current state of key screens across the viewport / direction matrix.
 *
 * Doubles as the baseline for visual regression: run before a change, run after, diff the folder.
 * Records console errors and >=400 responses per screen so a screenshot cannot hide a broken page.
 */
import { chromium } from '@playwright/test';
import { BASE, ADMIN, SHOTS, VIEWPORTS, watch } from './_env.mjs';
import { mkdirSync, writeFileSync } from 'node:fs';

const ADMIN_SCREENS = [
    ['dashboard', '/admin/dashboard'],
    ['orders', '/admin/orders/list/all'],
    ['products', '/admin/products/list/in-house'],
    ['customers', '/admin/customer/list'],
    ['theme', '/admin/theme'],
    ['builder', '/admin/theme/builder'],
];
const STORE_SCREENS = [
    ['home', '/'],
    ['products', '/products'],
];

const only = process.argv[2];                       // 'admin' | 'store' | undefined
const dirs = (process.env.DIRECTIONS || 'ltr').split(',');
const sizes = VIEWPORTS.filter(v => !process.env.VIEWPORT || v.name === process.env.VIEWPORT);

mkdirSync(SHOTS, { recursive: true });
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const report = [];

async function shoot(ctx, label, path, size, dir) {
    const page = await ctx.newPage();
    const problems = watch(page);
    try {
        await page.goto(BASE + path, { waitUntil: 'domcontentloaded', timeout: 45000 });
        await page.waitForTimeout(2200);
        const name = `${label}-${size.name}-${dir}.png`;
        await page.screenshot({ path: `${SHOTS}/${name}`, fullPage: true });
        report.push({ screen: label, path, size: size.name, dir, problems: problems.slice(0, 6) });
        console.log(`${name.padEnd(34)} ${problems.length ? problems.length + ' problem(s)' : 'clean'}`);
    } catch (e) {
        report.push({ screen: label, path, size: size.name, dir, error: String(e).slice(0, 160) });
        console.log(`${label}-${size.name}-${dir}  FAILED  ${String(e).slice(0, 90)}`);
    }
    await page.close();
}

for (const dir of dirs) {
    for (const size of sizes) {
        if (only !== 'store') {
            const ctx = await browser.newContext({ viewport: { width: size.width, height: size.height } });
            const login = await ctx.newPage();
            await login.goto(BASE + ADMIN.loginPath, { waitUntil: 'domcontentloaded' });
            await login.fill('input[name="email"]', ADMIN.email);
            await login.fill('input[name="password"]', ADMIN.password);
            await login.click('button[type="submit"]');
            await login.waitForTimeout(1500);
            await login.close();

            for (const [label, path] of ADMIN_SCREENS) await shoot(ctx, `admin-${label}`, path, size, dir);
            await ctx.close();
        }
        if (only !== 'admin') {
            const ctx = await browser.newContext({ viewport: { width: size.width, height: size.height } });
            for (const [label, path] of STORE_SCREENS) await shoot(ctx, `store-${label}`, path, size, dir);
            await ctx.close();
        }
    }
}

writeFileSync(`${SHOTS}/report.json`, JSON.stringify(report, null, 2));
await browser.close();
