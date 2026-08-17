/**
 * Storefront product-card matrix: the three pages that exercise all four
 * <x-k.product-card> variants, in both directions and three viewports.
 * Asserts the pages stay clean (no console/JS/HTTP errors, no error page,
 * no sideways scroll) and reports the card count per run.
 */
import { chromium } from '@playwright/test';
import { BASE, VIEWPORTS, watch, setDirection, hasServerError } from './_env.mjs';

const PAGES = [
    { label: 'home', path: '/' },
    { label: 'products', path: '/products' },
    { label: 'pdp', path: '/product/feme-hair-rosemary-oil-Iv6roz' },
];

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
let failures = 0;

for (const dir of ['ltr', 'rtl']) {
    for (const viewport of VIEWPORTS) {
        const ctx = await browser.newContext({ viewport: { width: viewport.width, height: viewport.height } });
        const page = await ctx.newPage();
        const problems = watch(page);
        await setDirection(page, dir);

        for (const target of PAGES) {
            const response = await page.goto(BASE + target.path, { waitUntil: 'domcontentloaded' });
            await page.waitForTimeout(800);

            const cards = await page.locator('.product-single-hover').count();
            const overflow = await page.evaluate(() =>
                document.scrollingElement.scrollWidth - document.scrollingElement.clientWidth);
            const errorPage = await hasServerError(page);
            const pageDir = await page.getAttribute('html', 'dir');

            const bad = response.status() !== 200 || errorPage || overflow > 0
                || (dir === 'rtl' && pageDir !== 'rtl');
            if (bad) failures++;

            console.log(
                `${dir} ${viewport.name}`.padEnd(14),
                target.label.padEnd(9),
                `status=${response.status()}`,
                `cards=${String(cards).padEnd(3)}`,
                overflow > 0 ? `H-OVERFLOW ${overflow}px` : 'no-overflow',
                errorPage ? 'ERROR-PAGE' : 'ok',
                bad ? ' <-- FAIL' : '',
            );
        }

        if (problems.length) {
            failures++;
            console.log(`  problems (${dir} ${viewport.name}):`, problems.slice(0, 5));
        }
        await ctx.close();
    }
}

await browser.close();
console.log(failures ? `FAILURES: ${failures}` : 'ALL CLEAN');
process.exit(failures ? 1 : 0);
