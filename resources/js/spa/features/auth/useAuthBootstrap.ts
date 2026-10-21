import { useCallback, useEffect, useState } from 'react';

import { refreshSession } from '../../lib/axios';
import { getStoredRefreshToken, useAuthStore } from '../../stores/authStore';

export function useAuthBootstrap() {
    const accessToken = useAuthStore((s) => s.accessToken);
    const refreshToken = useAuthStore((s) => s.refreshToken);
    const sessionError = useAuthStore((s) => s.sessionError);

    const [isBootstrapping, setIsBootstrapping] = useState(true);
    const [isRetrying, setIsRetrying] = useState(false);
    const retry = useCallback(async () => {
        setIsRetrying(true);

        try {
            await refreshSession();
        } catch {
            // The shared refresh handler distinguishes expired sessions from temporary failures.
        } finally {
            setIsRetrying(false);
        }
    }, []);

    useEffect(() => {
        let isMounted = true;

        async function run() {
            if (accessToken) {
                if (isMounted) {
                    setIsBootstrapping(false);
                }

                return;
            }

            const token = refreshToken ?? getStoredRefreshToken();

            if (!token) {
                if (isMounted) {
                    setIsBootstrapping(false);
                }

                return;
            }

            try {
                await refreshSession();
            } catch {
                // Preserve saved login on network/server errors so the user can retry.
            } finally {
                if (isMounted) {
                    setIsBootstrapping(false);
                }
            }
        }

        run();

        return () => {
            isMounted = false;
        };
    }, [accessToken, refreshToken]);

    useEffect(() => {
        if (!sessionError || !refreshToken) {
            return;
        }

        const reconnect = () => {
            void retry();
        };
        window.addEventListener('online', reconnect);

        return () => window.removeEventListener('online', reconnect);
    }, [sessionError, refreshToken, retry]);

    return { isBootstrapping, sessionError, retry, isRetrying, accessToken };
}
