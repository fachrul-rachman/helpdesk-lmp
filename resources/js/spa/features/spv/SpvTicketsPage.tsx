import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';

import { PriorityBadge, StatusBadge } from '../../components/common/badges';
import type {
    TicketPriority,
    TicketStatus,
} from '../../components/common/badges';
import { formatDateTimeId } from '../../components/common/format';
import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
import { api } from '../../lib/axios';
import { getEcho } from '../../lib/echo';
import { cn } from '../../lib/utils';
import type { ApiListResponse, TicketListItem } from '../../types/ticket';

const statusOptions: Array<{ value: '' | TicketStatus; label: string }> = [
    { value: '', label: 'Semua Status' },
    { value: 'new', label: 'Baru' },
    { value: 'open', label: 'Open' },
    { value: 'pending', label: 'Pending' },
    { value: 'on_progress', label: 'On Progress' },
    { value: 'queue', label: 'Antrian' },
    { value: 'solved', label: 'Solved' },
    { value: 'closed', label: 'Closed' },
];

const priorityOptions: Array<{ value: '' | TicketPriority; label: string }> = [
    { value: '', label: 'Semua Prioritas' },
    { value: 'high', label: 'Tinggi' },
    { value: 'medium', label: 'Sedang' },
    { value: 'low', label: 'Rendah' },
];

type ExportPeriod = 'week' | 'month' | 'year' | 'custom';

const exportPeriodOptions: Array<{ value: ExportPeriod; label: string }> = [
    { value: 'week', label: 'Minggu ini' },
    { value: 'month', label: 'Bulan ini' },
    { value: 'year', label: 'Tahun ini' },
    { value: 'custom', label: 'Tanggal bebas' },
];

export function SpvTicketsPage() {
    const [items, setItems] = useState<TicketListItem[]>([]);
    const [isLoading, setIsLoading] = useState(true);
    const [errorMessage, setErrorMessage] = useState<string | null>(null);

    const [search, setSearch] = useState('');
    const [status, setStatus] = useState<'' | TicketStatus>('');
    const [priority, setPriority] = useState<'' | TicketPriority>('');
    const [hasRequest, setHasRequest] = useState(false);
    const [exportPeriod, setExportPeriod] = useState<ExportPeriod>('month');
    const [exportStartDate, setExportStartDate] = useState('');
    const [exportEndDate, setExportEndDate] = useState('');
    const [isExporting, setIsExporting] = useState(false);
    const [exportError, setExportError] = useState<string | null>(null);

    async function exportTickets() {
        if (
            exportPeriod === 'custom' &&
            (!exportStartDate ||
                !exportEndDate ||
                exportEndDate < exportStartDate)
        ) {
            setExportError(
                'Isi rentang tanggal yang valid. Tanggal akhir tidak boleh sebelum tanggal awal.',
            );

            return;
        }

        setIsExporting(true);
        setExportError(null);

        try {
            const response = await api.get<Blob>('/api/spv/tickets/export', {
                params: {
                    period: exportPeriod,
                    start_date:
                        exportPeriod === 'custom' ? exportStartDate : undefined,
                    end_date:
                        exportPeriod === 'custom' ? exportEndDate : undefined,
                },
                responseType: 'blob',
            });
            const disposition = String(
                response.headers['content-disposition'] ?? '',
            );
            const filenameMatch = disposition.match(/filename="?([^";]+)"?/i);
            const filename =
                filenameMatch?.[1] ?? 'rekap-data-respons-tiket.xlsx';
            const url = window.URL.createObjectURL(response.data);
            const anchor = document.createElement('a');
            anchor.href = url;
            anchor.download = filename;
            document.body.appendChild(anchor);
            anchor.click();
            anchor.remove();
            window.URL.revokeObjectURL(url);
        } catch {
            setExportError('Export gagal dibuat. Silakan coba lagi.');
        } finally {
            setIsExporting(false);
        }
    }

    async function load() {
        setIsLoading(true);
        setErrorMessage(null);

        try {
            const response = await api.get<ApiListResponse<TicketListItem>>(
                '/api/tickets',
                {
                    params: {
                        per_page: 50,
                        page: 1,
                        search: search.trim() || undefined,
                        status: status || undefined,
                        priority: priority || undefined,
                        has_request: hasRequest ? true : undefined,
                    },
                },
            );
            setItems(response.data.data ?? []);
        } catch (error: any) {
            const message =
                error?.response?.data?.message ??
                'Gagal memuat ticket. Silakan coba lagi.';
            setErrorMessage(String(message));
        } finally {
            setIsLoading(false);
        }
    }

    useEffect(() => {
        // Initial page data is loaded when the realtime subscription is installed.
        // eslint-disable-next-line react-hooks/set-state-in-effect
        load();
        const echo = getEcho();
        const channel = echo.private('dashboard.spv');
        const handler = () => load();
        channel.listen('.TicketCreated', handler);
        channel.listen('.TicketAssigned', handler);
        channel.listen('.TicketStatusChanged', handler);
        channel.listen('.MessageSent', handler);

        return () => {
            channel.stopListening('.TicketCreated');
            channel.stopListening('.TicketAssigned');
            channel.stopListening('.TicketStatusChanged');
            channel.stopListening('.MessageSent');
            echo.leave('dashboard.spv');
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const filtered = useMemo(() => items, [items]);

    return (
        <div className="space-y-4">
            <div className="flex items-center justify-between gap-3">
                <h1 className="text-lg font-semibold text-slate-900">
                    Semua Ticket
                </h1>
                <Link
                    to="/spv/tickets/create"
                    className="hidden lg:inline-flex"
                >
                    <Button>Buat Ticket</Button>
                </Link>
            </div>

            <section
                className="rounded-xl bg-white p-4 ring-1 ring-slate-200"
                aria-labelledby="export-ticket-title"
            >
                <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <h2
                            id="export-ticket-title"
                            className="text-sm font-semibold text-slate-900"
                        >
                            Export rekap ticket
                        </h2>
                        <p className="mt-1 text-xs text-slate-600">
                            Mengunduh ticket solved dan closed berdasarkan
                            tanggal ticket dibuat.
                        </p>
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2 lg:flex lg:items-end">
                        <label className="grid gap-1 text-xs font-medium text-slate-700">
                            Periode
                            <select
                                className="h-10 min-w-40 rounded-md border border-slate-200 bg-white px-3 text-sm font-normal focus:ring-2 focus:ring-blue-500 focus:outline-none"
                                value={exportPeriod}
                                onChange={(event) =>
                                    setExportPeriod(
                                        event.target.value as ExportPeriod,
                                    )
                                }
                            >
                                {exportPeriodOptions.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </label>
                        {exportPeriod === 'custom' ? (
                            <>
                                <label className="grid gap-1 text-xs font-medium text-slate-700">
                                    Tanggal awal
                                    <Input
                                        type="date"
                                        value={exportStartDate}
                                        onChange={(event) =>
                                            setExportStartDate(
                                                event.target.value,
                                            )
                                        }
                                    />
                                </label>
                                <label className="grid gap-1 text-xs font-medium text-slate-700">
                                    Tanggal akhir
                                    <Input
                                        type="date"
                                        min={exportStartDate || undefined}
                                        value={exportEndDate}
                                        onChange={(event) =>
                                            setExportEndDate(event.target.value)
                                        }
                                    />
                                </label>
                            </>
                        ) : null}
                        <Button onClick={exportTickets} disabled={isExporting}>
                            {isExporting ? 'Menyiapkan...' : 'Download XLSX'}
                        </Button>
                    </div>
                </div>
                {exportError ? (
                    <p className="mt-3 text-sm text-red-700" role="alert">
                        {exportError}
                    </p>
                ) : null}
            </section>

            <div className="rounded-xl bg-white p-3 ring-1 ring-slate-200">
                <div className="grid gap-2 sm:grid-cols-3">
                    <Input
                        placeholder="Cari nomor ticket / subject / nama customer..."
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                    />
                    <select
                        className="h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none"
                        value={status}
                        onChange={(e) =>
                            setStatus((e.target.value as any) || '')
                        }
                    >
                        {statusOptions.map((o) => (
                            <option key={o.label} value={o.value}>
                                {o.label}
                            </option>
                        ))}
                    </select>
                    <select
                        className="h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none"
                        value={priority}
                        onChange={(e) =>
                            setPriority((e.target.value as any) || '')
                        }
                    >
                        {priorityOptions.map((o) => (
                            <option key={o.label} value={o.value}>
                                {o.label}
                            </option>
                        ))}
                    </select>
                    <label className="flex items-center gap-2 rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700">
                        <input
                            type="checkbox"
                            className="h-4 w-4"
                            checked={hasRequest}
                            onChange={(e) => setHasRequest(e.target.checked)}
                        />
                        Hanya yang ada request takeover
                    </label>
                </div>
                <div className="mt-3 flex justify-end">
                    <Button
                        variant="secondary"
                        onClick={load}
                        disabled={isLoading}
                    >
                        {isLoading ? 'Memuat...' : 'Terapkan Filter'}
                    </Button>
                </div>
            </div>

            {isLoading ? (
                <div className="text-sm text-slate-600">Memuat ticket...</div>
            ) : errorMessage ? (
                <div className="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                    {errorMessage}
                </div>
            ) : filtered.length === 0 ? (
                <div className="rounded-lg border border-slate-200 bg-white p-4 text-sm text-slate-600">
                    Tidak ada ticket.
                </div>
            ) : (
                <div className="space-y-3">
                    {filtered.map((t) => (
                        <Link
                            key={t.id}
                            to={`/spv/tickets/${t.id}`}
                            className={cn(
                                'block rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200 hover:ring-slate-300',
                            )}
                        >
                            <div className="flex items-start justify-between gap-3">
                                <div className="min-w-0">
                                    {t.ticket_number ? (
                                        <div className="text-xs font-semibold text-slate-500">
                                            {t.ticket_number}
                                        </div>
                                    ) : null}
                                    <div className="truncate text-sm font-semibold text-slate-900">
                                        {t.subject}
                                    </div>
                                    <div className="mt-1 text-xs text-slate-600">
                                        {(t.customer?.name ??
                                            t.customer?.phone_number ??
                                            '-') + ''}
                                        {t.division?.name
                                            ? ` - ${t.division.name}`
                                            : ''}
                                    </div>
                                </div>
                                <div className="shrink-0 text-right text-xs text-slate-500">
                                    {formatDateTimeId(t.created_at)}
                                </div>
                            </div>
                            <div className="mt-3 flex flex-wrap items-center gap-2">
                                <StatusBadge status={t.status} />
                                <PriorityBadge priority={t.priority} />
                                {t.has_takeover_request ? (
                                    <span
                                        className={cn(
                                            'rounded-full px-2 py-1 text-xs font-semibold',
                                            t.takeover_request_status ===
                                                'approved'
                                                ? 'bg-emerald-50 text-emerald-700'
                                                : 'bg-amber-50 text-amber-800',
                                        )}
                                    >
                                        Takeover{' '}
                                        {t.takeover_request_status ===
                                        'approved'
                                            ? 'Approved'
                                            : 'Pending'}
                                    </span>
                                ) : null}
                            </div>
                        </Link>
                    ))}
                </div>
            )}
        </div>
    );
}
