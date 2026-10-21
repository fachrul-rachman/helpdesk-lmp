import { Download } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { Link } from 'react-router-dom';

import {
    formatDateId,
    formatDateTimeId,
    formatSlaMinutes,
} from '../../components/common/format';
import { Button } from '../../components/ui/button';
import { api } from '../../lib/axios';

import { ReportDateFields } from './ReportDateFields';
import type {
    DivisionOption,
    ReportFilters,
    ReportPeriod,
} from './reportFilters';
import {
    periodRange,
    reportSelectClass,
    reportStatuses,
    reportTicketUrl,
    validRange,
} from './reportFilters';
import { TicketExportPanel } from './TicketExportPanel';

type Section = 'overview' | 'sla' | 'category' | 'satisfaction';
type Group = {
    name: string;
    count: number;
    value?: string | null;
    id?: string;
};
type SlaMetric = { eligible: number; on_time: number; late: number };
type Overview = {
    summary: {
        total: number;
        active: number;
        completed: number;
        queued: number;
    };
    statuses: { status: string; count: number }[];
    active_by_division: Group[];
};
type SlaDivision = DivisionOption & {
    first_response_target_minutes: number;
    resolution_target_minutes: number;
    average_minutes: number | null;
    first_response: SlaMetric;
    resolution: SlaMetric;
};
type Sla = {
    first_response: SlaMetric & {
        average_minutes: number | null;
        missing: number;
    };
    resolution: SlaMetric;
    divisions: SlaDivision[];
};
type Categories = {
    categories: Group[];
    subcategories: Group[];
    sites: Group[];
    zones: Group[];
};
type Satisfaction = {
    summary: {
        total: number;
        average: number | null;
        good: number;
        bad: number;
        unreviewed: number;
    };
    ratings: { rating: number; count: number }[];
    divisions: (DivisionOption & {
        good: number;
        bad: number;
        total: number;
    })[];
};
type ReportResponse = {
    data: Overview | Sla | Categories | Satisfaction;
    divisions: DivisionOption[];
};
type ReviewSelection = {
    rating?: number;
    rating_group?: 'good' | 'bad';
    division_id?: string;
    label?: string;
};
type Review = {
    id: number;
    ticket_id: string;
    ticket_number: string;
    rating: number;
    feedback: string | null;
    division_name: string | null;
    submitted_at: string;
};

const sections: { value: Section; label: string }[] = [
    { value: 'overview', label: 'Ringkasan' },
    { value: 'sla', label: 'SLA' },
    { value: 'category', label: 'Kategori & lokasi' },
    { value: 'satisfaction', label: 'Kepuasan' },
];
const panelClass =
    'min-w-0 rounded-xl border border-slate-200 bg-white p-4 sm:p-5';
const linkClass =
    'inline-flex min-h-11 items-center rounded px-1 text-sm text-blue-700 hover:underline focus-visible:outline-2 focus-visible:outline-blue-600';
const percent = (metric: SlaMetric) =>
    metric.eligible
        ? `${Math.round((metric.on_time / metric.eligible) * 100)}%`
        : 'Belum ada data';
const duration = (value: number | null) =>
    value === null ? 'Belum ada data' : formatSlaMinutes(value);

function Panel({
    title,
    subtitle,
    children,
}: {
    title: string;
    subtitle?: string;
    children: ReactNode;
}) {
    return (
        <section className={panelClass}>
            <h2 className="text-sm font-semibold text-slate-900">{title}</h2>
            {subtitle && (
                <p className="mt-1 text-xs text-slate-600">{subtitle}</p>
            )}
            <div className="mt-4">{children}</div>
        </section>
    );
}

function Stats({
    items,
}: {
    items: { label: string; value: string | number; note: string }[];
}) {
    return (
        <dl className="grid grid-cols-2 gap-3 xl:grid-cols-4">
            {items.map((item) => (
                <div key={item.label} className={panelClass}>
                    <dt className="text-xs text-slate-600">{item.label}</dt>
                    <dd className="mt-2 text-2xl font-semibold tracking-tight break-words text-slate-900 tabular-nums">
                        {item.value}
                    </dd>
                    <dd className="mt-1 text-xs text-slate-600">{item.note}</dd>
                </div>
            ))}
        </dl>
    );
}

function Bars({
    items,
}: {
    items: {
        label: string;
        count: number;
        to?: string;
        onClick?: () => void;
        tone?: 'good' | 'bad';
    }[];
}) {
    const max = Math.max(1, ...items.map((item) => item.count));

    if (items.length === 0) {
        return <p className="py-6 text-sm text-slate-500">Belum ada data.</p>;
    }

    return (
        <div className="space-y-2">
            {items.map((item) => {
                const content = (
                    <>
                        <span className="mb-2 flex justify-between gap-3 text-xs">
                            <span className="text-left break-words">
                                {item.label}
                            </span>
                            <strong className="font-semibold tabular-nums">
                                {item.count}
                            </strong>
                        </span>
                        <span className="block h-2 overflow-hidden rounded-full bg-slate-100">
                            <span
                                className={`block h-full rounded-full motion-safe:transition-[width] motion-safe:duration-200 ${item.tone === 'bad' ? 'bg-red-600' : item.tone === 'good' ? 'bg-emerald-600' : 'bg-blue-600'}`}
                                style={{
                                    width: `${(item.count / max) * 100}%`,
                                }}
                            />
                        </span>
                    </>
                );
                const className =
                    'block min-h-11 w-full rounded py-2 text-slate-700 hover:text-blue-700 focus-visible:outline-2 focus-visible:outline-blue-600';

                return item.to ? (
                    <Link
                        key={item.label}
                        className={className}
                        to={item.to}
                        aria-label={`${item.label}: ${item.count} ticket`}
                    >
                        {content}
                    </Link>
                ) : (
                    <button
                        key={item.label}
                        type="button"
                        className={className}
                        onClick={item.onClick}
                        aria-label={`${item.label}: ${item.count} ulasan`}
                    >
                        {content}
                    </button>
                );
            })}
        </div>
    );
}

function SlaLinks({
    metric,
    filters,
    kind,
    divisionId,
}: {
    metric: SlaMetric;
    filters: ReportFilters;
    kind: 'fr' | 'resolution';
    divisionId: string;
}) {
    return (
        <div>
            <Link
                className={linkClass}
                to={reportTicketUrl(
                    { ...filters, division_id: divisionId },
                    { filter: `${kind}_on_time` },
                )}
                aria-label={`Tepat waktu: ${metric.on_time} dari ${metric.eligible}`}
            >
                {metric.on_time} / {metric.eligible}
            </Link>
            <br />
            <Link
                className={`${linkClass} text-red-700`}
                to={reportTicketUrl(
                    { ...filters, division_id: divisionId },
                    { filter: `${kind}_late` },
                )}
            >
                {metric.late} terlambat
            </Link>
        </div>
    );
}

function SlaContent({ data, filters }: { data: Sla; filters: ReportFilters }) {
    return (
        <>
            <Stats
                items={[
                    {
                        label: 'Respons tepat waktu',
                        value: percent(data.first_response),
                        note: `${data.first_response.on_time} dari ${data.first_response.eligible} dapat dinilai`,
                    },
                    {
                        label: 'Rata-rata respons',
                        value: duration(data.first_response.average_minutes),
                        note: 'Jam kerja divisi',
                    },
                    {
                        label: 'Selesai tepat waktu',
                        value: percent(data.resolution),
                        note: `${data.resolution.on_time} dari ${data.resolution.eligible} dapat dinilai`,
                    },
                    {
                        label: 'Belum ada data respons',
                        value: data.first_response.missing,
                        note: 'Belum dibalas atau waktu tidak lengkap',
                    },
                ]}
            />
            <div className="grid gap-4 lg:grid-cols-2">
                {[
                    {
                        title: 'SLA respons pertama',
                        kind: 'fr',
                        metric: data.first_response,
                    },
                    {
                        title: 'SLA penyelesaian',
                        kind: 'resolution',
                        metric: data.resolution,
                    },
                ].map(({ title, kind, metric }) => (
                    <Panel
                        key={kind}
                        title={title}
                        subtitle="Hanya ticket dengan waktu lengkap"
                    >
                        <Bars
                            items={[
                                {
                                    label: 'Tepat waktu',
                                    count: metric.on_time,
                                    to: reportTicketUrl(filters, {
                                        filter: `${kind}_on_time`,
                                    }),
                                    tone: 'good',
                                },
                                {
                                    label: 'Melewati batas',
                                    count: metric.late,
                                    to: reportTicketUrl(filters, {
                                        filter: `${kind}_late`,
                                    }),
                                    tone: 'bad',
                                },
                            ]}
                        />
                    </Panel>
                ))}
            </div>
            <Panel title="SLA masing-masing divisi">
                <div className="space-y-4 md:hidden">
                    {data.divisions.map((division) => (
                        <article
                            key={division.id}
                            className="border-b border-slate-200 pb-4 last:border-0 last:pb-0"
                        >
                            <h3 className="text-sm font-medium">
                                {division.name}
                            </h3>
                            <dl className="mt-3 grid grid-cols-2 gap-3 text-xs">
                                <div>
                                    <dt className="text-slate-500">
                                        Target respons
                                    </dt>
                                    <dd className="mt-1">
                                        {duration(
                                            division.first_response_target_minutes,
                                        )}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-slate-500">
                                        Rata-rata respons
                                    </dt>
                                    <dd className="mt-1">
                                        {duration(division.average_minutes)}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-slate-500">
                                        Respons tepat waktu
                                    </dt>
                                    <dd>
                                        <SlaLinks
                                            metric={division.first_response}
                                            filters={filters}
                                            kind="fr"
                                            divisionId={division.id}
                                        />
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-slate-500">
                                        Selesai tepat waktu
                                    </dt>
                                    <dd>
                                        <SlaLinks
                                            metric={division.resolution}
                                            filters={filters}
                                            kind="resolution"
                                            divisionId={division.id}
                                        />
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-slate-500">
                                        Target selesai
                                    </dt>
                                    <dd className="mt-1">
                                        {duration(
                                            division.resolution_target_minutes,
                                        )}
                                    </dd>
                                </div>
                            </dl>
                        </article>
                    ))}
                </div>
                <div className="hidden overflow-x-auto md:block">
                    <table className="w-full text-left text-xs">
                        <thead className="bg-slate-50 text-slate-600">
                            <tr>
                                {[
                                    'Divisi',
                                    'Target respons',
                                    'Rata-rata respons',
                                    'Respons tepat waktu',
                                    'Target selesai',
                                    'Selesai tepat waktu',
                                ].map((label) => (
                                    <th
                                        key={label}
                                        className="px-3 py-3 font-medium"
                                    >
                                        {label}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {data.divisions.map((division) => (
                                <tr
                                    key={division.id}
                                    className="border-b border-slate-100 last:border-0"
                                >
                                    <td className="px-3 py-3">
                                        {division.name}
                                    </td>
                                    <td className="px-3 py-3">
                                        {duration(
                                            division.first_response_target_minutes,
                                        )}
                                    </td>
                                    <td className="px-3 py-3">
                                        {duration(division.average_minutes)}
                                    </td>
                                    <td className="px-3 py-1">
                                        <SlaLinks
                                            metric={division.first_response}
                                            filters={filters}
                                            kind="fr"
                                            divisionId={division.id}
                                        />
                                    </td>
                                    <td className="px-3 py-3">
                                        {duration(
                                            division.resolution_target_minutes,
                                        )}
                                    </td>
                                    <td className="px-3 py-1">
                                        <SlaLinks
                                            metric={division.resolution}
                                            filters={filters}
                                            kind="resolution"
                                            divisionId={division.id}
                                        />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </Panel>
        </>
    );
}

function Reviews({
    filters,
    selection,
    onReset,
}: {
    filters: ReportFilters;
    selection: ReviewSelection;
    onReset: () => void;
}) {
    const [page, setPage] = useState(1);
    const params = new URLSearchParams({
        start_date: filters.start_date,
        end_date: filters.end_date,
        division_id: selection.division_id ?? filters.division_id,
        page: String(page),
        per_page: '5',
    });

    if (selection.rating) {
        params.set('rating', String(selection.rating));
    }

    if (selection.rating_group) {
        params.set('rating_group', selection.rating_group);
    }

    const key = params.toString();
    const [result, setResult] = useState<{
        key: string;
        items: Review[];
        total: number;
        error?: string;
    } | null>(null);

    useEffect(() => {
        const controller = new AbortController();
        api.get<{ data: Review[]; meta: { total: number } }>(
            `/api/spv/reports/reviews?${key}`,
            { signal: controller.signal },
        )
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
                        error: 'Ulasan gagal dimuat.',
                    });
                }
            });

        return () => controller.abort();
    }, [key]);

    return (
        <Panel title={selection.label ?? 'Ulasan pelanggan'}>
            {selection.label && (
                <Button variant="outline" className="mb-3" onClick={onReset}>
                    Semua ulasan
                </Button>
            )}
            {result?.key !== key ? (
                <p role="status" className="text-sm text-slate-500">
                    Memuat ulasan...
                </p>
            ) : result.error ? (
                <p role="alert" className="text-sm text-red-700">
                    {result.error}
                </p>
            ) : (
                <>
                    <p
                        className="mb-3 text-xs text-slate-500"
                        aria-live="polite"
                    >
                        {result.total} ulasan
                    </p>
                    {result.items.map((review) => (
                        <article
                            key={review.id}
                            className="border-t border-slate-200 py-4"
                        >
                            <div className="flex items-center justify-between gap-3">
                                <Link
                                    className={linkClass}
                                    to={`/spv/tickets/${review.ticket_id}`}
                                >
                                    {review.ticket_number}
                                </Link>
                                <span
                                    className={`rounded-md px-2 py-1 text-xs ${review.rating >= 3 ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700'}`}
                                >
                                    {review.rating} / 5 (
                                    {review.rating >= 3 ? 'Good' : 'Bad'})
                                </span>
                            </div>
                            {review.feedback && (
                                <p className="mt-1 text-sm break-words whitespace-pre-wrap">
                                    {review.feedback}
                                </p>
                            )}
                            <p className="mt-2 text-xs text-slate-500">
                                {review.division_name ??
                                    'Divisi tidak tersedia'}
                            </p>
                            <p className="mt-1 text-xs text-slate-500">
                                {formatDateTimeId(review.submitted_at)}
                            </p>
                        </article>
                    ))}
                    {result.total === 0 && (
                        <p className="text-sm text-slate-500">
                            Belum ada ulasan.
                        </p>
                    )}
                    {result.total > 5 && (
                        <div className="mt-3 flex flex-wrap items-center justify-between gap-2">
                            <Button
                                variant="outline"
                                disabled={page === 1}
                                onClick={() => setPage(page - 1)}
                            >
                                Sebelumnya
                            </Button>
                            <span className="text-xs text-slate-500">
                                Halaman {page} dari{' '}
                                {Math.ceil(result.total / 5)}
                            </span>
                            <Button
                                variant="outline"
                                disabled={page * 5 >= result.total}
                                onClick={() => setPage(page + 1)}
                            >
                                Berikutnya
                            </Button>
                        </div>
                    )}
                </>
            )}
        </Panel>
    );
}

export function SpvReportsPage() {
    const [section, setSection] = useState<Section>('overview');
    const [period, setPeriod] = useState<ReportPeriod>('month');
    const [filters, setFilters] = useState<ReportFilters>(() => ({
        ...periodRange('month'),
        division_id: '',
    }));
    const [exportOpen, setExportOpen] = useState(false);
    const [reviewSelection, setReviewSelection] = useState<ReviewSelection>({});
    const [result, setResult] = useState<{
        key: string;
        response?: ReportResponse;
        error?: string;
    } | null>(null);
    const params = new URLSearchParams({ ...filters, section }),
        key = params.toString();
    const valid = validRange(filters);

    useEffect(() => {
        if (!valid) {
            return;
        }

        const controller = new AbortController();
        api.get<ReportResponse>(`/api/spv/reports?${key}`, {
            signal: controller.signal,
        })
            .then((response) => setResult({ key, response: response.data }))
            .catch(() => {
                if (!controller.signal.aborted) {
                    setResult({
                        key,
                        error: 'Laporan gagal dimuat. Silakan coba lagi.',
                    });
                }
            });

        return () => controller.abort();
    }, [key, valid]);

    const data = result?.key === key ? result.response?.data : undefined;
    const divisions = result?.response?.divisions ?? [];
    const error = result?.key === key ? result.error : null;
    const groupItems = (items: Group[], dimension: string) =>
        items.map((item) => ({
            label: item.name,
            count: item.count,
            to: reportTicketUrl(
                filters,
                item.value === null
                    ? { dimension, missing: '1' }
                    : { dimension, value: item.value ?? '' },
            ),
        }));

    return (
        <div className="space-y-5 text-slate-900">
            <div className="flex items-center justify-between gap-3">
                <h1 className="text-xl font-semibold tracking-tight">
                    Laporan
                </h1>
                <Button
                    className="min-h-11"
                    disabled={!valid}
                    onClick={() => setExportOpen(!exportOpen)}
                    aria-expanded={exportOpen}
                >
                    <Download className="h-4 w-4" aria-hidden="true" />
                    Ekspor
                </Button>
            </div>
            {exportOpen && (
                <TicketExportPanel
                    initial={filters}
                    initialPeriod={period}
                    divisions={divisions}
                    onClose={() => setExportOpen(false)}
                />
            )}
            <div className="grid min-w-0 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <ReportDateFields
                    period={period}
                    range={filters}
                    onChange={(next, range) => {
                        setPeriod(next);
                        setFilters({ ...filters, ...range });
                        setReviewSelection({});
                    }}
                />
                <label className="grid min-w-0 gap-1 text-xs font-medium text-slate-600">
                    Divisi
                    <select
                        className={reportSelectClass}
                        value={filters.division_id}
                        onChange={(event) => {
                            setFilters({
                                ...filters,
                                division_id: event.target.value,
                            });
                            setReviewSelection({});
                        }}
                    >
                        <option value="">Semua divisi</option>
                        {divisions.map((division) => (
                            <option key={division.id} value={division.id}>
                                {division.name}
                            </option>
                        ))}
                    </select>
                </label>
            </div>
            {valid ? (
                <p className="text-xs text-slate-500">
                    {formatDateId(filters.start_date)} sampai{' '}
                    {formatDateId(filters.end_date)}
                </p>
            ) : (
                <p role="alert" className="text-sm text-red-700">
                    Pilih rentang tanggal yang valid, maksimal satu tahun.
                </p>
            )}
            <nav
                className="flex flex-wrap gap-x-5 gap-y-1 border-b border-slate-200"
                aria-label="Bagian laporan"
            >
                {sections.map(({ value, label }) => (
                    <button
                        key={value}
                        type="button"
                        className={`min-h-11 border-b-2 text-sm motion-safe:transition-colors ${section === value ? 'border-blue-600 font-medium text-blue-700' : 'border-transparent text-slate-600 hover:text-slate-900'}`}
                        aria-pressed={section === value}
                        onClick={() => setSection(value)}
                    >
                        {label}
                    </button>
                ))}
            </nav>
            {valid &&
                (error ? (
                    <p
                        role="alert"
                        className="rounded-lg bg-red-50 p-4 text-sm text-red-700"
                    >
                        {error}
                    </p>
                ) : !data ? (
                    <p role="status" className="py-4 text-sm text-slate-500">
                        Memuat laporan...
                    </p>
                ) : (
                    <div className="space-y-4">
                        {'active_by_division' in data && (
                            <>
                                <Stats
                                    items={[
                                        {
                                            label: 'Total ticket',
                                            value: data.summary.total,
                                            note: 'Dibuat dalam periode pilihan',
                                        },
                                        {
                                            label: 'Ticket aktif',
                                            value: data.summary.active,
                                            note: 'Termasuk Pending, tanpa antrean',
                                        },
                                        {
                                            label: 'Ticket selesai',
                                            value: data.summary.completed,
                                            note: 'Status Solved / Closed saat ini',
                                        },
                                        {
                                            label: 'Dalam antrean',
                                            value: data.summary.queued,
                                            note: 'Dibuat dalam periode pilihan',
                                        },
                                    ]}
                                />
                                <div className="grid gap-4 lg:grid-cols-2">
                                    <Panel
                                        title="Ticket berdasarkan status"
                                        subtitle="Status saat ini"
                                    >
                                        <div
                                            className="grid grid-cols-4 gap-2 pt-4"
                                            role="group"
                                            aria-label="Jumlah ticket berdasarkan status"
                                        >
                                            {data.statuses.map((item) => (
                                                <Link
                                                    key={item.status}
                                                    to={reportTicketUrl(
                                                        filters,
                                                        { status: item.status },
                                                    )}
                                                    className="group min-w-0 rounded focus-visible:outline-2 focus-visible:outline-blue-600"
                                                    aria-label={`${reportStatuses.find((status) => status.value === item.status)?.label}: ${item.count} ticket`}
                                                >
                                                    <div className="flex h-44 items-end justify-center border-b border-slate-200 pt-7">
                                                        <div
                                                            className="relative w-3/5 max-w-12 rounded-t-md bg-blue-600 group-hover:bg-blue-700 motion-safe:transition-[height] motion-safe:duration-200"
                                                            style={{
                                                                height: `${(item.count / Math.max(1, ...data.statuses.map((s) => s.count))) * 100}%`,
                                                            }}
                                                        >
                                                            <strong className="absolute -top-6 right-0 left-0 text-center text-xs font-semibold text-slate-900 tabular-nums">
                                                                {item.count}
                                                            </strong>
                                                        </div>
                                                    </div>
                                                    <span className="mt-3 block text-center text-xs text-slate-700">
                                                        {
                                                            reportStatuses.find(
                                                                (status) =>
                                                                    status.value ===
                                                                    item.status,
                                                            )?.label
                                                        }
                                                    </span>
                                                </Link>
                                            ))}
                                        </div>
                                    </Panel>
                                    <Panel
                                        title="Beban aktif per divisi"
                                        subtitle="Ticket aktif yang dibuat dalam periode pilihan"
                                    >
                                        <Bars
                                            items={data.active_by_division.map(
                                                (item) => ({
                                                    label: item.name,
                                                    count: item.count,
                                                    to: reportTicketUrl(
                                                        {
                                                            ...filters,
                                                            division_id:
                                                                item.id ?? '',
                                                        },
                                                        { filter: 'active' },
                                                    ),
                                                }),
                                            )}
                                        />
                                    </Panel>
                                </div>
                            </>
                        )}
                        {'first_response' in data && (
                            <SlaContent data={data} filters={filters} />
                        )}
                        {'categories' in data && (
                            <div className="grid gap-4 lg:grid-cols-2">
                                {[
                                    {
                                        title: 'Kategori global',
                                        dimension: 'category',
                                        items: data.categories,
                                    },
                                    {
                                        title: 'Subkategori divisi',
                                        dimension: 'subcategory',
                                        items: data.subcategories,
                                    },
                                    {
                                        title: 'Sebaran Site',
                                        dimension: 'site',
                                        items: data.sites,
                                    },
                                    {
                                        title: 'Sebaran Zone',
                                        dimension: 'zone',
                                        items: data.zones,
                                    },
                                ].map((group) => (
                                    <Panel
                                        key={group.dimension}
                                        title={group.title}
                                    >
                                        <Bars
                                            items={groupItems(
                                                group.items,
                                                group.dimension,
                                            )}
                                        />
                                    </Panel>
                                ))}
                            </div>
                        )}
                        {'ratings' in data && (
                            <>
                                <Stats
                                    items={[
                                        {
                                            label: 'Rata-rata kepuasan',
                                            value:
                                                data.summary.average === null
                                                    ? 'Belum ada data'
                                                    : `${data.summary.average.toLocaleString('id-ID', { maximumFractionDigits: 1 })} / 5`,
                                            note: `${data.summary.total} ulasan`,
                                        },
                                        {
                                            label: 'Total ulasan',
                                            value: data.summary.total,
                                            note: 'Berdasarkan tanggal pengisian',
                                        },
                                        {
                                            label: 'Good',
                                            value: data.summary.good,
                                            note: 'Rating 3-5',
                                        },
                                        {
                                            label: 'Bad',
                                            value: data.summary.bad,
                                            note: 'Rating 1-2',
                                        },
                                    ]}
                                />
                                <section
                                    className={`${panelClass} flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between`}
                                >
                                    <div>
                                        <h2 className="text-sm font-semibold">
                                            Belum mengisi ulasan
                                        </h2>
                                        <p className="mt-1 text-xs text-slate-600">
                                            Ticket Solved / Closed dengan
                                            tanggal solved dalam periode
                                            pilihan.
                                        </p>
                                    </div>
                                    <div className="flex items-center gap-4">
                                        <strong className="text-2xl font-semibold tabular-nums">
                                            {data.summary.unreviewed}
                                        </strong>
                                        <Link
                                            className={linkClass}
                                            to={reportTicketUrl(filters, {
                                                filter: 'unreviewed',
                                            })}
                                        >
                                            Lihat ticket
                                        </Link>
                                    </div>
                                </section>
                                <div className="grid gap-4 lg:grid-cols-2">
                                    <Panel title="Sebaran rating">
                                        <Bars
                                            items={data.ratings.map((item) => ({
                                                label: `${item.rating} bintang`,
                                                count: item.count,
                                                tone:
                                                    item.rating <= 2
                                                        ? 'bad'
                                                        : undefined,
                                                onClick: () =>
                                                    setReviewSelection({
                                                        rating: item.rating,
                                                        label: `Ulasan ${item.rating} bintang`,
                                                    }),
                                            }))}
                                        />
                                    </Panel>
                                    <Panel
                                        title="Kepuasan per divisi"
                                        subtitle="Good: rating 3-5. Bad: rating 1-2."
                                    >
                                        <table className="w-full text-left text-xs">
                                            <thead className="bg-slate-50 text-slate-600">
                                                <tr>
                                                    {[
                                                        'Divisi',
                                                        'Good',
                                                        'Bad',
                                                        'Total ulasan',
                                                    ].map((label) => (
                                                        <th
                                                            key={label}
                                                            className="px-2 py-3 font-medium"
                                                        >
                                                            {label}
                                                        </th>
                                                    ))}
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {data.divisions.map(
                                                    (division) => (
                                                        <tr
                                                            key={division.id}
                                                            className="border-b border-slate-100 last:border-0"
                                                        >
                                                            <td className="max-w-36 px-2 py-2 break-words">
                                                                {division.name}
                                                            </td>
                                                            {(
                                                                [
                                                                    'good',
                                                                    'bad',
                                                                    'total',
                                                                ] as const
                                                            ).map((group) => (
                                                                <td
                                                                    key={group}
                                                                    className="px-2 py-1"
                                                                >
                                                                    <button
                                                                        type="button"
                                                                        className={`${linkClass} ${group === 'bad' ? 'text-red-700' : group === 'good' ? 'text-emerald-700' : ''}`}
                                                                        onClick={() =>
                                                                            setReviewSelection(
                                                                                {
                                                                                    division_id:
                                                                                        division.id,
                                                                                    ...(group ===
                                                                                    'total'
                                                                                        ? {}
                                                                                        : {
                                                                                              rating_group:
                                                                                                  group,
                                                                                          }),
                                                                                    label: `${group === 'total' ? 'Semua ulasan' : group === 'good' ? 'Good' : 'Bad'}: ${division.name}`,
                                                                                },
                                                                            )
                                                                        }
                                                                        aria-label={`${group === 'total' ? 'Total ulasan' : group}: ${division.name}`}
                                                                    >
                                                                        {
                                                                            division[
                                                                                group
                                                                            ]
                                                                        }
                                                                    </button>
                                                                </td>
                                                            ))}
                                                        </tr>
                                                    ),
                                                )}
                                            </tbody>
                                        </table>
                                    </Panel>
                                </div>
                                <Reviews
                                    key={JSON.stringify({
                                        ...filters,
                                        ...reviewSelection,
                                    })}
                                    filters={filters}
                                    selection={reviewSelection}
                                    onReset={() => setReviewSelection({})}
                                />
                            </>
                        )}
                    </div>
                ))}
        </div>
    );
}
