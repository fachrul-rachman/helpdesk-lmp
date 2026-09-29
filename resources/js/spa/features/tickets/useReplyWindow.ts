import { useCallback, useEffect, useState } from 'react';

import type { ReplyWindow } from '../../types/ticket';

export function useReplyWindow() {
    const [replyWindow, setReplyWindow] = useState<ReplyWindow | null>(null);

    useEffect(() => {
        if (!replyWindow?.is_open || !replyWindow.expires_at) return;

        const delay = new Date(replyWindow.expires_at).getTime() - Date.now();
        if (delay <= 0) {
            setReplyWindow((current) =>
                current ? { ...current, is_open: false } : current,
            );
            return;
        }

        const timeout = window.setTimeout(
            () =>
                setReplyWindow((current) =>
                    current ? { ...current, is_open: false } : current,
                ),
            delay,
        );

        return () => window.clearTimeout(timeout);
    }, [replyWindow]);

    const openFromCustomerMessage = useCallback((createdAt: string | null) => {
        const receivedAt = createdAt ? new Date(createdAt) : new Date();
        setReplyWindow({
            is_open: true,
            last_customer_message_at: receivedAt.toISOString(),
            expires_at: new Date(
                receivedAt.getTime() + 24 * 60 * 60 * 1000,
            ).toISOString(),
        });
    }, []);

    return { replyWindow, setReplyWindow, openFromCustomerMessage };
}
