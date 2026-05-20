import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';

import { formatDateTimeId } from '../../components/common/format';
import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
import { api } from '../../lib/axios';
import { getEcho } from '../../lib/echo';
import { cn } from '../../lib/utils';
import type { ApiListResponse } from '../../types/ticket';
import type { SpvConversationListItem } from '../../types/spv';

type ConversationsResponse = ApiListResponse<SpvConversationListItem>;

export function SpvConversationsPage() {
    const [items, setItems] = useState<SpvConversationListItem[]>([]);
    const [isLoading, setIsLoading] = useState(true);
    const [errorMessage, setErrorMessage] = useState<string | null>(null);

    const [search, setSearch] = useState('');
    const [hasTicket, setHasTicket] = useState<'all' | 'true' | 'false'>('all');

    async function load() {
        setIsLoading(true);
        setErrorMessage(null);
        try {
            const res = await api.get<ConversationsResponse>('/api/spv/conversations', {
                params: {
                    per_page: 50,
                    page: 1,
                    search: search.trim() || undefined,
                    has_ticket: hasTicket === 'all' ? undefined : hasTicket,
                },
            });
            setItems(res.data.data ?? []);
        } catch (error: any) {
            const message =
                error?.response?.data?.message ?? 'Gagal memuat percakapan.';
            setErrorMessage(String(message));
        } finally {
            setIsLoading(false);
        }
    }

    useEffect(() => {
        load();
        const echo = getEcho();
        const channel = echo.private('dashboard.spv');
        const handler = () => load();
        channel.listen('.AiConversationUpdated', handler);
        channel.listen('.MessageSent', handler);
        channel.listen('.TicketCreated', handler);
        return () => {
            channel.stopListening('.AiConversationUpdated');
            channel.stopListening('.MessageSent');
            channel.stopListening('.TicketCreated');
            echo.leave('dashboard.spv');
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    return (
        <div className="space-y-4">
            <div className="flex items-center justify-between gap-3">
                <h1 className="text-lg font-semibold text-slate-900">Percakapan Customer</h1>
            </div>

            <div className="rounded-xl bg-white p-3 ring-1 ring-slate-200">
                <div className="grid gap-2 sm:grid-cols-3">
                    <Input
                        placeholder="Cari nama atau nomor HP…"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                    />
                    <select
                        className="h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                        value={hasTicket}
                        onChange={(e) => setHasTicket(e.target.value as any)}
                    >
                        <option value="all">Semua</option>
                        <option value="true">Punya Ticket</option>
                        <option value="false">Tidak Punya Ticket</option>
                    </select>
                    <div className="sm:col-span-1 sm:justify-self-end">
                        <Button variant="secondary" onClick={load} disabled={isLoading} className="w-full sm:w-auto">
                            {isLoading ? 'Memuat…' : 'Terapkan'}
                        </Button>
                    </div>
                </div>
            </div>

            {isLoading ? (
                <div className="text-sm text-slate-600">Memuat percakapan…</div>
            ) : errorMessage ? (
                <div className="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                    {errorMessage}
                </div>
            ) : items.length === 0 ? (
                <div className="rounded-lg border border-slate-200 bg-white p-4 text-sm text-slate-600">
                    Tidak ada percakapan.
                </div>
            ) : (
                <div className="space-y-3">
                    {items.map((row) => {
                        const customer = row.customer;
                        return (
                            <Link
                                key={customer.id}
                                to={`/spv/conversations/${customer.id}`}
                                className={cn(
                                    'block rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200 hover:ring-slate-300'
                                )}
                            >
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <div className="truncate text-sm font-semibold text-slate-900">
                                            {customer.name ?? 'Customer'}
                                        </div>
                                        <div className="mt-1 truncate text-xs text-slate-600">
                                            {customer.phone_number ?? '-'}
                                        </div>
                                    </div>
                                    <div className="shrink-0 text-right">
                                        <div className="text-xs text-slate-500">
                                            {row.last_message?.created_at
                                                ? formatDateTimeId(row.last_message.created_at)
                                                : '-'}
                                        </div>
                                    </div>
                                </div>
                                <div className="mt-2 text-sm text-slate-700 line-clamp-2">
                                    {row.last_message?.content ?? 'Belum ada pesan.'}
                                </div>
                                {row.active_ticket ? (
                                    <div className="mt-3 rounded-lg bg-blue-50 p-3 text-xs text-blue-800">
                                        Ticket aktif: {row.active_ticket.subject}
                                    </div>
                                ) : null}
                            </Link>
                        );
                    })}
                </div>
            )}
        </div>
    );
}

