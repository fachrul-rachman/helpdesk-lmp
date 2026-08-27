import { formatDateId } from './format';

export function ChatDateSeparator({
    date,
}: {
    date: string | null | undefined;
}) {
    return (
        <div
            className="flex w-full items-center gap-3 py-1"
            aria-label={`Tanggal ${formatDateId(date)}`}
        >
            <div className="h-px flex-1 bg-slate-200" />
            <time
                dateTime={date ?? undefined}
                className="text-xs font-medium text-slate-500"
            >
                {formatDateId(date)}
            </time>
            <div className="h-px flex-1 bg-slate-200" />
        </div>
    );
}
