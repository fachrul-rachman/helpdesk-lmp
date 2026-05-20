import { useEffect, useState } from 'react';

import { getStoredRefreshToken, useAuthStore } from '../../stores/authStore';
import { api } from '../../lib/axios';

type RefreshResponse = {
    access_token: string;
    expires_in: number;
};

export function useAuthBootstrap() {
    const accessToken = useAuthStore((s) => s.accessToken);
    const refreshToken = useAuthStore((s) => s.refreshToken);
    const setAccessToken = useAuthStore((s) => s.setAccessToken);
    const clear = useAuthStore((s) => s.clear);

    const [isBootstrapping, setIsBootstrapping] = useState(true);

    useEffect(() => {
        let isMounted = true;

        async function run() {
            if (accessToken) {
                if (isMounted) setIsBootstrapping(false);
                return;
            }

            const token = refreshToken ?? getStoredRefreshToken();
            if (!token) {
                if (isMounted) setIsBootstrapping(false);
                return;
            }

            try {
                const response = await api.post<RefreshResponse>('/api/auth/refresh', {
                    refresh_token: token,
                });

                setAccessToken(response.data.access_token);
            } catch {
                clear();
            } finally {
                if (isMounted) setIsBootstrapping(false);
            }
        }

        run();

        return () => {
            isMounted = false;
        };
    }, [accessToken, refreshToken, setAccessToken, clear]);

    return { isBootstrapping };
}
