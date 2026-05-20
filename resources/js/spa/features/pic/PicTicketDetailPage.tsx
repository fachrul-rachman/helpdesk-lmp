import { useEffect, useMemo, useRef, useState } from 'react';
import { useParams } from 'react-router-dom';

import { PriorityBadge, StatusBadge, type TicketStatus } from '../../components/common/badges';
import { formatDateTimeId, formatTimeId } from '../../components/common/format';
import { PaperclipIcon, SendIcon } from '../../components/common/icons';
import { Button } from '../../components/ui/button';
import { Textarea } from '../../components/ui/textarea';
import { api } from '../../lib/axios';
import { getEcho } from '../../lib/echo';
import { cn } from '../../lib/utils';
import { useAuthStore } from '../../stores/authStore';
import type { TicketDetail, TicketMessage } from '../../types/ticket';

type TicketDetailResponse = { data: TicketDetail };
type MessagesResponse = { data: TicketMessage[] };

function formatRemaining(deadlineIso: string | null | undefined) {
    if (!deadlineIso) return { label: '-', tone: 'muted' as const };
    const deadline = new Date(deadlineIso);
    if (Number.isNaN(deadline.getTime())) return { label: '-', tone: 'muted' as const };

    const now = Date.now();
    const diffMs = deadline.getTime() - now;
    const isOverdue = diffMs < 0;
    const absMs = Math.abs(diffMs);
    const totalMinutes = Math.floor(absMs / 60000);
    const hours = Math.floor(totalMinutes / 60);
    const minutes = totalMinutes % 60;
    const parts: string[] = [];
    if (hours > 0) parts.push(`${hours} jam`);
    parts.push(`${minutes} menit`);

    return {
        label: `${isOverdue ? 'Overdue' : 'Sisa'} ${parts.join(' ')}`,
        tone: isOverdue ? ('danger' as const) : ('ok' as const),
    };
}

function bubbleAlign(senderType: TicketMessage['sender_type']) {
    return senderType === 'customer' ? 'items-start' : 'items-end';
}

function bubbleStyle(senderType: TicketMessage['sender_type']) {
    if (senderType === 'customer') return 'bg-white border border-slate-200 text-slate-900';
    if (senderType === 'ai') return 'bg-slate-100 text-slate-900';
    return 'bg-blue-50 text-slate-900';
}

function senderLabel(senderType: TicketMessage['sender_type']) {
    if (senderType === 'customer') return 'Customer';
    if (senderType === 'ai') return 'AI';
    if (senderType === 'system') return 'Sistem';
    return senderType.toUpperCase();
}

export function PicTicketDetailPage() {
    const { id } = useParams<{ id: string }>();
    const currentUser = useAuthStore((s) => s.user);

    const [ticket, setTicket] = useState<TicketDetail | null>(null);
    const [messages, setMessages] = useState<TicketMessage[]>([]);
    const [isLoading, setIsLoading] = useState(true);
    const [errorMessage, setErrorMessage] = useState<string | null>(null);

    const [draft, setDraft] = useState('');
    const [isSending, setIsSending] = useState(false);
    const [sendError, setSendError] = useState<string | null>(null);
    const [files, setFiles] = useState<File[]>([]);

    const bottomRef = useRef<HTMLDivElement | null>(null);
    const fileInputRef = useRef<HTMLInputElement | null>(null);
    const messageBoxRef = useRef<HTMLTextAreaElement | null>(null);

    const [isSavingNotes, setIsSavingNotes] = useState(false);
    const [ticketNotesDraft, setTicketNotesDraft] = useState('');
    const [customerNotesDraft, setCustomerNotesDraft] = useState('');
    const [nextStatus, setNextStatus] = useState<TicketStatus | ''>('');
    const [isDrawerOpen, setIsDrawerOpen] = useState(false);
    const [takeoverReason, setTakeoverReason] = useState('');
    const [isTakeoverSubmitting, setIsTakeoverSubmitting] = useState(false);

    useEffect(() => {
        bottomRef.current?.scrollIntoView({ behavior: 'auto' });
    }, [messages.length]);

    useEffect(() => {
        let isMounted = true;

        async function load() {
            if (!id) return;
            setIsLoading(true);
            setErrorMessage(null);
            try {
                const [ticketRes, msgsRes] = await Promise.all([
                    api.get<TicketDetailResponse>(`/api/tickets/${id}`),
                    api.get<MessagesResponse>(`/api/tickets/${id}/messages`),
                ]);

                if (!isMounted) return;
                setTicket(ticketRes.data.data);
                setMessages(msgsRes.data.data ?? []);
                setTicketNotesDraft(ticketRes.data.data.notes ?? '');
                setCustomerNotesDraft(ticketRes.data.data.customer?.notes ?? '');
                setNextStatus('');
            } catch (error: any) {
                if (!isMounted) return;
                const message =
                    error?.response?.data?.message ??
                    'Gagal memuat detail ticket. Silakan coba lagi.';
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
    }, [id]);

    useEffect(() => {
        if (!id) return;

        const echo = getEcho();
        const channel = echo.private(`ticket.${id}`);

        const onMessageSent = (payload: any) => {
            const message = payload?.data as TicketMessage | undefined;
            if (!message?.id) return;
            setMessages((prev) => {
                if (prev.some((m) => m.id === message.id)) return prev;
                return [...prev, message];
            });
        };

        const onStatusChanged = (payload: any) => {
            const data = payload?.data as { ticket_id?: string; to_status?: TicketStatus } | undefined;
            if (!data?.ticket_id || data.ticket_id !== id || !data.to_status) return;
            setTicket((prev) => (prev ? { ...prev, status: data.to_status! } : prev));
        };

        channel.listen('.MessageSent', onMessageSent);
        channel.listen('.TicketStatusChanged', onStatusChanged);

        return () => {
            channel.stopListening('.MessageSent');
            channel.stopListening('.TicketStatusChanged');
            echo.leave(`ticket.${id}`);
        };
    }, [id]);

    const canReply = useMemo(() => {
        if (!ticket) return false;
        const isReadOnlyByTakeover =
            ticket.takeover_request?.status === 'approved' &&
            ticket.takeover_request.requested_by?.id &&
            currentUser?.id &&
            ticket.takeover_request.requested_by.id === currentUser.id;

        return (
            ticket.status !== 'queue' &&
            ticket.status !== 'solved' &&
            ticket.status !== 'closed' &&
            !isReadOnlyByTakeover
        );
    }, [ticket, currentUser]);

    const takeoverState = useMemo(() => {
        const req = ticket?.takeover_request ?? null;
        if (!req) return { status: null as null | 'pending' | 'approved', isRequester: false };
        const isRequester = !!(currentUser?.id && req.requested_by?.id === currentUser.id);
        return { status: req.status, isRequester };
    }, [ticket, currentUser]);

    const isReadOnly = takeoverState.status === 'approved' && takeoverState.isRequester;

    async function reloadTicketOnly() {
        if (!id) return;
        const ticketRes = await api.get<TicketDetailResponse>(`/api/tickets/${id}`);
        setTicket(ticketRes.data.data);
        setTicketNotesDraft(ticketRes.data.data.notes ?? '');
        setCustomerNotesDraft(ticketRes.data.data.customer?.notes ?? '');
    }

    async function handleRequestTakeover() {
        if (!id) return;
        setIsTakeoverSubmitting(true);
        setSendError(null);
        try {
            await api.post(`/api/pic/tickets/${id}/takeover-request`, { reason: takeoverReason });
            setTakeoverReason('');
            await reloadTicketOnly();
        } catch (error: any) {
            const message = error?.response?.data?.message ?? 'Gagal mengirim request takeover.';
            setSendError(String(message));
        } finally {
            setIsTakeoverSubmitting(false);
        }
    }

    async function handleCancelTakeover() {
        if (!id) return;
        setIsTakeoverSubmitting(true);
        setSendError(null);
        try {
            await api.post(`/api/pic/tickets/${id}/takeover-request/cancel`);
            await reloadTicketOnly();
        } catch (error: any) {
            const message = error?.response?.data?.message ?? 'Gagal membatalkan request takeover.';
            setSendError(String(message));
        } finally {
            setIsTakeoverSubmitting(false);
        }
    }

    const allowedTransitions = useMemo<TicketStatus[]>(() => {
        if (!ticket) return [] as TicketStatus[];
        if (ticket.status === 'open') return ['pending', 'on_progress', 'solved'];
        if (ticket.status === 'pending') return ['open'];
        if (ticket.status === 'on_progress') return ['open', 'solved'];
        return [] as TicketStatus[];
    }, [ticket]);

    const statusLabel: Record<TicketStatus, string> = {
        new: 'Baru',
        open: 'Open',
        pending: 'Pending',
        on_progress: 'On Progress',
        queue: 'Antrian',
        solved: 'Solved',
        closed: 'Closed',
    };

    async function handleSend() {
        if (!id) return;
        setSendError(null);
        const content = draft.trim();
        if (!content && files.length === 0) {
            setSendError('Pesan tidak boleh kosong.');
            return;
        }

        setIsSending(true);
        try {
            const form = new FormData();
            if (content) form.append('content', content);
            for (const f of files.slice(0, 5)) {
                form.append('attachments[]', f);
            }

            const res = await api.post<{ data: TicketMessage }>(`/api/tickets/${id}/messages`, form, {
                headers: { 'Content-Type': 'multipart/form-data' },
            });

            setDraft('');
            setFiles([]);
            setMessages((prev) => [...prev, res.data.data]);
        } catch (error: any) {
            const message =
                error?.response?.data?.message ?? 'Gagal mengirim pesan. Silakan coba lagi.';
            setSendError(String(message));
        } finally {
            setIsSending(false);
        }
    }

    async function handleChangeStatus(nextStatus: TicketStatus) {
        if (!id) return;
        if (!allowedTransitions.includes(nextStatus)) return;
        try {
            const res = await api.patch<TicketDetailResponse>(`/api/tickets/${id}/status`, {
                status: nextStatus,
            });
            setTicket(res.data.data);
        } catch (error: any) {
            const message =
                error?.response?.data?.message ?? 'Gagal mengubah status ticket.';
            setErrorMessage(String(message));
        }
    }

    async function handleSaveNotes() {
        if (!ticket) return;
        setIsSavingNotes(true);
        try {
            const ops: Array<Promise<any>> = [];

            if (nextStatus) {
                ops.push(
                    api.patch<TicketDetailResponse>(`/api/tickets/${ticket.id}/status`, {
                        status: nextStatus,
                    })
                );
            }

            ops.push(
                api.patch<TicketDetailResponse>(`/api/tickets/${ticket.id}/notes`, {
                    notes: ticketNotesDraft.trim() ? ticketNotesDraft.trim() : null,
                })
            );

            if (ticket.customer?.id) {
                ops.push(
                    api.patch<{ data: any }>(`/api/customers/${ticket.customer.id}/notes`, {
                        notes: customerNotesDraft.trim() ? customerNotesDraft.trim() : null,
                    })
                );
            }

            const results = await Promise.all(ops);
            const updatedTicket = results
                .map((r: any) => r?.data?.data)
                .find((d: any) => d && typeof d === 'object' && 'subject' in d) as TicketDetail | undefined;

            if (updatedTicket) {
                setTicket(updatedTicket);
            }

            setNextStatus('');
        } catch (error: any) {
            const message = error?.response?.data?.message ?? 'Gagal menyimpan catatan.';
            setErrorMessage(String(message));
        } finally {
            setIsSavingNotes(false);
        }
    }

    if (isLoading) {
        return <div className="text-sm text-slate-600">Memuat ticket...</div>;
    }

    if (errorMessage) {
        return (
            <div className="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                {errorMessage}
            </div>
        );
    }

    if (!ticket) {
        return (
            <div className="rounded-lg border border-slate-200 bg-white p-4 text-sm text-slate-600">
                Ticket tidak ditemukan.
            </div>
        );
    }

    const resolution = formatRemaining(ticket.sla?.resolution_deadline_at);
    const frTone =
        ticket.sla?.fr_status === 'overdue'
            ? 'danger'
            : ticket.sla?.fr_status === 'done'
              ? 'ok'
              : 'muted';

    return (
        <>
            <div className="grid gap-4 lg:grid-cols-[1fr_380px] lg:items-start">
            <div className="order-2 lg:order-1">
                <div className="rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
                <div className="border-b border-slate-200 px-4 py-3">
                    <div className="text-sm font-semibold text-slate-900">Percakapan</div>
                    <div className="text-xs text-slate-600">
                        Deadline resolusi:{' '}
                        {ticket.sla?.resolution_deadline_at
                            ? formatDateTimeId(ticket.sla.resolution_deadline_at)
                            : '-'}
                    </div>
                    <div className="mt-3 lg:hidden">
                        <Button variant="secondary" className="w-full" onClick={() => setIsDrawerOpen(true)}>
                            Lihat Detail
                        </Button>
                    </div>
                </div>

                <div className="max-h-[62dvh] space-y-3 overflow-auto px-4 py-4 lg:max-h-[58dvh]">
                    {messages.length === 0 ? (
                        <div className="text-sm text-slate-600">Belum ada pesan.</div>
                    ) : (
                        messages.map((m) => (
                            <div key={m.id} className={cn('flex flex-col', bubbleAlign(m.sender_type))}>
                                <div className="mb-1 text-[11px] font-medium text-slate-500">
                                    {m.sender?.name ?? senderLabel(m.sender_type)}
                                </div>
                                <div className={cn('max-w-[85%] rounded-2xl px-3 py-2 text-sm', bubbleStyle(m.sender_type))}>
                                    {m.content ? <div className="whitespace-pre-wrap">{m.content}</div> : null}
                                    {m.attachments?.length ? (
                                        <div className="mt-2 space-y-2">
                                            {m.attachments.map((a) => (
                                                <div key={a.id} className="space-y-2">
                                                    {a.url && a.mime_type?.startsWith('image/') ? (
                                                        <button
                                                            type="button"
                                                            className="block w-full overflow-hidden rounded-xl border border-slate-200 bg-white hover:bg-slate-50"
                                                            onClick={() => window.open(a.url!, '_blank', 'noopener,noreferrer')}
                                                            title="Buka di tab baru"
                                                        >
                                                            <img
                                                                src={a.url}
                                                                alt={a.file_name}
                                                                className="h-auto w-full max-h-64 object-contain"
                                                                loading="lazy"
                                                            />
                                                        </button>
                                                    ) : (
                                                        <button
                                                            type="button"
                                                            className="block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-left text-sm hover:bg-slate-50"
                                                            onClick={async () => {
                                                                try {
                                                                    const url = a.url
                                                                        ? a.url
                                                                        : (await api.get<{ url: string }>(
                                                                              `/api/attachments/${a.id}/url`
                                                                          )).data.url;
                                                                    window.open(url, '_blank', 'noopener,noreferrer');
                                                                } catch {
                                                                    setErrorMessage('Gagal membuka attachment.');
                                                                }
                                                            }}
                                                        >
                                                            <div className="truncate font-medium">{a.file_name}</div>
                                                            <div className="text-xs text-slate-500">{a.mime_type}</div>
                                                        </button>
                                                    )}
                                                </div>
                                            ))}
                                        </div>
                                    ) : null}
                                    <div className="mt-1 text-right text-[11px] text-slate-500">
                                        {formatTimeId(m.created_at)}
                                    </div>
                                </div>
                            </div>
                        ))
                    )}
                    <div ref={bottomRef} />
                </div>

                <div className="border-t border-slate-200 p-3">
                    {sendError ? (
                        <div className="mb-2 rounded-md border border-red-200 bg-red-50 p-2 text-sm text-red-700">
                            {sendError}
                        </div>
                    ) : null}

                    <div className="flex gap-2">
                        <input
                            ref={fileInputRef}
                            type="file"
                            className="hidden"
                            multiple
                            onChange={(e) => {
                                const selected = Array.from(e.target.files ?? []);
                                setFiles((prev) => [...prev, ...selected].slice(0, 5));
                                e.target.value = '';
                            }}
                            accept="image/jpeg,image/png,image/webp,video/mp4,video/3gpp,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                        />
                        <Button
                            type="button"
                            variant="secondary"
                            className="w-10 px-0"
                            onClick={() => fileInputRef.current?.click()}
                            disabled={!canReply || isSending}
                            aria-label="Tambah attachment"
                            title="Attachment"
                        >
                            <PaperclipIcon className="h-5 w-5" />
                        </Button>
                        <Textarea
                            ref={messageBoxRef}
                            rows={1}
                            className="min-h-10"
                            placeholder={canReply ? 'Ketik balasan...' : 'Ticket tidak bisa dibalas.'}
                            value={draft}
                            onChange={(e) => setDraft(e.target.value)}
                            disabled={!canReply || isSending}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                                    e.preventDefault();
                                    handleSend();
                                }
                            }}
                        />
                        <Button
                            type="button"
                            className="w-10 px-0"
                            onClick={handleSend}
                            disabled={!canReply || isSending}
                            aria-label="Kirim pesan"
                            title="Kirim"
                        >
                            <SendIcon className="h-5 w-5" />
                        </Button>
                    </div>

                    {files.length ? (
                        <div className="mt-2 flex flex-wrap gap-2">
                            {files.map((f, idx) => (
                                <button
                                    key={`${f.name}-${idx}`}
                                    type="button"
                                    className="rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-700 hover:bg-slate-200"
                                    onClick={() => setFiles((prev) => prev.filter((_, i) => i !== idx))}
                                    title="Klik untuk hapus"
                                >
                                    {f.name}
                                </button>
                            ))}
                        </div>
                    ) : null}
                </div>
            </div>
            </div>

        <div className="hidden space-y-4 lg:order-2 lg:block">
                <div className="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                    <div className="text-sm font-semibold text-slate-900">Data Customer</div>
                    <div className="mt-3 space-y-1 text-sm text-slate-700">
                        <div className="font-semibold text-slate-900">{ticket.customer?.name ?? 'Customer'}</div>
                        <div>{ticket.customer?.phone_number ?? '-'}</div>
                    </div>
                    <div className="mt-4">
                        <div className="text-xs font-semibold text-slate-700">Catatan Customer</div>
                        <Textarea
                            rows={3}
                            placeholder="Tulis catatan customer..."
                            value={customerNotesDraft}
                            onChange={(e) => setCustomerNotesDraft(e.target.value)}
                            disabled={!ticket.customer?.id}
                            className="mt-2"
                        />
                    </div>
                </div>

                <div className="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                    <div className="flex items-start justify-between gap-3">
                        <div className="min-w-0">
                            <div className="text-sm font-semibold text-slate-900">Data Ticket</div>
                            {ticket.ticket_number ? (
                                <div className="mt-2 text-xs font-semibold text-slate-500">{ticket.ticket_number}</div>
                            ) : null}
                            <div className="mt-2 truncate text-base font-semibold text-slate-900">{ticket.subject}</div>
                            <div className="mt-1 text-xs text-slate-600">{ticket.division?.name ?? '-'}</div>
                        </div>
                        <div className="shrink-0 text-right">
                            <div className="text-xs text-slate-500">{formatDateTimeId(ticket.created_at)}</div>
                        </div>
                    </div>

                    <div className="mt-3 flex flex-wrap items-center gap-2">
                        <StatusBadge status={ticket.status} />
                        <PriorityBadge priority={ticket.priority} />
                    </div>

                    <div className="mt-4 space-y-2">
                        <div className="text-xs font-semibold text-slate-700">Ubah Status</div>
                        {allowedTransitions.length === 0 ? (
                            <div className="text-sm text-slate-600">
                                {ticket.status === 'new'
                                    ? 'Status akan berubah menjadi Open saat Anda membalas pertama kali.'
                                    : 'Tidak ada perubahan status yang tersedia.'}
                            </div>
                        ) : (
                            <select
                                className="h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                                value={nextStatus}
                                onChange={(e) => setNextStatus((e.target.value as TicketStatus) || '')}
                                disabled={isReadOnly}
                            >
                                <option value="">(Tidak mengubah status)</option>
                                {allowedTransitions.map((s) => (
                                    <option key={s} value={s}>
                                        {statusLabel[s]}
                                    </option>
                                ))}
                            </select>
                        )}
                    </div>

                    <div className="mt-4">
                        <div className="text-xs font-semibold text-slate-700">Deskripsi / Catatan Ticket</div>
                        <Textarea
                            rows={4}
                            placeholder="Tulis deskripsi/catatan ticket..."
                            value={ticketNotesDraft}
                            onChange={(e) => setTicketNotesDraft(e.target.value)}
                            disabled={isReadOnly}
                            className="mt-2"
                        />
                    </div>
                </div>

                {['open', 'pending', 'on_progress'].includes(ticket.status) ? (
                    <div className="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                        <div className="text-sm font-semibold text-slate-900">Request Takeover</div>

                        {ticket.takeover_request ? (
                            <>
                                <div className="mt-2 text-xs text-slate-600">
                                    Status: <span className="font-semibold">{ticket.takeover_request.status}</span>
                                </div>
                                <div className="mt-2 whitespace-pre-wrap text-sm text-slate-800">
                                    {ticket.takeover_request.reason}
                                </div>

                                {ticket.takeover_request.status === 'pending' && takeoverState.isRequester ? (
                                    <Button
                                        className="mt-3 w-full"
                                        variant="secondary"
                                        onClick={handleCancelTakeover}
                                        disabled={isTakeoverSubmitting}
                                    >
                                        {isTakeoverSubmitting ? 'Memproses...' : 'Batalkan Request'}
                                    </Button>
                                ) : ticket.takeover_request.status === 'approved' && takeoverState.isRequester ? (
                                    <div className="mt-3 rounded-md border border-amber-200 bg-amber-50 p-2 text-sm text-amber-800">
                                        Ticket sedang diambil alih oleh SPV. Anda tidak bisa melakukan aksi sampai request ditutup.
                                    </div>
                                ) : null}
                            </>
                        ) : (
                            <>
                                <div className="mt-2 text-xs text-slate-600">
                                    Ajukan request takeover ke SPV jika Anda butuh bantuan atau sedang ada pekerjaan lain.
                                </div>
                                <Textarea
                                    rows={3}
                                    placeholder="Tulis alasan request takeover..."
                                    value={takeoverReason}
                                    onChange={(e) => setTakeoverReason(e.target.value)}
                                    className="mt-2"
                                    disabled={isTakeoverSubmitting || isReadOnly}
                                />
                                <Button
                                    className="mt-3 w-full"
                                    onClick={handleRequestTakeover}
                                    disabled={isTakeoverSubmitting || takeoverReason.trim() === '' || isReadOnly}
                                >
                                    {isTakeoverSubmitting ? 'Memproses...' : 'Request'}
                                </Button>
                            </>
                        )}
                    </div>
                ) : null}

                <div className="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                    <div className="text-sm font-semibold text-slate-900">SLA</div>
                    <div className="mt-3 space-y-2 text-sm">
                        <div className="flex items-center justify-between">
                            <div className="text-slate-600">Resolution</div>
                            <div
                                className={cn(
                                    'font-semibold',
                                    resolution.tone === 'danger' ? 'text-red-600' : 'text-slate-900'
                                )}
                            >
                                {resolution.label}
                            </div>
                        </div>
                        <div className="flex items-center justify-between">
                            <div className="text-slate-600">First Respond</div>
                            <div
                                className={cn(
                                    'font-semibold',
                                    frTone === 'danger'
                                        ? 'text-red-600'
                                        : frTone === 'ok'
                                          ? 'text-green-600'
                                          : 'text-slate-900'
                                )}
                            >
                                {ticket.sla?.fr_status === 'done'
                                    ? 'Done'
                                    : ticket.sla?.fr_status === 'overdue'
                                      ? 'Overdue'
                                      : ticket.sla?.fr_status ?? '-'}
                            </div>
                        </div>
                    </div>
                </div>

                <Button className="w-full" onClick={handleSaveNotes} disabled={isSavingNotes}>
                    {isSavingNotes ? 'Memperbarui...' : 'Update'}
                </Button>
            </div>
        </div>

        {isDrawerOpen ? (
            <div className="fixed inset-0 z-50 lg:hidden">
                <button
                    type="button"
                    className="absolute inset-0 bg-black/40"
                    aria-label="Tutup drawer"
                    onClick={() => setIsDrawerOpen(false)}
                />
                <div className="absolute inset-x-0 bottom-0 max-h-[85dvh] overflow-auto rounded-t-2xl bg-slate-50 p-4 shadow-xl">
                    <div className="mb-3 flex items-center justify-between">
                        <div className="text-sm font-semibold text-slate-900">Detail Ticket</div>
                        <Button variant="secondary" onClick={() => setIsDrawerOpen(false)}>
                            Tutup
                        </Button>
                    </div>

                    <div className="space-y-4">
                        <div className="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                            <div className="text-sm font-semibold text-slate-900">Data Customer</div>
                            <div className="mt-3 space-y-1 text-sm text-slate-700">
                                <div className="font-semibold text-slate-900">{ticket.customer?.name ?? 'Customer'}</div>
                                <div>{ticket.customer?.phone_number ?? '-'}</div>
                            </div>
                            <div className="mt-4">
                                <div className="text-xs font-semibold text-slate-700">Catatan Customer</div>
                        <Textarea
                            rows={3}
                            placeholder="Tulis catatan customer..."
                            value={customerNotesDraft}
                            onChange={(e) => setCustomerNotesDraft(e.target.value)}
                            disabled={!ticket.customer?.id || isReadOnly}
                            className="mt-2"
                        />
                            </div>
                        </div>

                        <div className="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                            <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <div className="text-sm font-semibold text-slate-900">Data Ticket</div>
                                        {ticket.ticket_number ? (
                                            <div className="mt-2 text-xs font-semibold text-slate-500">{ticket.ticket_number}</div>
                                        ) : null}
                                        <div className="mt-2 truncate text-base font-semibold text-slate-900">{ticket.subject}</div>
                                        <div className="mt-1 text-xs text-slate-600">{ticket.division?.name ?? '-'}</div>
                                    </div>
                                <div className="shrink-0 text-right">
                                    <div className="text-xs text-slate-500">{formatDateTimeId(ticket.created_at)}</div>
                                </div>
                            </div>

                            <div className="mt-3 flex flex-wrap items-center gap-2">
                                <StatusBadge status={ticket.status} />
                                <PriorityBadge priority={ticket.priority} />
                            </div>

                            <div className="mt-4 space-y-2">
                                <div className="text-xs font-semibold text-slate-700">Ubah Status</div>
                                {allowedTransitions.length === 0 ? (
                                    <div className="text-sm text-slate-600">
                                        {ticket.status === 'new'
                                            ? 'Status akan berubah menjadi Open saat Anda membalas pertama kali.'
                                            : 'Tidak ada perubahan status yang tersedia.'}
                                    </div>
                                ) : (
                                    <select
                                        className="h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                                        value={nextStatus}
                                        onChange={(e) => setNextStatus((e.target.value as TicketStatus) || '')}
                                    >
                                        <option value="">(Tidak mengubah status)</option>
                                        {allowedTransitions.map((s) => (
                                            <option key={s} value={s}>
                                                {statusLabel[s]}
                                            </option>
                                        ))}
                                    </select>
                                )}
                            </div>

                            <div className="mt-4">
                                <div className="text-xs font-semibold text-slate-700">Deskripsi / Catatan Ticket</div>
                                <Textarea
                                    rows={4}
                                    placeholder="Tulis deskripsi/catatan ticket..."
                                    value={ticketNotesDraft}
                                    onChange={(e) => setTicketNotesDraft(e.target.value)}
                                    className="mt-2"
                                />
                            </div>
                        </div>

                        <div className="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                            <div className="text-sm font-semibold text-slate-900">SLA</div>
                            <div className="mt-3 space-y-2 text-sm">
                                <div className="flex items-center justify-between">
                                    <div className="text-slate-600">Resolution</div>
                                    <div
                                        className={cn(
                                            'font-semibold',
                                            resolution.tone === 'danger' ? 'text-red-600' : 'text-slate-900'
                                        )}
                                    >
                                        {resolution.label}
                                    </div>
                                </div>
                                <div className="flex items-center justify-between">
                                    <div className="text-slate-600">First Respond</div>
                                    <div
                                        className={cn(
                                            'font-semibold',
                                            frTone === 'danger'
                                                ? 'text-red-600'
                                                : frTone === 'ok'
                                                  ? 'text-green-600'
                                                  : 'text-slate-900'
                                        )}
                                    >
                                        {ticket.sla?.fr_status === 'done'
                                            ? 'Done'
                                            : ticket.sla?.fr_status === 'overdue'
                                              ? 'Overdue'
                                              : ticket.sla?.fr_status ?? '-'}
                                    </div>
                                </div>
                            </div>
                        </div>

                        <Button className="w-full" onClick={handleSaveNotes} disabled={isSavingNotes || isReadOnly}>
                            {isSavingNotes ? 'Memperbarui...' : 'Update'}
                        </Button>
                    </div>
                </div>
            </div>
        ) : null}
        </>
    );
}
