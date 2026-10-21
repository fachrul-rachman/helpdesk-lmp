import type { TicketStatus } from '../../components/common/badges';

export type ReportPeriod = 'month' | 'week' | 'previous' | 'year' | 'custom';
export type DateRange = { start_date: string; end_date: string };
export type ReportFilters = DateRange & { division_id: string };
export type DivisionOption = { id: string; name: string };

export const reportStatuses: Array<{ value: TicketStatus; label: string }> = [
    { value: 'new', label: 'Baru' },
    { value: 'open', label: 'Open' },
    { value: 'pending', label: 'Pending' },
    { value: 'on_progress', label: 'On Progress' },
    { value: 'queue', label: 'Antrean' },
    { value: 'solved', label: 'Solved' },
    { value: 'closed', label: 'Closed' },
];

export const reportSelectClass =
    'min-h-11 w-full min-w-0 rounded-md border border-slate-200 bg-white px-3 py-2 text-base focus:outline-none focus:ring-2 focus:ring-blue-500 sm:text-sm';

export function periodRange(
    period: Exclude<ReportPeriod, 'custom'>,
    now = new Date(),
): DateRange {
    const parts = new Intl.DateTimeFormat('en', {
        timeZone: 'Asia/Jakarta',
        year: 'numeric',
        month: 'numeric',
        day: 'numeric',
    }).formatToParts(now);
    const value = (type: string) =>
        Number(parts.find((part) => part.type === type)?.value);
    const year = value('year'),
        month = value('month') - 1,
        day = value('day');
    const date = (y: number, m: number, d: number) =>
        new Date(Date.UTC(y, m, d)).toISOString().slice(0, 10);

    if (period === 'previous') {
        return {
            start_date: date(year, month - 1, 1),
            end_date: date(year, month, 0),
        };
    }

    return {
        start_date:
            period === 'week'
                ? date(year, month, day - 6)
                : date(year, period === 'year' ? 0 : month, 1),
        end_date: date(year, month, day),
    };
}

export function validRange(range: DateRange): boolean {
    const from = Date.parse(range.start_date),
        to = Date.parse(range.end_date);

    return (
        Number.isFinite(from) &&
        Number.isFinite(to) &&
        from <= to &&
        (to - from) / 86400000 <= 366
    );
}

export function reportTicketUrl(
    filters: ReportFilters,
    extra: Record<string, string> = {},
): string {
    const params = new URLSearchParams({ report: '1', ...filters, ...extra });

    return `/spv/tickets?${params}`;
}
