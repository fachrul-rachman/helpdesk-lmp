import axios from 'axios';
import type { AxiosError } from 'axios';
import type { AxiosInstance, AxiosRequestConfig } from 'axios';

import { getStoredRefreshToken, useAuthStore } from '../stores/authStore';

type RefreshResponse = {
    access_token: string;
};

type RetriableRequestConfig = AxiosRequestConfig & {
    _retry?: boolean;
    _sessionToken?: string | null;
};

export const api: AxiosInstance = axios.create({
    baseURL: import.meta.env.VITE_API_URL,
    headers: { 'Content-Type': 'application/json' },
});

api.interceptors.request.use((config) => {
    (config as RetriableRequestConfig)._sessionToken = getStoredRefreshToken();
    const token = useAuthStore.getState().accessToken;

    if (token) {
        config.headers = config.headers ?? {};
        config.headers.Authorization = `Bearer ${token}`;
    }

    return config;
});

const refreshClient = axios.create({
    baseURL: import.meta.env.VITE_API_URL,
    timeout: 15000,
    headers: { 'Content-Type': 'application/json' },
});
let refreshing: { token: string; promise: Promise<RefreshResponse> } | null =
    null;

export function refreshSession(): Promise<RefreshResponse> {
    const token = getStoredRefreshToken();

    if (!token) {
        return useAuthStore
            .getState()
            .clear()
            .then(() => {
                throw new Error('Sesi login sudah berakhir.');
            });
    }

    if (refreshing?.token === token) {
        return refreshing.promise;
    }

    const promise = (async () => {
        try {
            const { data } = await refreshClient.post<RefreshResponse>(
                '/api/auth/refresh',
                {
                    refresh_token: token,
                },
            );

            if (getStoredRefreshToken() !== token) {
                throw new Error('Sesi login telah berubah.');
            }

            if (!data.access_token || typeof data.access_token !== 'string') {
                throw new Error('Respons pembaruan login tidak valid.');
            }

            useAuthStore.getState().setAccessToken(data.access_token);
            useAuthStore.getState().setSessionError(null);

            return data;
        } catch (error) {
            // A delayed response must never clear or restore a different account's session.
            if (getStoredRefreshToken() === token) {
                const status = axios.isAxiosError(error)
                    ? error.response?.status
                    : undefined;

                if (status === 401 || status === 403) {
                    await useAuthStore.getState().clear();
                } else {
                    useAuthStore
                        .getState()
                        .setSessionError(
                            'Belum bisa menyambungkan kembali. Periksa koneksi Anda lalu coba lagi.',
                        );
                }
            }

            throw error;
        } finally {
            if (refreshing?.token === token) {
                refreshing = null;
            }
        }
    })();
    refreshing = { token, promise };

    return promise;
}

function redirectToLogin() {
    window.location.assign('/app/login');
}

api.interceptors.response.use(
    (response) => response,
    async (error: AxiosError) => {
        const status = error.response?.status;
        const originalConfig = error.config as
            | RetriableRequestConfig
            | undefined;

        const url = originalConfig?.url ?? '';
        const isAuthEndpoint =
            url.includes('/api/auth/login') ||
            url.includes('/api/auth/refresh') ||
            url.endsWith('/api/auth/login') ||
            url.endsWith('/api/auth/refresh');

        if (!originalConfig || status !== 401 || originalConfig._retry) {
            return Promise.reject(error);
        }

        if (isAuthEndpoint) {
            return Promise.reject(error);
        }

        originalConfig._retry = true;

        if (originalConfig._sessionToken !== getStoredRefreshToken()) {
            return Promise.reject(error);
        }

        try {
            const currentToken = useAuthStore.getState().accessToken;
            const accessToken =
                currentToken &&
                originalConfig.headers?.Authorization !==
                    `Bearer ${currentToken}`
                    ? currentToken
                    : (await refreshSession()).access_token;

            originalConfig.headers = originalConfig.headers ?? {};
            originalConfig.headers.Authorization = `Bearer ${accessToken}`;

            return api(originalConfig);
        } catch (refreshError) {
            if (!getStoredRefreshToken()) {
                redirectToLogin();
            }

            return Promise.reject(refreshError);
        }
    },
);
