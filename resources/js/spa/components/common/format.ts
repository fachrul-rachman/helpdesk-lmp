const BUSINESS_TIMEZONE = 'Asia/Jakarta';

export function formatDateTimeId(iso: string | null | undefined) {
    if (!iso) return '-';
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return '-';

    return new Intl.DateTimeFormat('id-ID', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
        timeZone: BUSINESS_TIMEZONE,
    }).format(date);
}

export function formatDateId(iso: string | null | undefined) {
    if (!iso) {
        return '';
    }

    const date = new Date(iso);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    return new Intl.DateTimeFormat('id-ID', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        timeZone: BUSINESS_TIMEZONE,
    }).format(date);
}

export function formatTimeId(iso: string | null | undefined) {
    if (!iso) return '';
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return '';

    return new Intl.DateTimeFormat('id-ID', {
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
        timeZone: BUSINESS_TIMEZONE,
    }).format(date);
}

export function formatSlaMinutes(totalMinutes: number) {
    const minutes = Math.max(0, Math.floor(totalMinutes));

    if (minutes >= 24 * 60) {
        return `${Math.floor(minutes / (24 * 60))} hari`;
    }

    const hours = Math.floor(minutes / 60);
    const remainder = minutes % 60;

    return [hours > 0 ? `${hours} jam` : '', `${remainder} menit`]
        .filter(Boolean)
        .join(' ');
}

export function formatSlaRemaining(deadlineIso: string | null | undefined) {
    if (!deadlineIso) {
        return { label: '-', tone: 'muted' as const };
    }

    const deadline = new Date(deadlineIso);

    if (Number.isNaN(deadline.getTime())) {
        return { label: '-', tone: 'muted' as const };
    }

    const diffMs = deadline.getTime() - Date.now();
    const isOverdue = diffMs < 0;

    return {
        label: `${isOverdue ? 'Overdue' : 'Sisa'} ${formatSlaMinutes(Math.abs(diffMs) / 60000)}`,
        tone: isOverdue ? ('danger' as const) : ('ok' as const),
    };
}
