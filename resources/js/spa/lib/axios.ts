import axios, { AxiosError, type AxiosInstance, type AxiosRequestConfig } from 'axios';

import { getStoredRefreshToken, useAuthStore } from '../stores/authStore';

type RefreshResponse = {
    access_token: string;
};

type RetriableRequestConfig = AxiosRequestConfig & { _retry?: boolean };

export const api: AxiosInstance = axios.create({
    baseURL: import.meta.env.VITE_API_URL,
    headers: { 'Content-Type': 'application/json' },
});

api.interceptors.request.use((config) => {
    const token = useAuthStore.getState().accessToken;
    if (token) {
        config.headers = config.headers ?? {};
        config.headers.Authorization = `Bearer ${token}`;
    }
    return config;
});

let refreshPromise: Promise<RefreshResponse> | null = null;

async function refreshTokens(): Promise<RefreshResponse> {
    const refreshToken = getStoredRefreshToken();
    if (!refreshToken) {
        throw new Error('Refresh token tidak ditemukan.');
    }

    const response = await axios.post<RefreshResponse>(
        `${import.meta.env.VITE_API_URL}/api/auth/refresh`,
        { refresh_token: refreshToken },
        { headers: { 'Content-Type': 'application/json' } }
    );

    return response.data;
}

function redirectToLogin() {
    window.location.assign('/app/login');
}

api.interceptors.response.use(
    (response) => response,
    async (error: AxiosError) => {
        const status = error.response?.status;
        const originalConfig = error.config as RetriableRequestConfig | undefined;

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

        try {
            refreshPromise ??= refreshTokens();
            const data = await refreshPromise;
            useAuthStore.getState().setAccessToken(data.access_token);
            refreshPromise = null;

            originalConfig.headers = originalConfig.headers ?? {};
            originalConfig.headers.Authorization = `Bearer ${data.access_token}`;
            return api(originalConfig);
        } catch (refreshError) {
            refreshPromise = null;
            useAuthStore.getState().clear();
            redirectToLogin();
            return Promise.reject(refreshError);
        }
    }
);
