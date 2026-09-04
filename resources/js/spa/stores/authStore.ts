import { create } from 'zustand';

import type { AuthUser } from '../types/auth';
import { clearLocalPush } from '../lib/pushRegistration';

const refreshTokenKey = 'helpdesk_refresh_token';
const userKey = 'helpdesk_user';

type AuthState = {
    accessToken: string | null;
    refreshToken: string | null;
    user: AuthUser | null;
    setTokens: (params: { accessToken: string; refreshToken: string }) => void;
    setUser: (user: AuthUser | null) => void;
    setAccessToken: (token: string | null) => void;
    clear: () => Promise<void>;
};

export const useAuthStore = create<AuthState>((set) => ({
    accessToken: null,
    refreshToken: localStorage.getItem(refreshTokenKey),
    user: (() => {
        const raw = localStorage.getItem(userKey);
        if (!raw) return null;
        try {
            return JSON.parse(raw) as AuthUser;
        } catch {
            localStorage.removeItem(userKey);
            return null;
        }
    })(),
    setTokens: ({ accessToken, refreshToken }) => {
        localStorage.setItem(refreshTokenKey, refreshToken);
        set({ accessToken, refreshToken });
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
        set({ accessToken: null, refreshToken: null, user: null });
        // Clear the worker account before navigating away, including expired sessions.
        await clearLocalPush().catch(() => undefined);
    },
}));

export function getStoredRefreshToken() {
    return localStorage.getItem(refreshTokenKey);
}

export function getStoredUser(): AuthUser | null {
    const raw = localStorage.getItem(userKey);
    if (!raw) return null;
    try {
        return JSON.parse(raw) as AuthUser;
    } catch {
        localStorage.removeItem(userKey);
        return null;
    }
}
