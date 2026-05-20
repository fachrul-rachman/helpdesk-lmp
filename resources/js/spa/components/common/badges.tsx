import { cn } from '../../lib/utils';

export type TicketStatus = 'new' | 'open' | 'pending' | 'on_progress' | 'queue' | 'solved' | 'closed';
export type TicketPriority = 'low' | 'medium' | 'high';

const statusLabel: Record<TicketStatus, string> = {
    new: 'Baru',
    open: 'Open',
    pending: 'Pending',
    on_progress: 'Diproses',
    queue: 'Antrian',
    solved: 'Selesai',
    closed: 'Ditutup',
};

const statusClass: Record<TicketStatus, string> = {
    new: 'bg-blue-50 text-blue-700 ring-blue-200',
    open: 'bg-green-50 text-green-700 ring-green-200',
    pending: 'bg-amber-50 text-amber-700 ring-amber-200',
    on_progress: 'bg-purple-50 text-purple-700 ring-purple-200',
    queue: 'bg-slate-100 text-slate-700 ring-slate-200',
    solved: 'bg-teal-50 text-teal-700 ring-teal-200',
    closed: 'bg-rose-50 text-rose-700 ring-rose-200',
};

export function StatusBadge({ status }: { status: TicketStatus }) {
    return (
        <span
            className={cn(
                'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold ring-1 ring-inset',
                statusClass[status]
            )}
        >
            {statusLabel[status]}
        </span>
    );
}

const priorityLabel: Record<TicketPriority, string> = {
    low: 'Rendah',
    medium: 'Sedang',
    high: 'Tinggi',
};

const priorityClass: Record<TicketPriority, string> = {
    low: 'bg-blue-50 text-blue-700 ring-blue-200',
    medium: 'bg-orange-50 text-orange-700 ring-orange-200',
    high: 'bg-rose-50 text-rose-700 ring-rose-200',
};

export function PriorityBadge({ priority }: { priority: TicketPriority }) {
    return (
        <span
            className={cn(
                'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold ring-1 ring-inset',
                priorityClass[priority]
            )}
        >
            {priorityLabel[priority]}
        </span>
    );
}

