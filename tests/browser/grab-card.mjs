/**
 * Dumps the rendered HTML of the storefront product cards.
 *
 * Used to prove that folding four near-identical partials into <x-k.product-card>
 * changes no markup: capture with the component in place, capture again with the
 * change stashed, diff the two files. A visual check cannot make that claim —
 * only the emitted HTML can.
 *
 * Usage: node tests/browser/grab-card.mjs <output-file>
 */
import { chromium } from '@playwright/test';
import { writeFileSync } from 'node:fs';
import { BASE } from './_env.mjs';

const out = process.argv[2];
if (!out) {
    console.error('usage: node tests/browser/grab-card.mjs <output-file>');
    process.exit(2);
}

const PAGES = [
    { label: 'home', path: '/' },                                        // inline + category
    { label: 'products', path: '/products' },                            // filter (_ajax-products)
    { label: 'pdp', path: '/product/feme-hair-rosemary-oil-Iv6roz' },    // inline + inline-plain
];

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
const chunks = [];

for (const target of PAGES) {
    const response = await page.goto(BASE + target.path, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(600);

    const cards = await page.$$eval('.product-single-hover', nodes =>
        nodes.map(node => node.outerHTML)
    );

    chunks.push(`===== ${target.label} (${target.path}) status=${response?.status()} cards=${cards.length} =====`);
    // Collapse runs of whitespace: the old partials differed from each other on
    // indentation alone, and that is not a difference worth reporting.
    chunks.push(...cards.map(html => html.replace(/\s+/g, ' ').replace(/> </g, '>\n<')));
}

writeFileSync(out, chunks.join('\n') + '\n');
console.log(`wrote ${out}`);
await browser.close();
