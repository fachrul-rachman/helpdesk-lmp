import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { createServer } from 'node:http';
import { createRequire } from 'node:module';
import { extname, resolve, sep } from 'node:path';
import process from 'node:process';

const { chromium } = createRequire(import.meta.url)('playwright');
const root = resolve('public');
const manifest = JSON.parse(
    await readFile(resolve(root, 'build/manifest.json'), 'utf8'),
);
const server = createServer(async (req, res) => {
    const path = new URL(req.url, 'http://localhost').pathname;

    if (path.startsWith('/app/')) {
        res.setHeader('Content-Type', 'text/html');
        res.end(
            `<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/build/${manifest['resources/css/app.css'].file}"><div id="app"></div><script type="module" src="/build/${manifest['resources/js/spa/main.tsx'].file}"></script>`,
        );

        return;
    }

    const target = resolve(root, '.' + path);

    if (!target.startsWith(root + sep)) {
        res.writeHead(403).end();

        return;
    }

    try {
        res.setHeader(
            'Content-Type',
            {
                '.js': 'text/javascript',
                '.css': 'text/css',
                '.woff2': 'font/woff2',
            }[extname(target)] ?? 'application/octet-stream',
        );
        res.end(await readFile(target));
    } catch {
        res.writeHead(404).end();
    }
});
await new Promise((done) => server.listen(0, '127.0.0.1', done));
const origin = `http://127.0.0.1:${server.address().port}`;
const browser = await chromium.launch({
    headless: true,
    channel: process.env.PLAYWRIGHT_CHANNEL || 'msedge',
});

try {
    for (const width of [375, 1280]) {
        const context = await browser.newContext({
            viewport: { width, height: 900 },
            serviceWorkers: 'block',
        });
        await context.routeWebSocket('**/*', (socket) => socket.close());
        const page = await context.newPage();
        page.setDefaultTimeout(10000);
        const errors = [];
        page.on('pageerror', (error) => errors.push(error.message));
        let refreshMode = 503,
            expired = false,
            refreshes = 0;
        await page.route('**/*', (route) =>
            new URL(route.request().url()).origin === origin
                ? route.continue()
                : route.abort(),
        );
        await page.route('**/api/**', async (route) => {
            const path = new URL(route.request().url()).pathname;
            const json = (data, status = 200) =>
                route.fulfill({
                    status,
                    contentType: 'application/json',
                    body: JSON.stringify(data),
                });

            if (path === '/api/auth/refresh') {
                refreshes++;

                if (refreshMode === 0) {
                    return route.abort('failed');
                }

                if (refreshMode !== 200) {
                    return json({ message: 'Simulated failure' }, refreshMode);
                }

                expired = false;

                return json({
                    access_token: `access-${refreshes}`,
                    expires_in: 900,
                });
            }

            if (expired) {
                return json({ message: 'Expired' }, 401);
            }

            if (path === '/api/admin/users') {
                return json({
                    data: [],
                    meta: {
                        current_page: 1,
                        last_page: 1,
                        total: 0,
                        per_page: 20,
                    },
                });
            }

            return json({ data: [] });
        });
        await page.addInitScript(() => {
            if (!sessionStorage.getItem('seeded')) {
                localStorage.setItem('helpdesk_refresh_token', 'dummy-refresh');
                localStorage.setItem(
                    'helpdesk_user',
                    JSON.stringify({
                        id: 'dummy-admin',
                        name: 'Admin Test',
                        role: 'admin',
                    }),
                );
                sessionStorage.setItem('seeded', '1');
            }
        });
        const savedToken = () =>
            page.evaluate(() => localStorage.getItem('helpdesk_refresh_token'));
        const notice = page
            .getByRole('alert')
            .filter({ hasText: 'Belum bisa menyambungkan kembali' });
        await page.goto(`${origin}/app/admin/users`);
        await notice.waitFor();
        assert.equal(await savedToken(), 'dummy-refresh');
        assert.equal(refreshes, 1);
        assert.ok(!page.url().endsWith('/login'));

        refreshMode = 200;
        await page
            .getByRole('button', { name: 'Coba lagi', exact: true })
            .click();
        await page
            .getByRole('heading', { name: 'Manajemen User', exact: true })
            .waitFor();
        await notice.waitFor({ state: 'hidden' });

        // A failed refresh must not unmount the open page or discard typed input.
        expired = true;
        refreshMode = 0;
        const search = page.locator('input').first();
        await search.fill('draft tetap ada');
        await notice.waitFor();
        assert.equal(await search.inputValue(), 'draft tetap ada');
        assert.equal(await savedToken(), 'dummy-refresh');
        refreshMode = 200;
        await page.evaluate(() => window.dispatchEvent(new Event('online')));
        await notice.waitFor({ state: 'hidden' });
        assert.equal(await search.inputValue(), 'draft tetap ada');
        await search.fill('coba setelah pulih');
        await page.getByText('Belum ada data.').waitFor();

        // Reopening the page uses the stored refresh token, even after a temporary failure.
        refreshMode = 503;
        await page.reload();
        await notice.waitFor();
        assert.equal(await savedToken(), 'dummy-refresh');
        refreshMode = 200;
        await page
            .getByRole('button', { name: 'Coba lagi', exact: true })
            .click();
        await page
            .getByRole('heading', { name: 'Manajemen User', exact: true })
            .waitFor();

        // A genuinely revoked session must still require login.
        refreshMode = 401;
        await page.reload();
        await page.waitForURL('**/app/login');
        assert.equal(await savedToken(), null);
        assert.deepEqual(errors, []);
        await context.close();
        console.log(
            `Auth recovery, preserved input, reopen and revoked-session checks passed at ${width}px`,
        );
    }
} finally {
    await browser.close();
    await new Promise((done) => server.close(done));
}
