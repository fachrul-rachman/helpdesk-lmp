import { Bell, BellOff } from 'lucide-react';
import { useEffect, useState } from 'react';

import { api } from '../../lib/axios';
import {
    applicationServerKey,
    bindPushAccount,
    clearLocalPush,
    pushOwner,
    pushRegistration,
    pushSupported,
} from '../../lib/pushRegistration';
import {
    getStoredRefreshToken,
    getStoredUser,
    useAuthStore,
} from '../../stores/authStore';
import { Button } from '../ui/button';

type PushConfig = { enabled: boolean; public_key: string | null };

export function PushNotifications() {
    const userId = useAuthStore((state) => state.user?.id);
    const [config, setConfig] = useState<PushConfig | null>(null);
    const [active, setActive] = useState(false);
    const [busy, setBusy] = useState(true);
    const [message, setMessage] = useState('');
    const supported = pushSupported();

    useEffect(() => {
        let canceled = false;
        async function sync() {
            try {
                const { data } = await api.get<PushConfig>('/api/push/config');

                if (canceled) {
                    return;
                }

                setConfig(data);

                if (!supported || !data.enabled) {
                    return;
                }

                const registration = await pushRegistration();
                const subscription =
                    await registration.pushManager.getSubscription();

                if (canceled) {
                    return;
                }

                if (
                    subscription &&
                    pushOwner() === userId &&
                    Notification.permission === 'granted' &&
                    data.public_key &&
                    Array.from(
                        new Uint8Array(
                            subscription.options.applicationServerKey ??
                                new ArrayBuffer(0),
                        ),
                    ).join(',') ===
                        Array.from(applicationServerKey(data.public_key)).join(
                            ',',
                        )
                ) {
                    await api.post('/api/push/subscriptions', {
                        ...subscription.toJSON(),
                        refresh_token: getStoredRefreshToken(),
                    });

                    if (!canceled) {
                        await bindPushAccount(registration, userId ?? null);
                        setActive(true);
                    }
                } else if (subscription) {
                    await clearLocalPush();
                }
            } catch {
                if (!canceled) {
                    setMessage(
                        'Status notifikasi belum dapat diperiksa. Coba muat ulang halaman.',
                    );
                }
            } finally {
                if (!canceled) {
                    setBusy(false);
                }
            }
        }
        void sync();

        return () => {
            canceled = true;
        };
    }, [supported, userId]);

    async function toggle() {
        setBusy(true);
        setMessage('');

        try {
            if (active) {
                const registration = await pushRegistration();
                const subscription =
                    await registration.pushManager.getSubscription();
                await clearLocalPush();
                setActive(false);

                if (subscription) {
                    await api.delete('/api/push/subscriptions', {
                        data: { endpoint: subscription.endpoint },
                    });
                }

                return;
            }

            // Must run directly from the user gesture (especially iOS).
            const permission = await Notification.requestPermission();

            if (permission !== 'granted') {
                setMessage(
                    'Izin notifikasi belum diberikan. Aktifkan melalui pengaturan browser/perangkat.',
                );

                return;
            }

            if (
                !userId ||
                getStoredUser()?.id !== userId ||
                !config?.public_key
            ) {
                return;
            }

            await clearLocalPush();
            const registration = await pushRegistration();
            const subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: applicationServerKey(config.public_key),
            });

            try {
                await api.post('/api/push/subscriptions', {
                    ...subscription.toJSON(),
                    refresh_token: getStoredRefreshToken(),
                });

                if (getStoredUser()?.id !== userId) {
                    await subscription.unsubscribe();

                    return;
                }

                await bindPushAccount(registration, userId);
                setActive(true);
            } catch (error) {
                await clearLocalPush();

                throw error;
            }
        } catch {
            setMessage(
                'Gagal memperbarui notifikasi. Periksa koneksi, lalu coba lagi.',
            );
        } finally {
            setBusy(false);
        }
    }

    if (config && !config.enabled) {
        return null;
    }

    return (
        <div className="border-b border-slate-200 bg-white px-4 py-2">
            <div className="flex flex-wrap items-center gap-2">
                <Button
                    type="button"
                    variant="secondary"
                    disabled={busy || !supported || !config?.enabled}
                    onClick={toggle}
                    aria-pressed={active}
                >
                    {active ? (
                        <Bell className="h-4 w-4" aria-hidden="true" />
                    ) : (
                        <BellOff className="h-4 w-4" aria-hidden="true" />
                    )}
                    {busy
                        ? 'Memeriksa notifikasi…'
                        : active
                          ? 'Nonaktifkan notifikasi'
                          : 'Aktifkan notifikasi'}
                </Button>
                {!supported && (
                    <span className="text-xs text-slate-600">
                        Gunakan browser yang mendukung melalui HTTPS. Di
                        iPhone/iPad, tambahkan aplikasi ke Layar Utama lalu buka
                        dari sana.
                    </span>
                )}
            </div>
            {message && (
                <p role="status" className="mt-1 text-xs text-slate-600">
                    {message}
                </p>
            )}
        </div>
    );
}
