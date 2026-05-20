import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';

import { PriorityBadge, StatusBadge } from '../../components/common/badges';
import { formatDateTimeId } from '../../components/common/format';
import { api } from '../../lib/axios';
import type { ApiListResponse, TicketListItem } from '../../types/ticket';

export function PicHistoryPage() {
    const [items, setItems] = useState<TicketListItem[]>([]);
    const [isLoading, setIsLoading] = useState(true);
    const [errorMessage, setErrorMessage] = useState<string | null>(null);

    useEffect(() => {
        let isMounted = true;

        async function load() {
            setIsLoading(true);
            setErrorMessage(null);
            try {
                const response = await api.get<ApiListResponse<TicketListItem>>('/api/pic/tickets/history', {
                    params: { per_page: 100, page: 1 },
                });
                if (!isMounted) return;
                setItems(response.data.data ?? []);
            } catch (error: any) {
                if (!isMounted) return;
                const message =
                    error?.response?.data?.message ?? 'Gagal memuat riwayat ticket. Silakan coba lagi.';
                setErrorMessage(String(message));
            } finally {
                if (!isMounted) return;
                setIsLoading(false);
            }
        }

        load();
        return () => {
            isMounted = false;
        };
    }, []);

    return (
        <div>
            <h1 className="text-lg font-semibold text-slate-900">Riwayat Ticket</h1>

            {isLoading ? (
                <div className="mt-6 text-sm text-slate-600">Memuat riwayat...</div>
            ) : errorMessage ? (
                <div className="mt-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                    {errorMessage}
                </div>
            ) : items.length === 0 ? (
                <div className="mt-6 rounded-lg border border-slate-200 bg-white p-4 text-sm text-slate-600">
                    Belum ada ticket selesai.
                </div>
            ) : (
                <div className="mt-4 space-y-3">
                    {items.map((t) => (
                        <Link
                            key={t.id}
                            to={`/pic/tickets/${t.id}`}
                            className="block rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200 hover:ring-slate-300"
                        >
                            <div className="flex items-start justify-between gap-3">
                                <div className="min-w-0">
                                    {t.ticket_number ? (
                                        <div className="text-xs font-semibold text-slate-500">{t.ticket_number}</div>
                                    ) : null}
                                    <div className="truncate text-sm font-semibold text-slate-900">{t.subject}</div>
                                    <div className="mt-1 truncate text-xs text-slate-600">
                                        {t.customer?.name ?? t.customer?.phone_number ?? '-'}
                                        {t.division?.name ? ` - ${t.division.name}` : ''}
                                    </div>
                                </div>
                                <div className="shrink-0 text-right">
                                    <div className="text-xs text-slate-500">
                                        {formatDateTimeId(t.created_at)}
                                    </div>
                                </div>
                            </div>

                            <div className="mt-3 flex flex-wrap items-center gap-2">
                                <StatusBadge status={t.status} />
                                <PriorityBadge priority={t.priority} />
                            </div>
                        </Link>
                    ))}
                </div>
            )}
        </div>
    );
}
