import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';

import { PriorityBadge, StatusBadge } from '../../components/common/badges';
import { formatDateTimeId } from '../../components/common/format';
import { Button } from '../../components/ui/button';
import { api } from '../../lib/axios';
import type { TicketListItem } from '../../types/ticket';

type CustomerTicketsResponse = { data: TicketListItem[] };
type ConversationsResponse = {
    data: Array<{
        customer: { id: string; name: string | null; phone_number: string | null };
        last_message: { content: string; sender_type: string; created_at: string } | null;
        active_ticket: { id: string; status: string; subject: string } | null;
    }>;
};

export function SpvConversationDetailPage() {
    const { customerId } = useParams<{ customerId: string }>();
    const [customer, setCustomer] = useState<{ id: string; name: string | null; phone_number: string | null } | null>(
        null
    );
    const [tickets, setTickets] = useState<TicketListItem[]>([]);
    const [activeTicketId, setActiveTicketId] = useState<string | null>(null);

    const [isLoading, setIsLoading] = useState(true);
    const [errorMessage, setErrorMessage] = useState<string | null>(null);

    useEffect(() => {
        let isMounted = true;
        async function load() {
            if (!customerId) return;
            setIsLoading(true);
            setErrorMessage(null);
            try {
                const [conv, list] = await Promise.all([
                    api.get<ConversationsResponse>('/api/spv/conversations', {
                        params: { per_page: 1, page: 1, search: customerId },
                    }),
                    api.get<CustomerTicketsResponse>(`/api/spv/customers/${customerId}/tickets`),
                ]);

                if (!isMounted) return;
                const found = conv.data.data.find((x) => x.customer.id === customerId);
                setCustomer(found?.customer ?? { id: customerId, name: null, phone_number: null });
                setActiveTicketId(found?.active_ticket?.id ?? null);
                setTickets(list.data.data ?? []);
            } catch (error: any) {
                if (!isMounted) return;
                const message =
                    error?.response?.data?.message ?? 'Gagal memuat detail percakapan.';
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
    }, [customerId]);

    if (isLoading) {
        return <div className="text-sm text-slate-600">Memuat percakapan…</div>;
    }

    if (errorMessage) {
        return (
            <div className="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                {errorMessage}
            </div>
        );
    }

    return (
        <div className="space-y-4">
            <div className="flex items-start justify-between gap-3">
                <div>
                    <h1 className="text-lg font-semibold text-slate-900">
                        {customer?.name ?? 'Customer'}
                    </h1>
                    <div className="text-sm text-slate-600">{customer?.phone_number ?? '-'}</div>
                </div>
                {activeTicketId ? (
                    <Link to={`/spv/tickets/${activeTicketId}`}>
                        <Button>Lihat Ticket Aktif</Button>
                    </Link>
                ) : null}
            </div>

            <div className="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                <div className="text-sm font-semibold text-slate-900">Daftar Ticket Customer</div>
                {tickets.length === 0 ? (
                    <div className="mt-3 text-sm text-slate-600">Belum ada ticket.</div>
                ) : (
                    <div className="mt-3 space-y-3">
                        {tickets.map((t) => (
                            <Link
                                key={t.id}
                                to={`/spv/tickets/${t.id}`}
                                className="block rounded-lg border border-slate-200 p-3 hover:bg-slate-50"
                            >
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <div className="truncate text-sm font-semibold text-slate-900">
                                            {t.subject}
                                        </div>
                                        <div className="mt-1 text-xs text-slate-600">
                                            {t.division?.name ?? '-'}
                                        </div>
                                    </div>
                                    <div className="shrink-0 text-xs text-slate-500">
                                        {formatDateTimeId(t.created_at)}
                                    </div>
                                </div>
                                <div className="mt-2 flex flex-wrap gap-2">
                                    <StatusBadge status={t.status} />
                                    <PriorityBadge priority={t.priority} />
                                </div>
                            </Link>
                        ))}
                    </div>
                )}
            </div>
        </div>
    );
}

