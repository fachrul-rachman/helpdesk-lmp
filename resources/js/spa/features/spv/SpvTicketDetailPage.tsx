import { Fragment, useEffect, useMemo, useRef, useState } from 'react';
import { useParams } from 'react-router-dom';

import {
    PriorityBadge,
    StatusBadge,
    type TicketPriority,
    type TicketStatus,
} from '../../components/common/badges';
import { ChatDateSeparator } from '../../components/common/ChatDateSeparator';
import { CollapsibleCard } from '../../components/common/CollapsibleCard';
import {
    formatDateId,
    formatDateTimeId,
    formatSlaRemaining,
    formatTimeId,
} from '../../components/common/format';
import { PaperclipIcon, SendIcon } from '../../components/common/icons';
import { TicketLocationFields } from '../../components/common/TicketLocationFields';
import { TicketSubcategoryFields } from '../../components/common/TicketSubcategoryFields';
import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
import { Textarea } from '../../components/ui/textarea';
import { api } from '../../lib/axios';
import { getEcho } from '../../lib/echo';
import { cn } from '../../lib/utils';
import { useAuthStore } from '../../stores/authStore';
import type { TicketDetail, TicketMessage } from '../../types/ticket';

type TicketDetailResponse = { data: TicketDetail };
type MessagesResponse = { data: TicketMessage[] };

type DivisionOption = {
    id: string;
    name: string;
    is_active: boolean;
    is_fallback: boolean;
};
type PicOption = { id: string; name: string; phone_number: string };

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

function formatFileSize(size: number) {
    if (!Number.isFinite(size) || size <= 0) return '-';
    if (size < 1024) return `${size} B`;
    if (size < 1024 * 1024) return `${(size / 1024).toFixed(1)} KB`;
    return `${(size / (1024 * 1024)).toFixed(1)} MB`;
}

export function SpvTicketDetailPage() {
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

    const [divisions, setDivisions] = useState<DivisionOption[]>([]);
    const [pics, setPics] = useState<PicOption[]>([]);

    const [ticketNotesDraft, setTicketNotesDraft] = useState('');
    const [customerNotesDraft, setCustomerNotesDraft] = useState('');
    const [nextStatus, setNextStatus] = useState<TicketStatus | ''>('');
    const [nextPriority, setNextPriority] = useState<TicketPriority | ''>('');
    const [nextDivisionId, setNextDivisionId] = useState<string>('');
    const [nextPicId, setNextPicId] = useState<string>('');
    const [globalSubcategoryId, setGlobalSubcategoryId] = useState('');
    const [divisionSubcategoryId, setDivisionSubcategoryId] = useState('');
    const [site, setSite] = useState('');
    const [zone, setZone] = useState('');
    const [lotNumber, setLotNumber] = useState('');
    const [subjectDraft, setSubjectDraft] = useState('');
    const [isDrawerOpen, setIsDrawerOpen] = useState(false);
    const [isUpdating, setIsUpdating] = useState(false);
    const [isHandlingTakeover, setIsHandlingTakeover] = useState(false);

    useEffect(() => {
        bottomRef.current?.scrollIntoView({ behavior: 'auto' });
    }, [messages.length]);

    useEffect(() => {
        const el = messageBoxRef.current;
        if (!el) return;
        el.style.height = '0px';
        el.style.height = `${Math.min(el.scrollHeight, 200)}px`;
    }, [draft]);

    useEffect(() => {
        let isMounted = true;
        async function load() {
            if (!id) return;
            setIsLoading(true);
            setErrorMessage(null);
            try {
                const [ticketRes, msgsRes, divRes] = await Promise.all([
                    api.get<TicketDetailResponse>(`/api/tickets/${id}`),
                    api.get<MessagesResponse>(`/api/tickets/${id}/messages`),
                    api.get<{ data: DivisionOption[] }>('/api/divisions'),
                ]);

                if (!isMounted) return;
                setTicket(ticketRes.data.data);
                setMessages(msgsRes.data.data ?? []);
                setDivisions(divRes.data.data ?? []);

                setTicketNotesDraft(ticketRes.data.data.notes ?? '');
                setCustomerNotesDraft(
                    ticketRes.data.data.customer?.notes ?? '',
                );
                setNextStatus('');
                setNextPriority('');
                setNextDivisionId('');
                setNextPicId('');
                setGlobalSubcategoryId(
                    ticketRes.data.data.global_subcategory?.id ?? '',
                );
                setDivisionSubcategoryId(
                    ticketRes.data.data.division_subcategory?.id ?? '',
                );
                setSite(ticketRes.data.data.site ?? '');
                setZone(ticketRes.data.data.zone ?? '');
                setLotNumber(ticketRes.data.data.lot_number ?? '');
                setSubjectDraft(ticketRes.data.data.subject);
            } catch (error: any) {
                if (!isMounted) return;
                const message =
                    error?.response?.data?.message ??
                    'Gagal memuat detail ticket.';
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
            setMessages((prev) =>
                prev.some((m) => m.id === message.id)
                    ? prev
                    : [...prev, message],
            );
        };

        const onStatusChanged = (payload: any) => {
            const data = payload?.data as
                | { ticket_id?: string; to_status?: TicketStatus }
                | undefined;
            if (!data?.ticket_id || data.ticket_id !== id || !data.to_status)
                return;
            setTicket((prev) =>
                prev ? { ...prev, status: data.to_status! } : prev,
            );
        };

        channel.listen('.MessageSent', onMessageSent);
        channel.listen('.TicketStatusChanged', onStatusChanged);

        return () => {
            channel.stopListening('.MessageSent');
            channel.stopListening('.TicketStatusChanged');
            echo.leave(`ticket.${id}`);
        };
    }, [id]);

    const resolution = formatSlaRemaining(ticket?.sla?.resolution_deadline_at);
    const frTone =
        ticket?.sla?.fr_status === 'overdue'
            ? 'danger'
            : ticket?.sla?.fr_status === 'done'
              ? 'ok'
              : 'muted';

    const canReply = useMemo(() => {
        if (!ticket || !currentUser) return false;
        if (ticket.status === 'solved' || ticket.status === 'closed')
            return false;

        if (ticket.takeover_request?.status === 'approved') {
            return true;
        }

        return (
            ticket.assigned_to?.role === 'spv' &&
            ticket.assigned_to?.id === currentUser.id
        );
    }, [ticket, currentUser]);

    const allowedTransitions = useMemo<TicketStatus[]>(() => {
        if (!ticket) return [] as TicketStatus[];
        if (ticket.status === 'open')
            return ['pending', 'on_progress', 'solved'];
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

    async function handleApproveTakeover() {
        if (!id) return;
        setIsHandlingTakeover(true);
        try {
            await api.post(`/api/spv/tickets/${id}/takeover-request/approve`);
            const [ticketRes, msgsRes] = await Promise.all([
                api.get<TicketDetailResponse>(`/api/tickets/${id}`),
                api.get<MessagesResponse>(`/api/tickets/${id}/messages`),
            ]);
            setTicket(ticketRes.data.data);
            setMessages(msgsRes.data.data ?? []);
        } catch (error: any) {
            const message =
                error?.response?.data?.message ??
                'Gagal approve request takeover.';
            setErrorMessage(String(message));
        } finally {
            setIsHandlingTakeover(false);
        }
    }

    async function handleRejectTakeover() {
        if (!id) return;
        setIsHandlingTakeover(true);
        try {
            await api.post(`/api/spv/tickets/${id}/takeover-request/reject`);
            const ticketRes = await api.get<TicketDetailResponse>(
                `/api/tickets/${id}`,
            );
            setTicket(ticketRes.data.data);
        } catch (error: any) {
            const message =
                error?.response?.data?.message ??
                'Gagal reject request takeover.';
            setErrorMessage(String(message));
        } finally {
            setIsHandlingTakeover(false);
        }
    }

    async function handleCloseTakeover() {
        if (!id) return;
        setIsHandlingTakeover(true);
        try {
            await api.post(`/api/spv/tickets/${id}/takeover-request/close`);
            const ticketRes = await api.get<TicketDetailResponse>(
                `/api/tickets/${id}`,
            );
            setTicket(ticketRes.data.data);
        } catch (error: any) {
            const message =
                error?.response?.data?.message ??
                'Gagal close request takeover.';
            setErrorMessage(String(message));
        } finally {
            setIsHandlingTakeover(false);
        }
    }

    async function loadPics(divisionId: string) {
        setPics([]);
        if (!divisionId) return;
        try {
            const res = await api.get<{ data: PicOption[] }>('/api/spv/pics', {
                params: { division_id: divisionId },
            });
            setPics(res.data.data ?? []);
        } catch {
            setPics([]);
        }
    }

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
            for (const f of files.slice(0, 5)) form.append('attachments[]', f);

            const res = await api.post<{ data: TicketMessage }>(
                `/api/tickets/${id}/messages`,
                form,
                {
                    headers: { 'Content-Type': 'multipart/form-data' },
                },
            );

            setDraft('');
            setFiles([]);
            setMessages((prev) => [...prev, res.data.data]);
        } catch (error: any) {
            const message =
                error?.response?.data?.message ?? 'Gagal mengirim pesan.';
            setSendError(String(message));
        } finally {
            setIsSending(false);
        }
    }

    async function handleUpdate() {
        if (!ticket) return;
        if (!subjectDraft.trim()) {
            setErrorMessage('Judul ticket wajib diisi.');
            return;
        }
        setIsUpdating(true);
        setErrorMessage(null);
        try {
            // Urutan: status -> division -> assign -> priority -> notes
            if (nextStatus) {
                const res = await api.patch<TicketDetailResponse>(
                    `/api/tickets/${ticket.id}/status`,
                    {
                        status: nextStatus,
                    },
                );
                setTicket(res.data.data);
            }

            if (nextDivisionId) {
                const res = await api.patch<TicketDetailResponse>(
                    `/api/tickets/${ticket.id}/division`,
                    {
                        division_id: nextDivisionId,
                        assigned_to: nextPicId || null,
                    },
                );
                setTicket(res.data.data);
            }

            if (nextPicId && !nextDivisionId) {
                const res = await api.patch<TicketDetailResponse>(
                    `/api/tickets/${ticket.id}/assign`,
                    {
                        user_id: nextPicId,
                    },
                );
                setTicket(res.data.data);
            }

            if (nextPriority) {
                const res = await api.patch<TicketDetailResponse>(
                    `/api/tickets/${ticket.id}/priority`,
                    {
                        priority: nextPriority,
                    },
                );
                setTicket(res.data.data);
            }

            const subcategoryRes = await api.patch<TicketDetailResponse>(
                `/api/tickets/${ticket.id}/subcategories`,
                {
                    global_subcategory_id: globalSubcategoryId || null,
                    division_subcategory_id: divisionSubcategoryId || null,
                },
            );
            setTicket(subcategoryRes.data.data);

            const locationRes = await api.patch<TicketDetailResponse>(
                `/api/tickets/${ticket.id}/location`,
                {
                    site: site.trim() || null,
                    zone: zone.trim() || null,
                    lot_number: lotNumber.trim() || null,
                },
            );
            setTicket(locationRes.data.data);

            const subjectRes = await api.patch<TicketDetailResponse>(
                `/api/tickets/${ticket.id}/subject`,
                {
                    subject: subjectDraft.trim(),
                },
            );
            setTicket(subjectRes.data.data);

            const [ticketNotesRes] = await Promise.all([
                api.patch<TicketDetailResponse>(
                    `/api/tickets/${ticket.id}/notes`,
                    {
                        notes: ticketNotesDraft.trim()
                            ? ticketNotesDraft.trim()
                            : null,
                    },
                ),
                ticket.customer?.id
                    ? api.patch(`/api/customers/${ticket.customer.id}/notes`, {
                          notes: customerNotesDraft.trim()
                              ? customerNotesDraft.trim()
                              : null,
                      })
                    : Promise.resolve(null),
            ]);

            if (ticketNotesRes?.data?.data) setTicket(ticketNotesRes.data.data);
            setNextStatus('');
            setNextPriority('');
            setNextDivisionId('');
            setNextPicId('');
            setIsDrawerOpen(false);
        } catch (error: any) {
            const message =
                error?.response?.data?.message ?? 'Gagal memperbarui ticket.';
            setErrorMessage(String(message));
        } finally {
            setIsUpdating(false);
        }
    }

    if (isLoading)
        return <div className="text-sm text-slate-600">Memuat ticket...</div>;
    if (errorMessage)
        return (
            <div className="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                {errorMessage}
            </div>
        );
    if (!ticket)
        return (
            <div className="rounded-lg border border-slate-200 bg-white p-4 text-sm text-slate-600">
                Ticket tidak ditemukan.
            </div>
        );

    const DetailPanel = (
        <div className="space-y-4">
            <CollapsibleCard title="Data Customer">
                <div className="space-y-1 text-sm text-slate-700">
                    <div className="font-semibold text-slate-900">
                        {ticket.customer?.name ?? 'Customer'}
                    </div>
                    <div>{ticket.customer?.phone_number ?? '-'}</div>
                </div>
                <div className="mt-4">
                    <div className="text-xs font-semibold text-slate-700">
                        Catatan Customer
                    </div>
                    <Textarea
                        rows={3}
                        placeholder="Tulis catatan customer..."
                        value={customerNotesDraft}
                        onChange={(e) => setCustomerNotesDraft(e.target.value)}
                        disabled={!ticket.customer?.id || isUpdating}
                        className="mt-2"
                    />
                </div>
            </CollapsibleCard>

            <CollapsibleCard title="Data Ticket" defaultOpen>
                <div className="mb-2 text-xs font-semibold tracking-wide text-slate-500 uppercase">
                    Ringkasan
                </div>
                <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                        {ticket.ticket_number ? (
                            <div className="text-xs font-semibold text-slate-500">
                                {ticket.ticket_number}
                            </div>
                        ) : null}
                        <Input
                            aria-label="Judul Ticket"
                            value={subjectDraft}
                            onChange={(e) => setSubjectDraft(e.target.value)}
                            disabled={isUpdating}
                            maxLength={500}
                            className="mt-2 font-semibold"
                        />
                        <div className="mt-1 text-xs text-slate-600">
                            {ticket.division?.name ?? '-'}
                        </div>
                        <div className="mt-1 text-xs text-slate-600">
                            PIC/SPV: {ticket.assigned_to?.name ?? '-'}
                        </div>
                    </div>
                    <div className="shrink-0 text-right">
                        <div className="text-xs text-slate-500">
                            {formatDateTimeId(ticket.created_at)}
                        </div>
                    </div>
                </div>

                <div className="mt-3 flex flex-wrap items-center gap-2">
                    <StatusBadge status={ticket.status} />
                    <PriorityBadge priority={ticket.priority} />
                </div>

                <div className="mt-5 border-t border-slate-200 pt-4">
                    <div className="text-xs font-semibold tracking-wide text-slate-500 uppercase">
                        Penanganan
                    </div>

                    <div className="mt-3 space-y-2">
                        <div className="text-xs font-semibold text-slate-700">
                            Ubah Status
                        </div>
                        {allowedTransitions.length === 0 ? (
                            <div className="text-sm text-slate-600">
                                {ticket.status === 'new'
                                    ? 'Status akan berubah menjadi Open saat ada respons pertama.'
                                    : 'Tidak ada perubahan status yang tersedia.'}
                            </div>
                        ) : (
                            <select
                                className="h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none"
                                value={nextStatus}
                                onChange={(e) =>
                                    setNextStatus(
                                        (e.target.value as TicketStatus) || '',
                                    )
                                }
                                disabled={isUpdating}
                            >
                                <option value="">
                                    (Tidak mengubah status)
                                </option>
                                {allowedTransitions.map((s) => (
                                    <option key={s} value={s}>
                                        {statusLabel[s]}
                                    </option>
                                ))}
                            </select>
                        )}
                    </div>

                    {ticket.takeover_request ? (
                        <div className="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-3">
                            <div className="flex items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <div className="text-sm font-semibold text-slate-900">
                                        Request Takeover
                                    </div>
                                    <div className="mt-1 text-xs text-slate-600">
                                        Dari:{' '}
                                        {ticket.takeover_request.requested_by
                                            ?.name ?? '-'}{' '}
                                        ({ticket.takeover_request.status})
                                    </div>
                                </div>
                                <div className="shrink-0">
                                    <span
                                        className={cn(
                                            'rounded-full px-2 py-1 text-xs font-semibold',
                                            ticket.takeover_request.status ===
                                                'approved'
                                                ? 'bg-emerald-50 text-emerald-700'
                                                : 'bg-amber-50 text-amber-800',
                                        )}
                                    >
                                        {ticket.takeover_request.status ===
                                        'approved'
                                            ? 'Approved'
                                            : 'Pending'}
                                    </span>
                                </div>
                            </div>

                            <div className="mt-2 text-sm whitespace-pre-wrap text-slate-800">
                                {ticket.takeover_request.reason}
                            </div>

                            <div className="mt-3 flex flex-wrap gap-2">
                                {ticket.takeover_request.status ===
                                'pending' ? (
                                    <>
                                        <Button
                                            onClick={handleApproveTakeover}
                                            disabled={isHandlingTakeover}
                                        >
                                            {isHandlingTakeover
                                                ? 'Memproses...'
                                                : 'Approve'}
                                        </Button>
                                        <Button
                                            variant="secondary"
                                            onClick={handleRejectTakeover}
                                            disabled={isHandlingTakeover}
                                        >
                                            Reject
                                        </Button>
                                    </>
                                ) : (
                                    <Button
                                        variant="secondary"
                                        onClick={handleCloseTakeover}
                                        disabled={isHandlingTakeover}
                                    >
                                        {isHandlingTakeover
                                            ? 'Memproses...'
                                            : 'Close Request'}
                                    </Button>
                                )}
                            </div>
                        </div>
                    ) : null}

                    <div className="mt-4 space-y-2">
                        <div className="text-xs font-semibold text-slate-700">
                            Ubah Prioritas
                        </div>
                        <select
                            className="h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none"
                            value={nextPriority}
                            onChange={(e) =>
                                setNextPriority((e.target.value as any) || '')
                            }
                            disabled={isUpdating}
                        >
                            <option value="">(Tidak mengubah prioritas)</option>
                            <option value="high">Tinggi</option>
                            <option value="medium">Sedang</option>
                            <option value="low">Rendah</option>
                        </select>
                    </div>

                    <div className="mt-4 space-y-2">
                        <div className="text-xs font-semibold text-slate-700">
                            Pindah Divisi
                        </div>
                        <select
                            className="h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none"
                            value={nextDivisionId}
                            onChange={(e) => {
                                const v = e.target.value;
                                setNextDivisionId(v);
                                setNextPicId('');
                                setDivisionSubcategoryId(
                                    v
                                        ? ''
                                        : (ticket.division_subcategory?.id ??
                                              ''),
                                );
                                if (v) loadPics(v);
                            }}
                            disabled={isUpdating}
                        >
                            <option value="">(Tidak mengubah divisi)</option>
                            {divisions.map((d) => (
                                <option key={d.id} value={d.id}>
                                    {d.name}
                                    {d.is_fallback ? ' (Fallback)' : ''}
                                    {!d.is_active && !d.is_fallback
                                        ? ' (Nonaktif)'
                                        : ''}
                                </option>
                            ))}
                        </select>
                    </div>

                    <div className="mt-4 space-y-2">
                        <div className="text-xs font-semibold text-slate-700">
                            Assign PIC
                        </div>
                        <select
                            className="h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none"
                            value={nextPicId}
                            onChange={(e) => setNextPicId(e.target.value)}
                            disabled={isUpdating}
                        >
                            <option value="">(Tidak mengubah PIC)</option>
                            {pics.map((p) => (
                                <option key={p.id} value={p.id}>
                                    {p.name} ({p.phone_number})
                                </option>
                            ))}
                        </select>
                        {!nextDivisionId && ticket.division?.id ? (
                            <div className="text-xs text-slate-600">
                                Untuk assign cepat, pilih divisi di atas (atau
                                gunakan tombol “Update” setelah pilih PIC).
                            </div>
                        ) : null}
                    </div>
                </div>

                <div className="mt-5 border-t border-slate-200 pt-4">
                    <div className="text-xs font-semibold tracking-wide text-slate-500 uppercase">
                        Klasifikasi &amp; Lokasi
                    </div>

                    <div className="mt-3">
                        <TicketLocationFields
                            site={site}
                            zone={zone}
                            lotNumber={lotNumber}
                            onSiteChange={setSite}
                            onZoneChange={setZone}
                            onLotNumberChange={setLotNumber}
                            disabled={isUpdating}
                        />
                    </div>

                    <div className="mt-4">
                        <TicketSubcategoryFields
                            divisionId={nextDivisionId || ticket.division?.id}
                            globalValue={globalSubcategoryId}
                            divisionValue={divisionSubcategoryId}
                            currentGlobal={ticket.global_subcategory}
                            currentDivision={
                                nextDivisionId
                                    ? null
                                    : ticket.division_subcategory
                            }
                            onGlobalChange={setGlobalSubcategoryId}
                            onDivisionChange={setDivisionSubcategoryId}
                            disabled={isUpdating}
                        />
                    </div>
                </div>

                <div className="mt-5 border-t border-slate-200 pt-4">
                    <div className="text-xs font-semibold tracking-wide text-slate-500 uppercase">
                        Catatan
                    </div>
                    <div className="mt-3">
                        <div className="text-xs font-semibold text-slate-700">
                            Catatan Ticket/Action
                        </div>
                        <Textarea
                            rows={4}
                            placeholder="Tulis deskripsi/catatan ticket..."
                            value={ticketNotesDraft}
                            onChange={(e) =>
                                setTicketNotesDraft(e.target.value)
                            }
                            className="mt-2"
                            disabled={isUpdating}
                        />
                    </div>
                </div>
            </CollapsibleCard>

            <CollapsibleCard title="SLA">
                <div className="space-y-2 text-sm">
                    <div className="flex items-center justify-between">
                        <div className="text-slate-600">Resolution</div>
                        <div
                            className={cn(
                                'font-semibold',
                                resolution.tone === 'danger'
                                    ? 'text-red-600'
                                    : 'text-slate-900',
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
                                      : 'text-slate-900',
                            )}
                        >
                            {ticket.sla?.fr_status === 'done'
                                ? 'Done'
                                : ticket.sla?.fr_status === 'overdue'
                                  ? 'Overdue'
                                  : (ticket.sla?.fr_status ?? '-')}
                        </div>
                    </div>
                </div>
            </CollapsibleCard>

            <Button
                className="sticky bottom-0 w-full"
                onClick={handleUpdate}
                disabled={isUpdating}
            >
                {isUpdating ? 'Memperbarui…' : 'Update'}
            </Button>
        </div>
    );

    return (
        <>
            <div className="grid gap-4 lg:grid-cols-[1fr_380px] lg:items-start">
                <div className="order-1">
                    <div className="rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
                        <div className="border-b border-slate-200 px-4 py-3">
                            <div className="text-sm font-semibold text-slate-900">
                                Percakapan
                            </div>
                            <div className="text-xs text-slate-600">
                                Deadline resolusi:{' '}
                                {ticket.sla?.resolution_deadline_at
                                    ? formatDateTimeId(
                                          ticket.sla.resolution_deadline_at,
                                      )
                                    : '-'}
                            </div>
                            <div className="mt-3 lg:hidden">
                                <Button
                                    variant="secondary"
                                    className="w-full"
                                    onClick={() => setIsDrawerOpen(true)}
                                >
                                    Lihat Detail
                                </Button>
                            </div>
                        </div>

                        <div className="max-h-[62dvh] space-y-3 overflow-auto px-4 py-4 lg:max-h-[58dvh]">
                            {messages.length === 0 ? (
                                <div className="text-sm text-slate-600">
                                    Belum ada pesan.
                                </div>
                            ) : (
                                messages.map((m, index) => (
                                    <Fragment key={m.id}>
                                        {index === 0 ||
                                        formatDateId(
                                            messages[index - 1].created_at,
                                        ) !== formatDateId(m.created_at) ? (
                                            <ChatDateSeparator
                                                date={m.created_at}
                                            />
                                        ) : null}
                                        <div
                                            className={cn(
                                                'flex flex-col',
                                                bubbleAlign(m.sender_type),
                                            )}
                                        >
                                            <div className="mb-1 text-[11px] font-medium text-slate-500">
                                                {m.sender?.name ??
                                                    senderLabel(m.sender_type)}
                                            </div>
                                            <div
                                                className={cn(
                                                    'max-w-[85%] rounded-2xl px-3 py-2 text-sm',
                                                    bubbleStyle(m.sender_type),
                                                )}
                                            >
                                                {m.content ? (
                                                    <div className="whitespace-pre-wrap">
                                                        {m.content}
                                                    </div>
                                                ) : null}
                                                {m.attachments?.length ? (
                                                    <div className="mt-2 space-y-2">
                                                        {m.attachments.map(
                                                            (a) => (
                                                                <div
                                                                    key={a.id}
                                                                    className="space-y-2"
                                                                >
                                                                    {a.url &&
                                                                    a.mime_type?.startsWith(
                                                                        'image/',
                                                                    ) ? (
                                                                        <button
                                                                            type="button"
                                                                            className="block w-full overflow-hidden rounded-xl border border-slate-200 bg-white hover:bg-slate-50"
                                                                            onClick={() =>
                                                                                window.open(
                                                                                    a.url!,
                                                                                    '_blank',
                                                                                    'noopener,noreferrer',
                                                                                )
                                                                            }
                                                                            title="Buka di tab baru"
                                                                        >
                                                                            <img
                                                                                src={
                                                                                    a.url
                                                                                }
                                                                                alt={
                                                                                    a.file_name
                                                                                }
                                                                                className="h-auto max-h-64 w-full object-contain"
                                                                                loading="lazy"
                                                                            />
                                                                        </button>
                                                                    ) : (
                                                                        <button
                                                                            type="button"
                                                                            className="block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-left text-sm hover:bg-slate-50"
                                                                            onClick={async () => {
                                                                                try {
                                                                                    const url =
                                                                                        a.url
                                                                                            ? a.url
                                                                                            : (
                                                                                                  await api.get<{
                                                                                                      url: string;
                                                                                                  }>(
                                                                                                      `/api/attachments/${a.id}/url`,
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
                                                                            <div className="truncate font-medium">
                                                                                {
                                                                                    a.file_name
                                                                                }
                                                                            </div>
                                                                            <div className="text-xs text-slate-500">
                                                                                {
                                                                                    a.mime_type
                                                                                }
                                                                            </div>
                                                                        </button>
                                                                    )}
                                                                </div>
                                                            ),
                                                        )}
                                                    </div>
                                                ) : null}
                                                <div className="mt-1 text-right text-[11px] text-slate-500">
                                                    {formatTimeId(m.created_at)}
                                                </div>
                                            </div>
                                        </div>
                                    </Fragment>
                                ))
                            )}
                            <div ref={bottomRef} />
                        </div>

                        <div className="border-t border-slate-200 p-3">
                            {!canReply ? (
                                <div className="mb-2 rounded-md border border-amber-200 bg-amber-50 p-2 text-sm text-amber-800">
                                    Anda hanya bisa membalas ticket fallback
                                    (assigned ke SPV).
                                </div>
                            ) : null}
                            {sendError ? (
                                <div className="mb-2 rounded-md border border-red-200 bg-red-50 p-2 text-sm text-red-700">
                                    {sendError}
                                </div>
                            ) : null}

                            {files.length ? (
                                <div className="mb-3 space-y-2">
                                    {files.map((f, idx) => (
                                        <div
                                            key={`${f.name}-${idx}`}
                                            className="flex items-start justify-between gap-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2"
                                        >
                                            <div className="min-w-0">
                                                <div className="truncate text-sm font-medium text-slate-800">
                                                    {f.name}
                                                </div>
                                                <div className="text-xs text-slate-500">
                                                    {f.type || 'File'} •{' '}
                                                    {formatFileSize(f.size)}
                                                </div>
                                            </div>
                                            <button
                                                type="button"
                                                className="shrink-0 rounded-md px-2 py-1 text-xs font-medium text-slate-500 hover:bg-slate-200 hover:text-slate-700"
                                                onClick={() =>
                                                    setFiles((prev) =>
                                                        prev.filter(
                                                            (_, i) => i !== idx,
                                                        ),
                                                    )
                                                }
                                                title="Hapus file"
                                            >
                                                Hapus
                                            </button>
                                        </div>
                                    ))}
                                </div>
                            ) : null}

                            <div className="flex gap-2">
                                <input
                                    ref={fileInputRef}
                                    type="file"
                                    className="hidden"
                                    multiple
                                    onChange={(e) => {
                                        const selected = Array.from(
                                            e.target.files ?? [],
                                        );
                                        setFiles((prev) =>
                                            [...prev, ...selected].slice(0, 5),
                                        );
                                        e.target.value = '';
                                    }}
                                    accept="image/jpeg,image/png,image/webp,video/mp4,video/3gpp,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                                />
                                <Button
                                    type="button"
                                    variant="secondary"
                                    className="w-10 px-0"
                                    onClick={() =>
                                        fileInputRef.current?.click()
                                    }
                                    disabled={!canReply || isSending}
                                    aria-label="Tambah attachment"
                                    title="Attachment"
                                >
                                    <PaperclipIcon className="h-5 w-5" />
                                </Button>
                                <Textarea
                                    ref={messageBoxRef}
                                    rows={3}
                                    className="max-h-[200px] min-h-[88px] resize-y overflow-y-auto"
                                    placeholder={
                                        canReply
                                            ? 'Ketik balasan...'
                                            : 'Ticket tidak bisa dibalas.'
                                    }
                                    value={draft}
                                    onChange={(e) => setDraft(e.target.value)}
                                    disabled={!canReply || isSending}
                                    onKeyDown={(e) => {
                                        if (
                                            e.key === 'Enter' &&
                                            (e.ctrlKey || e.metaKey)
                                        ) {
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
                        </div>
                    </div>
                </div>

                <div className="hidden lg:order-2 lg:block lg:max-h-[calc(100dvh-140px)] lg:overflow-auto lg:pr-1">
                    {DetailPanel}
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
                            <div className="text-sm font-semibold text-slate-900">
                                Detail Ticket
                            </div>
                            <Button
                                variant="secondary"
                                onClick={() => setIsDrawerOpen(false)}
                            >
                                Tutup
                            </Button>
                        </div>
                        {DetailPanel}
                    </div>
                </div>
            ) : null}
        </>
    );
}
