const ownerKey = 'helpdesk_push_owner';

export function pushSupported() {
    return (
        window.isSecureContext &&
        'serviceWorker' in navigator &&
        'PushManager' in window &&
        'Notification' in window
    );
}

export function pushOwner() {
    return localStorage.getItem(ownerKey);
}

export async function pushRegistration() {
    const registration = await navigator.serviceWorker.register(
        '/helpdesk-push-sw.js',
        { scope: '/app/', updateViaCache: 'none' },
    );

    if (!registration.active) {
        await new Promise<void>((resolve, reject) => {
            const worker = registration.installing ?? registration.waiting;
            const timer = setTimeout(() => {
                worker?.removeEventListener('statechange', check);
                reject(new Error('Service worker belum siap. Coba lagi.'));
            }, 10000);
            function check() {
                if (registration.active) {
                    clearTimeout(timer);
                    worker?.removeEventListener('statechange', check);
                    resolve();
                }
            }
            worker?.addEventListener('statechange', check);
            check();
        });
    }

    return registration;
}

export async function bindPushAccount(
    registration: ServiceWorkerRegistration,
    userId: string | null,
) {
    await new Promise<void>((resolve, reject) => {
        const channel = new MessageChannel();
        const timer = setTimeout(() => {
            channel.port1.close();
            reject(new Error('Gagal memperbarui akun notifikasi.'));
        }, 5000);
        channel.port1.onmessage = () => {
            clearTimeout(timer);
            channel.port1.close();
            resolve();
        };
        registration.active?.postMessage({ type: 'PUSH_ACCOUNT', userId }, [
            channel.port2,
        ]);
    });

    if (userId) {
        localStorage.setItem(ownerKey, userId);
    } else {
        localStorage.removeItem(ownerKey);
    }
}

export async function clearLocalPush() {
    localStorage.removeItem(ownerKey);

    // Clear persistent routing even if a worker update/unsubscribe request fails.
    if ('caches' in window) {
        const cache = await caches.open('helpdesk-push-account-v1');
        await cache.delete('/__push_account__');
    }

    if (!('serviceWorker' in navigator)) {
        return;
    }

    const registration = await navigator.serviceWorker.getRegistration('/app/');

    if (!registration?.active?.scriptURL.endsWith('/helpdesk-push-sw.js')) {
        return;
    }

    await bindPushAccount(registration, null);
    const subscription = await registration.pushManager.getSubscription();

    if (subscription && !(await subscription.unsubscribe())) {
        throw new Error('Gagal menonaktifkan notifikasi perangkat.');
    }
}

export function applicationServerKey(value: string): Uint8Array<ArrayBuffer> {
    const raw = atob(
        value
            .replace(/-/g, '+')
            .replace(/_/g, '/')
            .padEnd(Math.ceil(value.length / 4) * 4, '='),
    );

    return Uint8Array.from(raw, (char) => char.charCodeAt(0));
}
