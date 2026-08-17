/**
 * Phase 1 exit check: every Kohl primitive rendered in the real admin shell,
 * across light/dark, LTR/RTL and three viewports.
 *
 * Each run logs in fresh and sets the language through the app's own endpoint —
 * direction lives in the server session, so a saved storage state cannot be
 * trusted to still be in the direction it was saved in.
 */
import { chromium } from '@playwright/test';
import { BASE, ADMIN, SHOTS, watch } from './_env.mjs';
import { mkdirSync, writeFileSync } from 'node:fs';

const PAGE_PATH = process.env.VERIFY_PATH || '/admin/design-system';
const SIZES = [
    { name: 'desktop', width: 1440, height: 1400 },
    { name: 'tablet', width: 834, height: 1200 },
    { name: 'mobile', width: 390, height: 900 },
];
const MATRIX = [
    { dir: 'ltr', theme: 'light' },
    { dir: 'ltr', theme: 'dark' },
    { dir: 'rtl', theme: 'light' },
    { dir: 'rtl', theme: 'dark' },
];

mkdirSync(SHOTS, { recursive: true });
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const report = [];

for (const size of SIZES) {
    for (const { dir, theme } of MATRIX) {
        // Mobile only needs one theme per direction; the grid is otherwise 12 runs.
        if (size.name !== 'desktop' && theme === 'dark') continue;

        const ctx = await browser.newContext({ viewport: { width: size.width, height: size.height } });
        const page = await ctx.newPage();
        const problems = watch(page);

        await page.goto(BASE + ADMIN.loginPath, { waitUntil: 'domcontentloaded' });
        await page.fill('input[name="email"]', ADMIN.email);
        await page.fill('input[name="password"]', ADMIN.password);
        await page.click('button[type="submit"]');
        await page.waitForTimeout(1500);

        const token = await page.getAttribute('meta[name="csrf-token"]', 'content').catch(() => null);
        await page.evaluate(async ({ code, tok }) => {
            await fetch('/change-language', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': tok || '' },
                body: new URLSearchParams({ language_code: code }),
            });
        }, { code: dir === 'rtl' ? 'sy' : 'en', tok: token });

        await page.addInitScript(value => {
            try { window.localStorage.setItem('k-theme', value); } catch (e) { /* ignore */ }
        }, theme);

        await page.goto(BASE + PAGE_PATH, { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(2000);

        const audit = await page.evaluate(() => {
            const count = selector => document.querySelectorAll(selector).length;
            const root = document.documentElement;
            const body = document.body;
            // Contrast sanity: text and page ground must not resolve to the same colour,
            // which is what a half-themed token set produces.
            const bg = getComputedStyle(body).backgroundColor;
            const fg = getComputedStyle(body).color;
            // Nothing may push the page sideways.
            const overflow = document.scrollingElement.scrollWidth - document.scrollingElement.clientWidth;
            return {
                dir: root.getAttribute('dir'),
                theme: root.getAttribute('data-k-theme') || 'system',
                bodyBg: bg,
                bodyFg: fg,
                horizontalOverflow: overflow,
                primitives: {
                    stat: count('.k-stat'),
                    btn: count('.k-btn'),
                    badge: count('.k-badge'),
                    field: count('.k-field'),
                    table: count('.k-table'),
                    chip: count('.k-chip'),
                    skeleton: count('.k-skeleton'),
                    empty: count('.k-empty'),
                    drawer: count('.k-drawer'),
                    tabs: count('.k-tab'),
                    avatar: count('.k-avatar'),
                },
            };
        });

        const label = `kohl-${size.name}-${dir}-${theme}`;
        await page.screenshot({ path: `${SHOTS}/${label}.png`, fullPage: true });

        report.push({ label, ...audit, problems: problems.slice(0, 6) });
        const total = Object.values(audit.primitives).reduce((a, b) => a + b, 0);
        console.log(
            label.padEnd(30),
            `dir=${audit.dir}`.padEnd(9),
            `theme=${audit.theme}`.padEnd(13),
            `primitives=${total}`.padEnd(17),
            audit.horizontalOverflow > 0 ? `H-OVERFLOW ${audit.horizontalOverflow}px` : 'no-overflow',
            problems.length ? `${problems.length} problem(s)` : 'clean',
        );

        await ctx.close();
    }
}

writeFileSync(`${SHOTS}/kohl-report.json`, JSON.stringify(report, null, 2));
await browser.close();
