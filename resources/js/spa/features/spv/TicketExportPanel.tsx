import { useEffect, useState } from 'react';

import type { TicketStatus } from '../../components/common/badges';
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
    validRange,
} from './reportFilters';

export function TicketExportPanel({
    initial,
    initialPeriod,
    divisions: suppliedDivisions,
    onClose,
}: {
    initial?: ReportFilters;
    initialPeriod?: ReportPeriod;
    divisions?: DivisionOption[];
    onClose?: () => void;
}) {
    const [period, setPeriod] = useState<ReportPeriod>(
        initialPeriod ?? (initial ? 'custom' : 'month'),
    );
    const [filters, setFilters] = useState<ReportFilters>(
        () => initial ?? { ...periodRange('month'), division_id: '' },
    );
    const [statuses, setStatuses] = useState<TicketStatus[]>([
        'solved',
        'closed',
    ]);
    const [loadedDivisions, setDivisions] = useState<DivisionOption[]>([]);
    const divisions = suppliedDivisions ?? loadedDivisions;
    const [isExporting, setIsExporting] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (suppliedDivisions) {
            return;
        }

        const controller = new AbortController();
        api.get<{ data: DivisionOption[] }>('/api/divisions', {
            signal: controller.signal,
        })
            .then((response) => setDivisions(response.data.data))
            .catch(() => {
                if (!controller.signal.aborted) {
                    setError('Daftar divisi gagal dimuat.');
                }
            });

        return () => controller.abort();
    }, [suppliedDivisions]);

    async function download() {
        if (!validRange(filters) || statuses.length === 0 || isExporting) {
            return;
        }

        setIsExporting(true);
        setError(null);

        try {
            const response = await api.get<Blob>('/api/spv/tickets/export', {
                params: {
                    ...filters,
                    division_id: filters.division_id || undefined,
                    period: 'custom',
                    statuses,
                },
                responseType: 'blob',
            });
            const disposition = String(
                response.headers['content-disposition'] ?? '',
            );
            const filename =
                disposition.match(/filename="?([^";]+)"?/i)?.[1] ??
                'rekap-ticket.xlsx';
            const url = URL.createObjectURL(response.data),
                anchor = document.createElement('a');
            anchor.href = url;
            anchor.download = filename;
            document.body.appendChild(anchor);
            anchor.click();
            anchor.remove();
            window.setTimeout(() => URL.revokeObjectURL(url), 1000);
        } catch {
            setError('Ekspor gagal. Silakan coba lagi.');
        } finally {
            setIsExporting(false);
        }
    }

    return (
        <section
            className="space-y-4 rounded-xl border border-slate-200 bg-white p-4 sm:p-5"
            aria-label="Ekspor ticket"
        >
            <div className="flex items-center justify-between gap-3">
                <h2 className="text-sm font-semibold">Ekspor ticket</h2>
                {onClose && (
                    <Button
                        variant="ghost"
                        onClick={onClose}
                        disabled={isExporting}
                    >
                        Tutup
                    </Button>
                )}
            </div>
            <div className="grid min-w-0 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <ReportDateFields
                    period={period}
                    range={filters}
                    onChange={(next, range) => {
                        setPeriod(next);
                        setFilters({ ...filters, ...range });
                    }}
                />
                <label className="grid min-w-0 gap-1 text-xs font-medium text-slate-600">
                    Divisi
                    <select
                        className={reportSelectClass}
                        value={filters.division_id}
                        onChange={(event) =>
                            setFilters({
                                ...filters,
                                division_id: event.target.value,
                            })
                        }
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
            <fieldset>
                <legend className="mb-2 text-xs font-medium text-slate-600">
                    Status (bisa lebih dari satu)
                </legend>
                <div className="flex flex-wrap gap-2">
                    {reportStatuses.map(({ value, label }) => (
                        <label
                            key={value}
                            className="flex min-h-11 items-center gap-2 rounded-md border border-slate-200 px-3 text-sm has-checked:border-blue-600 has-checked:bg-blue-50"
                        >
                            <input
                                type="checkbox"
                                className="h-4 w-4 accent-blue-600"
                                checked={statuses.includes(value)}
                                onChange={(event) =>
                                    setStatuses(
                                        event.target.checked
                                            ? [...statuses, value]
                                            : statuses.filter(
                                                  (s) => s !== value,
                                              ),
                                    )
                                }
                            />
                            {label}
                        </label>
                    ))}
                </div>
            </fieldset>
            {!validRange(filters) && (
                <p className="text-sm text-red-700" role="alert">
                    Pilih rentang tanggal yang valid, maksimal satu tahun.
                </p>
            )}
            {statuses.length === 0 && (
                <p className="text-sm text-red-700" role="alert">
                    Pilih minimal satu status.
                </p>
            )}
            {error && (
                <p className="text-sm text-red-700" role="alert">
                    {error}
                </p>
            )}
            <Button
                className="min-h-11"
                disabled={
                    isExporting || !validRange(filters) || statuses.length === 0
                }
                onClick={download}
            >
                {isExporting ? 'Menyiapkan...' : 'Unduh XLSX'}
            </Button>
        </section>
    );
}
