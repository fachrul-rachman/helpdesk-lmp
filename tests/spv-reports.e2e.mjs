import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { createServer } from 'node:http';
import { createRequire } from 'node:module';
import { extname, resolve, sep } from 'node:path';
import process from 'node:process';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');
const divisions = [
    { id: '11111111-1111-4111-8111-111111111111', name: 'Services' },
    { id: '22222222-2222-4222-8222-222222222222', name: 'Customer Service' },
];
const metric = { eligible: 10, on_time: 8, late: 2 };
const fixtures = {
    overview: {
        summary: { total: 20, active: 12, completed: 6, queued: 2 },
        statuses: [
            { status: 'new', count: 2 },
            { status: 'open', count: 4 },
            { status: 'on_progress', count: 3 },
            { status: 'queue', count: 2 },
        ],
        active_by_division: divisions.map((d, i) => ({
            ...d,
            count: i ? 4 : 8,
        })),
    },
    sla: {
        first_response: { ...metric, average_minutes: 25, missing: 2 },
        resolution: { eligible: 6, on_time: 4, late: 2 },
        divisions: divisions.map((d) => ({
            ...d,
            first_response_target_minutes: 5,
            resolution_target_minutes: 4320,
            average_minutes: 25,
            first_response: metric,
            resolution: { eligible: 3, on_time: 2, late: 1 },
        })),
    },
    category: {
        categories: [
            { name: 'Complaint', value: divisions[0].id, count: 12 },
            { name: 'Belum diisi', value: null, count: 8 },
        ],
        subcategories: [
            { name: 'Perawatan', value: divisions[1].id, count: 20 },
        ],
        sites: [{ name: 'Site Utara', value: 'Site Utara', count: 20 }],
        zones: [{ name: 'A', value: 'A', count: 20 }],
    },
    satisfaction: {
        summary: { total: 5, average: 3, good: 3, bad: 2, unreviewed: 1 },
        ratings: [5, 4, 3, 2, 1].map((rating) => ({ rating, count: 1 })),
        divisions: divisions.map((d, i) => ({
            ...d,
            good: i ? 1 : 2,
            bad: 1,
            total: i ? 2 : 3,
        })),
    },
};
const publicRoot = resolve('public');
const manifest = JSON.parse(
    await readFile(resolve(publicRoot, 'build/manifest.json'), 'utf8'),
);
const entry = manifest['resources/js/spa/main.tsx'];
const css = manifest['resources/css/app.css'];
const server = createServer(async (req, res) => {
    const pathname = new URL(req.url, 'http://localhost').pathname;

    if (pathname.startsWith('/app/')) {
        res.setHeader('Content-Type', 'text/html');
        res.end(
            `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/build/${css.file}"></head><body><div id="app"></div><script type="module" src="/build/${entry.file}"></script></body></html>`,
        );

        return;
    }

    const target = resolve(publicRoot, '.' + pathname);

    if (!target.startsWith(publicRoot + sep)) {
        res.writeHead(403);
        res.end();

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
        res.writeHead(404);
        res.end();
    }
});
await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
const origin = 'http://127.0.0.1:' + server.address().port;
const browser = await chromium.launch({
    headless: true,
    channel: process.env.PLAYWRIGHT_CHANNEL || 'msedge',
});
const context = await browser.newContext({
    viewport: { width: 1280, height: 900 },
    reducedMotion: 'reduce',
    serviceWorkers: 'block',
});
const page = await context.newPage(),
    errors = [],
    requests = [];
page.on('pageerror', (error) => errors.push(error.message));
page.setDefaultTimeout(20000);
await context.routeWebSocket('**/*', (socket) => socket.close());
await page.route('**/*', (route) =>
    new URL(route.request().url()).origin === origin
        ? route.continue()
        : route.abort(),
);
await page.route('**/api/**', async (route) => {
    const url = new URL(route.request().url());
    requests.push(url);
    const json = (body, status = 200) =>
        route.fulfill({
            status,
            contentType: 'application/json',
            body: JSON.stringify(body),
        });

    if (url.pathname === '/api/auth/refresh') {
        return json({ access_token: 'test-access-token' });
    }

    if (url.pathname === '/api/push/config') {
        return json({ enabled: false, public_key: null });
    }

    if (url.pathname === '/api/divisions') {
        return json({ data: divisions });
    }

    if (url.pathname === '/api/spv/reports') {
        return json({
            data: fixtures[url.searchParams.get('section')],
            divisions,
        });
    }

    if (url.pathname === '/api/spv/reports/reviews') {
        const rating = Number(
            url.searchParams.get('rating') ||
                (url.searchParams.get('rating_group') === 'bad' ? 1 : 3),
        );

        return json({
            data: [
                {
                    id: 1,
                    ticket_id: 'test-ticket',
                    ticket_number: 'T26-00117',
                    rating,
                    feedback: 'Ulasan pengujian',
                    division_name: 'Services',
                    submitted_at: '2026-10-10T02:00:00Z',
                },
            ],
            meta: { total: 1, page: 1, per_page: 5 },
        });
    }

    if (
        url.pathname === '/api/spv/reports/tickets' ||
        url.pathname === '/api/tickets'
    ) {
        const item = {
            id: 'test-ticket',
            ticket_number: 'T26-00117',
            subject: 'Ticket pengujian',
            customer: { name: 'Customer test' },
            division: divisions[0],
            status: 'new',
            priority: 'high',
            created_at: '2026-10-10T02:00:00Z',
        };

        return json({
            data: [item],
            meta: {
                total: 55,
                page: Number(url.searchParams.get('page') || 1),
                per_page: 50,
            },
        });
    }

    if (url.pathname === '/api/spv/tickets/export') {
        return route.fulfill({
            status: 200,
            contentType:
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            headers: {
                'Content-Disposition': 'attachment; filename="test.xlsx"',
                'Access-Control-Expose-Headers': 'Content-Disposition',
            },
            body: 'Export browser test',
        });
    }

    errors.push('Unexpected API request: ' + url.pathname);

    return json({ message: 'Not mocked' }, 404);
});
await page.clock.setFixedTime(new Date('2026-10-20T05:00:00Z'));
await page.addInitScript(() => {
    localStorage.setItem(
        'helpdesk_user',
        JSON.stringify({
            id: 'test-spv',
            name: 'SPV test',
            role: 'spv',
            is_active: true,
        }),
    );
    localStorage.setItem('helpdesk_refresh_token', 'test-refresh-token');
});
async function tab(name) {
    await page
        .getByRole('navigation', { name: 'Bagian laporan' })
        .getByRole('button', { name, exact: true })
        .click();
}
async function waitReport() {
    await page
        .getByRole('status')
        .filter({ hasText: 'Memuat laporan' })
        .waitFor({ state: 'hidden' });
}

try {
    await page.goto(origin + '/app/spv/reports');
    await page
        .getByRole('heading', { name: 'Ticket berdasarkan status' })
        .waitFor();
    assert.equal(
        await page
            .getByRole('navigation', { name: 'Bagian laporan' })
            .getByRole('button')
            .count(),
        4,
    );
    assert.equal(await page.locator('table').count(), 0);
    await page
        .getByRole('combobox', { name: 'Rentang waktu', exact: true })
        .selectOption('week');
    await waitReport();
    assert.equal(
        requests
            .filter((u) => u.pathname === '/api/spv/reports')
            .at(-1)
            .searchParams.get('start_date'),
        '2026-10-14',
    );
    await page
        .getByRole('combobox', { name: 'Divisi', exact: true })
        .selectOption(divisions[0].id);
    await waitReport();
    assert.equal(
        requests
            .filter((u) => u.pathname === '/api/spv/reports')
            .at(-1)
            .searchParams.get('division_id'),
        divisions[0].id,
    );
    await page
        .getByRole('combobox', { name: 'Rentang waktu', exact: true })
        .selectOption('month');
    await waitReport();
    await page
        .getByRole('link', { name: 'Services: 8 ticket', exact: true })
        .click();
    await page.getByRole('heading', { name: 'Semua Ticket' }).waitFor();
    assert.equal(
        requests
            .filter((u) => u.pathname.endsWith('/reports/tickets'))
            .at(-1)
            .searchParams.get('filter'),
        'active',
    );
    await page.getByRole('button', { name: 'Berikutnya', exact: true }).click();
    await page.getByText('Halaman 2 dari 2').waitFor();
    assert.equal(
        requests
            .filter((u) => u.pathname.endsWith('/reports/tickets'))
            .at(-1)
            .searchParams.get('page'),
        '2',
    );
    await page.goto(origin + '/app/spv/reports');
    await tab('SLA');
    await page
        .getByRole('heading', { name: 'SLA masing-masing divisi' })
        .waitFor();
    await tab('Kategori & lokasi');
    await page.getByRole('heading', { name: 'Kategori global' }).waitFor();
    assert.equal(await page.locator('table').count(), 0);
    await tab('Kepuasan');
    await page.getByText('Ulasan pengujian').waitFor();
    await page
        .getByRole('button', { name: '3 bintang: 1 ulasan', exact: true })
        .click();
    await page.getByText('3 / 5 (Good)', { exact: true }).waitFor();
    await page
        .getByRole('button', { name: 'bad: Services', exact: true })
        .click();
    await page.getByText('1 / 5 (Bad)', { exact: true }).waitFor();
    await page.getByRole('button', { name: 'Ekspor', exact: true }).click();
    const panel = page.getByRole('region', {
        name: 'Ekspor ticket',
        exact: true,
    });
    await panel.getByLabel('Baru', { exact: true }).check();
    await panel.getByLabel('Open', { exact: true }).check();
    await panel.getByLabel('Solved', { exact: true }).uncheck();
    await panel.getByLabel('Closed', { exact: true }).uncheck();
    await panel.getByLabel('Baru', { exact: true }).uncheck();
    await panel.getByLabel('Open', { exact: true }).uncheck();
    assert.equal(
        await panel.getByRole('button', { name: 'Unduh XLSX' }).isDisabled(),
        true,
    );
    await panel.getByLabel('Baru', { exact: true }).check();
    await panel.getByLabel('Open', { exact: true }).check();
    await panel
        .getByRole('combobox', { name: 'Divisi', exact: true })
        .selectOption(divisions[0].id);
    const download = page.waitForEvent('download');
    await panel.getByRole('button', { name: 'Unduh XLSX' }).click();
    assert.equal((await download).suggestedFilename(), 'test.xlsx');
    const exportRequest = requests
        .filter((u) => u.pathname.endsWith('/tickets/export'))
        .at(-1);
    assert.equal(
        exportRequest.searchParams.get('division_id'),
        divisions[0].id,
    );
    assert.deepEqual(exportRequest.searchParams.getAll('statuses[]'), [
        'new',
        'open',
    ]);
    await panel.getByRole('button', { name: 'Tutup', exact: true }).click();

    for (const width of [1280, 768, 375, 320]) {
        await page.setViewportSize({ width, height: 900 });

        for (const name of [
            'Ringkasan',
            'SLA',
            'Kategori & lokasi',
            'Kepuasan',
        ]) {
            await tab(name);
            await waitReport();
            const size = await page.evaluate(() => ({
                width: innerWidth,
                scroll: document.documentElement.scrollWidth,
            }));
            assert.ok(
                size.scroll <= size.width,
                `Overflow ${width} ${name}: ${JSON.stringify(size)}`,
            );
        }
    }

    if (process.env.REPORT_SCREENSHOT_DIR) {
        await page.screenshot({
            path: process.env.REPORT_SCREENSHOT_DIR + '/reports-mobile-app.png',
            fullPage: true,
        });
        await page.setViewportSize({ width: 1280, height: 900 });
        await tab('Ringkasan');
        await waitReport();
        await page.screenshot({
            path:
                process.env.REPORT_SCREENSHOT_DIR + '/reports-desktop-app.png',
            fullPage: true,
        });
    }

    assert.deepEqual(errors, []);
    console.log(
        'PASS: report tabs, active-ticket drilldown, pagination, Good/Bad reviews, XLSX multi-status filters, 320/375/768/1280px layouts. No production requests.',
    );
} catch (error) {
    console.error(
        'Browser failures:',
        errors,
        'API requests:',
        requests.map((url) => url.pathname),
    );
    console.error(await page.locator('body').ariaSnapshot());

    throw error;
} finally {
    await browser.close();
    await new Promise((resolve) => server.close(resolve));
}
