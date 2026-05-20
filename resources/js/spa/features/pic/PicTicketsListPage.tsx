import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';

import { PriorityBadge, StatusBadge, type TicketStatus } from '../../components/common/badges';
import { formatDateTimeId } from '../../components/common/format';
import { api } from '../../lib/axios';
import { cn } from '../../lib/utils';
import { useAuthStore } from '../../stores/authStore';
import type { ApiListResponse, TicketListItem } from '../../types/ticket';

type TabKey = 'aktif' | 'selesai';

const activeStatuses: TicketStatus[] = ['new', 'open', 'pending', 'on_progress', 'queue'];
const doneStatuses: TicketStatus[] = ['solved', 'closed'];

function isActiveStatus(status: TicketStatus) {
    return activeStatuses.includes(status);
}

function isDoneStatus(status: TicketStatus) {
    return doneStatuses.includes(status);
}

export function PicTicketsListPage() {
    const user = useAuthStore((s) => s.user);

    const [tab, setTab] = useState<TabKey>('aktif');
    const [items, setItems] = useState<TicketListItem[]>([]);
    const [isLoading, setIsLoading] = useState(true);
    const [errorMessage, setErrorMessage] = useState<string | null>(null);

    useEffect(() => {
        let isMounted = true;

        async function load() {
            setIsLoading(true);
            setErrorMessage(null);
            try {
                const response = await api.get<ApiListResponse<TicketListItem>>('/api/tickets', {
                    params: {
                        per_page: 100,
                        page: 1,
                        assigned_to: user?.id ?? undefined,
                    },
                });

                if (!isMounted) return;
                setItems(response.data.data ?? []);
            } catch (error: any) {
                if (!isMounted) return;
                const message =
                    error?.response?.data?.message ?? 'Gagal memuat daftar ticket. Silakan coba lagi.';
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
    }, [user?.id]);

    const filtered = useMemo(() => {
        if (tab === 'aktif') return items.filter((t) => isActiveStatus(t.status));
        return items.filter((t) => isDoneStatus(t.status));
    }, [items, tab]);

    return (
        <div>
            <div className="flex items-center justify-between gap-3">
                <h1 className="text-lg font-semibold text-slate-900">Ticket Saya</h1>
            </div>

            <div className="mt-4 grid grid-cols-2 gap-2 rounded-xl bg-white p-2 ring-1 ring-slate-200">
                <button
                    type="button"
                    className={cn(
                        'rounded-lg px-3 py-2 text-sm font-semibold',
                        tab === 'aktif' ? 'bg-blue-600 text-white' : 'text-slate-700 hover:bg-slate-50'
                    )}
                    onClick={() => setTab('aktif')}
                >
                    Aktif
                </button>
                <button
                    type="button"
                    className={cn(
                        'rounded-lg px-3 py-2 text-sm font-semibold',
                        tab === 'selesai' ? 'bg-blue-600 text-white' : 'text-slate-700 hover:bg-slate-50'
                    )}
                    onClick={() => setTab('selesai')}
                >
                    Selesai
                </button>
            </div>

            {isLoading ? (
                <div className="mt-6 text-sm text-slate-600">Memuat ticket...</div>
            ) : errorMessage ? (
                <div className="mt-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                    {errorMessage}
                </div>
            ) : filtered.length === 0 ? (
                <div className="mt-6 rounded-lg border border-slate-200 bg-white p-4 text-sm text-slate-600">
                    {tab === 'aktif' ? 'Tidak ada ticket aktif.' : 'Belum ada ticket selesai.'}
                </div>
            ) : (
                <div className="mt-4 space-y-3">
                    {filtered.map((t) => (
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
