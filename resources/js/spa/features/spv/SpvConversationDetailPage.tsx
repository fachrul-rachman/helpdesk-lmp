import { useEffect, useRef, useState } from 'react';
import { Link, useParams } from 'react-router-dom';

import { PriorityBadge, StatusBadge } from '../../components/common/badges';
import { formatDateTimeId, formatTimeId } from '../../components/common/format';
import { PaperclipIcon } from '../../components/common/icons';
import { Button } from '../../components/ui/button';
import { api } from '../../lib/axios';
import { getEcho } from '../../lib/echo';
import { cn } from '../../lib/utils';
import type {
    Customer,
    TicketListItem,
    TicketMessage,
} from '../../types/ticket';

type ConversationMessage = TicketMessage & {
    ticket: Omit<TicketListItem, 'customer'> | null;
};

type ConversationDetail = {
    customer: Customer;
    messages: ConversationMessage[];
    tickets: Array<Omit<TicketListItem, 'customer'>>;
    active_ticket: Omit<TicketListItem, 'customer'> | null;
};

type ConversationDetailResponse = { data: ConversationDetail };

function bubbleAlign(senderType: TicketMessage['sender_type']) {
    return senderType === 'customer' ? 'items-start' : 'items-end';
}

function bubbleStyle(senderType: TicketMessage['sender_type']) {
    if (senderType === 'customer')
        return 'bg-white border border-slate-200 text-slate-900';
    if (senderType === 'ai') return 'bg-slate-100 text-slate-900';
    if (senderType === 'system') return 'bg-slate-100 text-slate-900';

    return 'bg-blue-50 text-slate-900';
}

function senderLabel(senderType: TicketMessage['sender_type']) {
    if (senderType === 'customer') return 'Customer';
    if (senderType === 'ai') return 'AI';
    if (senderType === 'system') return 'Sistem';

    return senderType.toUpperCase();
}

function attachmentLabel(message: ConversationMessage) {
    if (message.content) return message.content;
    if (message.attachments.length === 1)
        return message.attachments[0]?.file_name ?? 'Attachment';
    if (message.attachments.length > 1)
        return `${message.attachments.length} attachment`;

    return '';
}

export function SpvConversationDetailPage() {
    const { customerId } = useParams<{ customerId: string }>();
    const [detail, setDetail] = useState<ConversationDetail | null>(null);
    const [isLoading, setIsLoading] = useState(true);
    const [errorMessage, setErrorMessage] = useState<string | null>(null);
    const bottomRef = useRef<HTMLDivElement | null>(null);

    useEffect(() => {
        bottomRef.current?.scrollIntoView({ behavior: 'auto', block: 'end' });
    }, [detail?.messages.length]);

    async function load() {
        if (!customerId) return;
        setIsLoading(true);
        setErrorMessage(null);
        try {
            const res = await api.get<ConversationDetailResponse>(
                `/api/spv/customers/${customerId}/conversation`,
                {
                    params: { limit: 200 },
                },
            );
            setDetail(res.data.data);
        } catch (error: any) {
            const message =
                error?.response?.data?.message ??
                'Gagal memuat detail percakapan.';
            setErrorMessage(String(message));
        } finally {
            setIsLoading(false);
        }
    }

    useEffect(() => {
        let isMounted = true;

        async function loadMounted() {
            if (!customerId) return;
            setIsLoading(true);
            setErrorMessage(null);
            try {
                const res = await api.get<ConversationDetailResponse>(
                    `/api/spv/customers/${customerId}/conversation`,
                    {
                        params: { limit: 200 },
                    },
                );
                if (!isMounted) return;
                setDetail(res.data.data);
            } catch (error: any) {
                if (!isMounted) return;
                const message =
                    error?.response?.data?.message ??
                    'Gagal memuat detail percakapan.';
                setErrorMessage(String(message));
            } finally {
                if (!isMounted) return;
                setIsLoading(false);
            }
        }

        loadMounted();

        return () => {
            isMounted = false;
        };
    }, [customerId]);

    useEffect(() => {
        if (!customerId) return;

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
    }, [customerId]);

    if (isLoading) {
        return (
            <div className="text-sm text-slate-600">Memuat percakapan...</div>
        );
    }

    if (errorMessage) {
        return (
            <div className="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                {errorMessage}
            </div>
        );
    }

    if (!detail) {
        return (
            <div className="rounded-lg border border-slate-200 bg-white p-4 text-sm text-slate-600">
                Percakapan tidak ditemukan.
            </div>
        );
    }

    const customerName = detail.customer.name ?? 'Customer';
    const customerPhone = detail.customer.phone_number ?? '-';

    return (
        <div className="space-y-4">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div className="min-w-0">
                    <h1 className="truncate text-lg font-semibold text-slate-900">
                        {customerName}
                    </h1>
                    <div className="mt-1 text-sm text-slate-600">
                        {customerPhone}
                    </div>
                    {detail.customer.notes ? (
                        <div className="mt-2 max-w-3xl rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900 ring-1 ring-amber-200">
                            {detail.customer.notes}
                        </div>
                    ) : null}
                </div>
                <div className="flex flex-wrap gap-2">
                    {detail.active_ticket ? (
                        <Link to={`/spv/tickets/${detail.active_ticket.id}`}>
                            <Button>Lihat Ticket Aktif</Button>
                        </Link>
                    ) : null}
                    <Link to="/spv/tickets/create">
                        <Button variant="secondary">Buat Ticket</Button>
                    </Link>
                </div>
            </div>

            <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_380px] xl:items-start">
                <section className="rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
                    <div className="border-b border-slate-200 px-4 py-3">
                        <div className="text-sm font-semibold text-slate-900">
                            Timeline Percakapan
                        </div>
                        <div className="text-xs text-slate-600">
                            Menampilkan {detail.messages.length} pesan terakhir.
                        </div>
                    </div>

                    <div className="max-h-[68dvh] space-y-4 overflow-auto px-4 py-4">
                        {detail.messages.length === 0 ? (
                            <div className="text-sm text-slate-600">
                                Belum ada pesan.
                            </div>
                        ) : (
                            detail.messages.map((message) => (
                                <div
                                    key={message.id}
                                    className={cn(
                                        'flex flex-col',
                                        bubbleAlign(message.sender_type),
                                    )}
                                >
                                    <div className="mb-1 flex max-w-[85%] flex-wrap items-center gap-2 text-[11px] font-medium text-slate-500">
                                        <span>
                                            {message.sender?.name ??
                                                senderLabel(
                                                    message.sender_type,
                                                )}
                                        </span>
                                        {message.ticket ? (
                                            <Link
                                                to={`/spv/tickets/${message.ticket.id}`}
                                                className="rounded-full bg-blue-50 px-2 py-0.5 font-semibold text-blue-700 ring-1 ring-blue-200 hover:bg-blue-100"
                                            >
                                                {message.ticket.ticket_number ??
                                                    'Ticket'}
                                                : {message.ticket.subject}
                                            </Link>
                                        ) : null}
                                    </div>

                                    <div
                                        className={cn(
                                            'max-w-[85%] rounded-2xl px-3 py-2 text-sm shadow-sm',
                                            bubbleStyle(message.sender_type),
                                        )}
                                    >
                                        {attachmentLabel(message) ? (
                                            <div className="whitespace-pre-wrap">
                                                {attachmentLabel(message)}
                                            </div>
                                        ) : null}

                                        {message.attachments?.length ? (
                                            <div className="mt-2 space-y-2">
                                                {message.attachments.map(
                                                    (attachment) => (
                                                        <div
                                                            key={attachment.id}
                                                        >
                                                            {attachment.url &&
                                                            attachment.mime_type?.startsWith(
                                                                'image/',
                                                            ) ? (
                                                                <button
                                                                    type="button"
                                                                    className="block w-full overflow-hidden rounded-xl border border-slate-200 bg-white hover:bg-slate-50"
                                                                    onClick={() =>
                                                                        window.open(
                                                                            attachment.url!,
                                                                            '_blank',
                                                                            'noopener,noreferrer',
                                                                        )
                                                                    }
                                                                    title="Buka di tab baru"
                                                                >
                                                                    <img
                                                                        src={
                                                                            attachment.url
                                                                        }
                                                                        alt={
                                                                            attachment.file_name
                                                                        }
                                                                        className="h-auto max-h-64 w-full object-contain"
                                                                        loading="lazy"
                                                                    />
                                                                </button>
                                                            ) : (
                                                                <button
                                                                    type="button"
                                                                    className="flex w-full items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-left text-sm hover:bg-slate-50"
                                                                    onClick={async () => {
                                                                        try {
                                                                            const url =
                                                                                attachment.url
                                                                                    ? attachment.url
                                                                                    : (
                                                                                          await api.get<{
                                                                                              url: string;
                                                                                          }>(
                                                                                              `/api/attachments/${attachment.id}/url`,
                                                                                          )
                                                                                      )
                                                                                          .data
                                                                                          .url;
                                                                            window.open(
                                                                                url,
                                                                                '_blank',
                                                                                'noopener,noreferrer',
                                                                            );
                                                                        } catch {
                                                                            setErrorMessage(
                                                                                'Gagal membuka attachment.',
                                                                            );
                                                                        }
                                                                    }}
                                                                >
                                                                    <PaperclipIcon className="h-4 w-4 shrink-0 text-slate-500" />
                                                                    <span className="min-w-0">
                                                                        <span className="block truncate font-medium">
                                                                            {
                                                                                attachment.file_name
                                                                            }
                                                                        </span>
                                                                        <span className="block text-xs text-slate-500">
                                                                            {
                                                                                attachment.mime_type
                                                                            }
                                                                        </span>
                                                                    </span>
                                                                </button>
                                                            )}
                                                        </div>
                                                    ),
                                                )}
                                            </div>
                                        ) : null}

                                        <div className="mt-1 text-right text-[11px] text-slate-500">
                                            {message.created_at
                                                ? formatTimeId(
                                                      message.created_at,
                                                  )
                                                : '-'}
                                        </div>
                                    </div>
                                </div>
                            ))
                        )}
                        <div ref={bottomRef} />
                    </div>
                </section>

                <aside className="space-y-4">
                    <div className="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                        <div className="text-sm font-semibold text-slate-900">
                            Ticket Customer
                        </div>
                        <div className="mt-1 text-xs text-slate-600">
                            {detail.tickets.length} ticket tercatat.
                        </div>

                        {detail.tickets.length === 0 ? (
                            <div className="mt-4 text-sm text-slate-600">
                                Belum ada ticket.
                            </div>
                        ) : (
                            <div className="mt-4 space-y-3">
                                {detail.tickets.map((ticket) => (
                                    <Link
                                        key={ticket.id}
                                        to={`/spv/tickets/${ticket.id}`}
                                        className="block rounded-lg border border-slate-200 p-3 hover:bg-slate-50"
                                    >
                                        <div className="flex items-start justify-between gap-3">
                                            <div className="min-w-0">
                                                {ticket.ticket_number ? (
                                                    <div className="mb-1 text-[11px] font-semibold text-slate-500">
                                                        {ticket.ticket_number}
                                                    </div>
                                                ) : null}
                                                <div className="truncate text-sm font-semibold text-slate-900">
                                                    {ticket.subject}
                                                </div>
                                                <div className="mt-1 text-xs text-slate-600">
                                                    {ticket.division?.name ??
                                                        '-'}
                                                </div>
                                                <div className="mt-1 text-xs text-slate-600">
                                                    PIC/SPV:{' '}
                                                    {ticket.assigned_to?.name ??
                                                        '-'}
                                                </div>
                                            </div>
                                            <div className="shrink-0 text-right text-xs text-slate-500">
                                                {ticket.created_at
                                                    ? formatDateTimeId(
                                                          ticket.created_at,
                                                      )
                                                    : '-'}
                                            </div>
                                        </div>

                                        <div className="mt-3 flex flex-wrap gap-2">
                                            <StatusBadge
                                                status={ticket.status}
                                            />
                                            <PriorityBadge
                                                priority={ticket.priority}
                                            />
                                            {ticket.has_takeover_request ? (
                                                <span className="inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-800 ring-1 ring-amber-200">
                                                    Takeover
                                                </span>
                                            ) : null}
                                        </div>
                                    </Link>
                                ))}
                            </div>
                        )}
                    </div>
                </aside>
            </div>
        </div>
    );
}
