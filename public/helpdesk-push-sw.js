/* Dedicated /app/ worker. No API responses or authenticated pages are cached. */
const accountCache = 'helpdesk-push-account-v1';
const accountKey = '/__push_account__';

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) =>
    event.waitUntil(self.clients.claim()),
);

async function accountId() {
    const cache = await caches.open(accountCache);
    return (await cache.match(accountKey))?.text();
}

self.addEventListener('message', (event) => {
    if (event.data?.type !== 'PUSH_ACCOUNT') return;
    event.waitUntil(
        (async () => {
            const cache = await caches.open(accountCache);
            if (event.data.userId) {
                await cache.put(
                    accountKey,
                    new Response(String(event.data.userId)),
                );
            } else {
                await cache.delete(accountKey);
                for (const notification of await self.registration.getNotifications())
                    notification.close();
            }
            event.ports?.[0]?.postMessage({ ok: true });
        })(),
    );
});

function ticketUrl(value) {
    if (
        typeof value !== 'string' ||
        !/^\/app\/(pic|spv)\/tickets\/[a-zA-Z0-9-]+$/.test(value)
    )
        return null;
    const url = new URL(value, self.location.origin);
    return url.origin === self.location.origin ? url.href : null;
}

self.addEventListener('push', (event) => {
    event.waitUntil(
        (async () => {
            let payload;
            try {
                payload = event.data?.json();
            } catch {
                return;
            }
            if (
                !payload?.user_id ||
                payload.user_id !== (await accountId()) ||
                !ticketUrl(payload.url)
            )
                return;
            await self.registration.showNotification(payload.title, {
                body: payload.body,
                icon: '/pwa-192x192.png',
                badge: '/pwa-192x192.png',
                tag: payload.tag,
                renotify: true,
                data: { url: payload.url, user_id: payload.user_id },
            });
        })(),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    event.waitUntil(
        (async () => {
            const data = event.notification.data;
            const url = ticketUrl(data?.url);
            if (!url || data.user_id !== (await accountId())) return;
            const windows = await self.clients.matchAll({
                type: 'window',
                includeUncontrolled: true,
            });
            const existing = windows.find((client) => client.url === url);
            if (existing) await existing.focus();
            else await self.clients.openWindow(url);
        })(),
    );
});
