import { create } from 'zustand';

import { clearLocalPush } from '../lib/pushRegistration';
import type { AuthUser } from '../types/auth';

const refreshTokenKey = 'helpdesk_refresh_token';
const userKey = 'helpdesk_user';

type AuthState = {
    accessToken: string | null;
    refreshToken: string | null;
    user: AuthUser | null;
    sessionError: string | null;
    setSessionError: (message: string | null) => void;
    setTokens: (params: { accessToken: string; refreshToken: string }) => void;
    setUser: (user: AuthUser | null) => void;
    setAccessToken: (token: string | null) => void;
    clear: () => Promise<void>;
};

export const useAuthStore = create<AuthState>((set) => ({
    accessToken: null,
    sessionError: null,
    setSessionError: (sessionError) => set({ sessionError }),
    refreshToken: localStorage.getItem(refreshTokenKey),
    user: (() => {
        const raw = localStorage.getItem(userKey);

        if (!raw) {
            return null;
        }

        try {
            return JSON.parse(raw) as AuthUser;
        } catch {
            localStorage.removeItem(userKey);

            return null;
        }
    })(),
    setTokens: ({ accessToken, refreshToken }) => {
        localStorage.setItem(refreshTokenKey, refreshToken);
        set({ accessToken, refreshToken, sessionError: null });
    },
    setUser: (user) => {
        if (!user) {
            localStorage.removeItem(userKey);
            set({ user: null });

            return;
        }

        localStorage.setItem(userKey, JSON.stringify(user));
        set({ user });
    },
    setAccessToken: (token) => set({ accessToken: token }),
    clear: async () => {
        localStorage.removeItem(refreshTokenKey);
        localStorage.removeItem(userKey);
        set({
            accessToken: null,
            refreshToken: null,
            user: null,
            sessionError: null,
        });
        // Clear the worker account before navigating away, including expired sessions.
        await clearLocalPush().catch(() => undefined);
    },
}));

export function getStoredRefreshToken() {
    return localStorage.getItem(refreshTokenKey);
}

export function getStoredUser(): AuthUser | null {
    const raw = localStorage.getItem(userKey);

    if (!raw) {
        return null;
    }

    try {
        return JSON.parse(raw) as AuthUser;
    } catch {
        localStorage.removeItem(userKey);

        return null;
    }
}
