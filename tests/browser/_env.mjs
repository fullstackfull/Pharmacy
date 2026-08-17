/**
 * Shared setup for browser checks against the local staging app.
 *
 * Staging only: BASE points at 127.0.0.1 and the credentials are the throwaway
 * staging admin created during environment setup. Nothing here may be pointed at
 * a production host.
 */
export const BASE = process.env.APP_BASE || 'http://127.0.0.1:8000';
export const ADMIN = {
    email: process.env.STAGING_ADMIN_EMAIL || 'staging.qa@localhost.test',
    password: process.env.STAGING_ADMIN_PASSWORD || 'StagingQA!2026',
    // The controller gates the admin login page on the literal primary key id==1, so any other
    // account - including one holding the Master Admin role - must sign in via the employee page.
    loginPath: '/login/employee',
    role: 'employee',
};
export const SHOTS = process.env.SHOT_DIR || '/tmp/shots';

export const VIEWPORTS = [
    { name: 'desktop', width: 1440, height: 900 },
    { name: 'tablet', width: 834, height: 1112 },
    { name: 'mobile', width: 390, height: 844 },
];

/** Collects console errors and failed requests so a "looks fine" screenshot cannot hide a broken page. */
export function watch(page) {
    const problems = [];
    page.on('console', m => { if (m.type() === 'error') problems.push('CONSOLE ' + m.text().slice(0, 200)); });
    page.on('pageerror', e => problems.push('JS ' + String(e).slice(0, 200)));
    page.on('response', r => {
        if (r.status() >= 400) problems.push(`HTTP ${r.status()} ${r.url().replace(BASE, '').slice(0, 120)}`);
    });
    return problems;
}

export async function loginAdmin(page) {
    await page.goto(BASE + ADMIN.loginPath, { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="email"]', ADMIN.email);
    await page.fill('input[name="password"]', ADMIN.password);
    await page.click('button[type="submit"]');
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.waitForTimeout(1200);
    return page.url();
}

/**
 * Switch the session to Arabic (rtl) or English (ltr).
 *
 * The app stores direction in the session via POST /change-language, so RTL cannot
 * be faked by setting an attribute — the server has to render it. This drives the
 * real endpoint, which is what makes the RTL screenshots trustworthy.
 */
export async function setDirection(page, dir) {
    const code = dir === 'rtl' ? 'sy' : 'en';
    await page.goto(BASE + '/', { waitUntil: 'domcontentloaded' });
    const token = await page.getAttribute('meta[name="csrf-token"]', 'content').catch(() => null);
    const status = await page.evaluate(async ({ base, code, token }) => {
        const body = new URLSearchParams({ language_code: code });
        const res = await fetch(base + '/change-language', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': token || '' },
            body,
        });
        return res.status;
    }, { base: BASE, code, token });
    return status;
}
