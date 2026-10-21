import type { DateRange, ReportPeriod } from './reportFilters';
import { periodRange, reportSelectClass } from './reportFilters';

export function ReportDateFields({
    period,
    range,
    onChange,
}: {
    period: ReportPeriod;
    range: DateRange;
    onChange: (period: ReportPeriod, range: DateRange) => void;
}) {
    return (
        <>
            <label className="grid min-w-0 gap-1 text-xs font-medium text-slate-600">
                Rentang waktu
                <select
                    className={reportSelectClass}
                    value={period}
                    onChange={(event) => {
                        const next = event.target.value as ReportPeriod;
                        onChange(
                            next,
                            next === 'custom' ? range : periodRange(next),
                        );
                    }}
                >
                    <option value="month">Bulan ini</option>
                    <option value="week">7 hari terakhir</option>
                    <option value="previous">Bulan lalu</option>
                    <option value="year">Tahun ini</option>
                    <option value="custom">Rentang khusus</option>
                </select>
            </label>
            {period === 'custom' && (
                <>
                    <label className="grid min-w-0 gap-1 text-xs font-medium text-slate-600">
                        Tanggal awal
                        <input
                            type="date"
                            className={reportSelectClass}
                            value={range.start_date}
                            max={range.end_date || undefined}
                            onChange={(event) =>
                                onChange(period, {
                                    ...range,
                                    start_date: event.target.value,
                                })
                            }
                        />
                    </label>
                    <label className="grid min-w-0 gap-1 text-xs font-medium text-slate-600">
                        Tanggal akhir
                        <input
                            type="date"
                            className={reportSelectClass}
                            value={range.end_date}
                            min={range.start_date || undefined}
                            onChange={(event) =>
                                onChange(period, {
                                    ...range,
                                    end_date: event.target.value,
                                })
                            }
                        />
                    </label>
                </>
            )}
        </>
    );
}
