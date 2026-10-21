import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';

import { PriorityBadge, StatusBadge } from '../../components/common/badges';
import { formatDateId, formatDateTimeId } from '../../components/common/format';
import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
import { api } from '../../lib/axios';
import { getEcho } from '../../lib/echo';
import type { ApiListResponse, TicketListItem } from '../../types/ticket';

import { reportSelectClass, reportStatuses } from './reportFilters';
import { TicketExportPanel } from './TicketExportPanel';

export function SpvTicketsPage() {
    const [searchParams] = useSearchParams();

    return (
        <SpvTicketsList
            key={searchParams.toString()}
            searchParams={searchParams}
        />
    );
}

function SpvTicketsList({ searchParams }: { searchParams: URLSearchParams }) {
    const reportMode = searchParams.get('report') === '1';
    const [draft, setDraft] = useState({
        search: '',
        status: searchParams.get('status') ?? '',
        priority: '',
        hasRequest: false,
    });
    const [applied, setApplied] = useState(draft);
    const [page, setPage] = useState(1);
    const [revision, setRevision] = useState(0);
    const [result, setResult] = useState<{
        key: string;
        items: TicketListItem[];
        total: number;
        error?: string;
    } | null>(null);
    const params = reportMode
        ? new URLSearchParams(searchParams)
        : new URLSearchParams();
    params.delete('report');
    params.set('per_page', '50');
    params.set('page', String(page));

    if (applied.search.trim()) {
        params.set('search', applied.search.trim());
    }

    if (applied.status) {
        params.set('status', applied.status);
    } else {
        params.delete('status');
    }

    if (applied.priority) {
        params.set('priority', applied.priority);
    }

    if (applied.hasRequest) {
        params.set('has_request', '1');
    }

    const url = `${reportMode ? '/api/spv/reports/tickets' : '/api/tickets'}?${params}`;
    const key = `${url}:${revision}`;

    useEffect(() => {
        const controller = new AbortController();
        api.get<ApiListResponse<TicketListItem>>(url, {
            signal: controller.signal,
        })
            .then((response) =>
                setResult({
                    key,
                    items: response.data.data,
                    total: response.data.meta.total,
                }),
            )
            .catch(() => {
                if (!controller.signal.aborted) {
                    setResult({
                        key,
                        items: [],
                        total: 0,
                        error: 'Gagal memuat ticket. Silakan coba lagi.',
                    });
                }
            });

        return () => controller.abort();
    }, [url, key]);

    useEffect(() => {
        const echo = getEcho(),
            channel = echo.private('dashboard.spv');
        const events = [
            '.TicketCreated',
            '.TicketAssigned',
            '.TicketStatusChanged',
            '.MessageSent',
        ];
        const handler = () => setRevision((value) => value + 1);
        events.forEach((event) => channel.listen(event, handler));

        return () => {
            events.forEach((event) => channel.stopListening(event, handler));
            echo.leave('dashboard.spv');
        };
    }, []);

    const loading = result?.key !== key;
    const items = !loading ? result.items : [];

    return (
        <div className="space-y-4">
            <div className="flex items-center justify-between gap-3">
                <h1 className="text-lg font-semibold text-slate-900">
                    Semua Ticket
                </h1>
                <Button asChild className="hidden lg:inline-flex">
                    <Link to="/spv/tickets/create">Buat Ticket</Link>
                </Button>
            </div>
            {reportMode && (
                <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-blue-100 bg-blue-50 p-3">
                    <div>
                        <p className="text-sm font-medium text-blue-900">
                            Hasil pilihan laporan
                        </p>
                        <p className="mt-1 text-xs text-blue-800">
                            {formatDateId(searchParams.get('start_date'))}{' '}
                            sampai {formatDateId(searchParams.get('end_date'))}
                        </p>
                        {searchParams.get('filter') === 'unreviewed' && (
                            <p className="mt-1 text-xs text-blue-800">
                                Belum mengisi ulasan, berdasarkan tanggal solved
                            </p>
                        )}
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button asChild variant="outline">
                            <Link to="/spv/reports">Laporan</Link>
                        </Button>
                        <Button asChild variant="outline">
                            <Link
                                to="/spv/tickets"
                                onClick={() => {
                                    const empty = {
                                        search: '',
                                        status: '',
                                        priority: '',
                                        hasRequest: false,
                                    };
                                    setDraft(empty);
                                    setApplied(empty);
                                    setPage(1);
                                }}
                            >
                                Hapus filter laporan
                            </Link>
                        </Button>
                    </div>
                </div>
            )}
            <TicketExportPanel />
            <form
                className="rounded-xl border border-slate-200 bg-white p-3"
                onSubmit={(event) => {
                    event.preventDefault();
                    setApplied(draft);
                    setPage(1);
                    setRevision((value) => value + 1);
                }}
            >
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <label className="grid gap-1 text-xs text-slate-600">
                        Cari ticket
                        <Input
                            placeholder="Nomor, judul, atau customer"
                            className="min-h-11 text-base sm:text-sm"
                            value={draft.search}
                            onChange={(event) =>
                                setDraft({
                                    ...draft,
                                    search: event.target.value,
                                })
                            }
                        />
                    </label>
                    <label className="grid gap-1 text-xs text-slate-600">
                        Status
                        <select
                            className={reportSelectClass}
                            value={draft.status}
                            onChange={(event) =>
                                setDraft({
                                    ...draft,
                                    status: event.target.value,
                                })
                            }
                        >
                            <option value="">Semua status</option>
                            {reportStatuses.map((status) => (
                                <option key={status.value} value={status.value}>
                                    {status.label}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="grid gap-1 text-xs text-slate-600">
                        Prioritas
                        <select
                            className={reportSelectClass}
                            value={draft.priority}
                            onChange={(event) =>
                                setDraft({
                                    ...draft,
                                    priority: event.target.value,
                                })
                            }
                        >
                            <option value="">Semua prioritas</option>
                            <option value="high">Tinggi</option>
                            <option value="medium">Sedang</option>
                            <option value="low">Rendah</option>
                        </select>
                    </label>
                    <label className="flex min-h-11 items-center gap-2 text-sm text-slate-700">
                        <input
                            type="checkbox"
                            className="h-4 w-4"
                            checked={draft.hasRequest}
                            onChange={(event) =>
                                setDraft({
                                    ...draft,
                                    hasRequest: event.target.checked,
                                })
                            }
                        />
                        Hanya request takeover
                    </label>
                </div>
                <div className="mt-3 flex justify-end">
                    <Button
                        type="submit"
                        variant="secondary"
                        disabled={loading}
                    >
                        Terapkan Filter
                    </Button>
                </div>
            </form>
            {loading ? (
                <p role="status" className="text-sm text-slate-600">
                    Memuat ticket...
                </p>
            ) : result.error ? (
                <p
                    role="alert"
                    className="rounded-lg bg-red-50 p-4 text-sm text-red-700"
                >
                    {result.error}
                </p>
            ) : items.length === 0 ? (
                <p className="rounded-lg border border-slate-200 bg-white p-4 text-sm text-slate-600">
                    Tidak ada ticket.
                </p>
            ) : (
                <>
                    <div className="space-y-3">
                        {items.map((ticket) => (
                            <Link
                                key={ticket.id}
                                to={`/spv/tickets/${ticket.id}`}
                                className="block rounded-xl border border-slate-200 bg-white p-4 hover:border-slate-300 focus-visible:outline-2 focus-visible:outline-blue-600"
                            >
                                <div className="flex flex-col gap-2 sm:flex-row sm:justify-between">
                                    <div className="min-w-0">
                                        <p className="text-xs font-semibold text-slate-500">
                                            {ticket.ticket_number}
                                        </p>
                                        <h2 className="mt-1 text-sm font-semibold break-words text-slate-900">
                                            {ticket.subject}
                                        </h2>
                                        <p className="mt-1 text-xs text-slate-600">
                                            {ticket.customer?.name ??
                                                ticket.customer?.phone_number ??
                                                'Customer'}
                                        </p>
                                        {ticket.division?.name && (
                                            <p className="mt-1 text-xs text-slate-600">
                                                {ticket.division.name}
                                            </p>
                                        )}
                                    </div>
                                    <p className="shrink-0 text-xs text-slate-500">
                                        {formatDateTimeId(ticket.created_at)}
                                    </p>
                                </div>
                                <div className="mt-3 flex flex-wrap gap-2">
                                    <StatusBadge status={ticket.status} />
                                    <PriorityBadge priority={ticket.priority} />
                                    {ticket.has_takeover_request && (
                                        <span
                                            className={`rounded-full px-2 py-1 text-xs font-semibold ${ticket.takeover_request_status === 'approved' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-800'}`}
                                        >
                                            Takeover{' '}
                                            {ticket.takeover_request_status ===
                                            'approved'
                                                ? 'Approved'
                                                : 'Pending'}
                                        </span>
                                    )}
                                </div>
                            </Link>
                        ))}
                    </div>
                    {result.total > 50 && (
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <Button
                                variant="outline"
                                disabled={page === 1}
                                onClick={() => setPage(page - 1)}
                            >
                                Sebelumnya
                            </Button>
                            <p className="text-xs text-slate-600">
                                Halaman {page} dari{' '}
                                {Math.ceil(result.total / 50)}
                            </p>
                            <Button
                                variant="outline"
                                disabled={page * 50 >= result.total}
                                onClick={() => setPage(page + 1)}
                            >
                                Berikutnya
                            </Button>
                        </div>
                    )}
                </>
            )}
        </div>
    );
}
