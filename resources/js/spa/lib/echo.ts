import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

import { useAuthStore } from '../stores/authStore';

(window as unknown as { Pusher?: typeof Pusher }).Pusher = Pusher;

let echoInstance: Echo<any> | null = null;

function buildEcho() {
    const token = useAuthStore.getState().accessToken;

    return new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: Number(import.meta.env.VITE_REVERB_PORT),
        wssPort: Number(import.meta.env.VITE_REVERB_PORT),
        forceTLS: import.meta.env.VITE_REVERB_SCHEME === 'https',
        enabledTransports: ['ws', 'wss'],
        authEndpoint: `${import.meta.env.VITE_API_URL || window.location.origin}/api/broadcasting/auth`,
        auth: {
            headers: {
                ...(token ? { Authorization: `Bearer ${token}` } : {}),
            },
        },
    });
}

export function getEcho() {
    echoInstance ??= buildEcho();
    return echoInstance;
}

export function resetEcho() {
    if (!echoInstance) return;
    echoInstance.disconnect();
    echoInstance = null;
}
