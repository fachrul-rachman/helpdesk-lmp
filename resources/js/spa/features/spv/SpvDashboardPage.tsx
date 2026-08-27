import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';

import { formatDateTimeId, formatSlaMinutes } from '../../components/common/format';
import { Button } from '../../components/ui/button';
import { api } from '../../lib/axios';
import { getEcho } from '../../lib/echo';
import type { SpvAnalyticsResponse } from '../../types/spv';

export function SpvDashboardPage() {
    const [data, setData] = useState<SpvAnalyticsResponse | null>(null);
    const [isLoading, setIsLoading] = useState(true);
    const [errorMessage, setErrorMessage] = useState<string | null>(null);

    async function load() {
        setIsLoading(true);
        setErrorMessage(null);
        try {
            const response = await api.get<SpvAnalyticsResponse>('/api/spv/analytics');
            setData(response.data);
        } catch (error: any) {
            const message =
                error?.response?.data?.message ?? 'Gagal memuat dashboard. Silakan coba lagi.';
            setErrorMessage(String(message));
        } finally {
            setIsLoading(false);
        }
    }

    useEffect(() => {
        load();
        const echo = getEcho();
        const channel = echo.private('dashboard.spv');

        const handler = () => {
            load();
        };

        channel.listen('.TicketCreated', handler);
        channel.listen('.TicketAssigned', handler);
        channel.listen('.TicketStatusChanged', handler);
        channel.listen('.SlaWarning', handler);
        channel.listen('.AiConversationUpdated', handler);

        return () => {
            channel.stopListening('.TicketCreated');
            channel.stopListening('.TicketAssigned');
            channel.stopListening('.TicketStatusChanged');
            channel.stopListening('.SlaWarning');
            channel.stopListening('.AiConversationUpdated');
            echo.leave('dashboard.spv');
        };
    }, []);

    if (isLoading) {
        return <div className="text-sm text-slate-600">Memuat dashboard…</div>;
    }

    if (errorMessage) {
        return (
            <div className="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                {errorMessage}
            </div>
        );
    }

    if (!data) {
        return (
            <div className="rounded-lg border border-slate-200 bg-white p-4 text-sm text-slate-600">
                Data tidak tersedia.
            </div>
        );
    }

    return (
        <div className="space-y-4">
            <div className="flex items-center justify-between gap-3">
                <h1 className="text-lg font-semibold text-slate-900">Dashboard</h1>
                <div className="hidden lg:block">
                    <Link to="/spv/tickets/create">
                        <Button>Buat Ticket Manual</Button>
                    </Link>
                </div>
            </div>

            <div className="grid gap-3 sm:grid-cols-2">
                <div className="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                    <div className="text-sm font-semibold text-slate-900">Total Ticket</div>
                    <div className="mt-2 text-3xl font-bold text-slate-900">{data.tickets.total}</div>
                    <div className="mt-1 text-xs text-slate-600">
                        Update: {formatDateTimeId(new Date().toISOString())}
                    </div>
                </div>
                <div className="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                    <div className="text-sm font-semibold text-slate-900">Ticket Aktif</div>
                    <div className="mt-2 text-3xl font-bold text-slate-900">{data.tickets.active}</div>
                    <div className="mt-1 text-xs text-slate-600">
                        Status: new/open/pending/on_progress
                    </div>
                </div>
            </div>

            <div className="grid gap-3 lg:grid-cols-2">
                <div className="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                    <div className="text-sm font-semibold text-slate-900">Ticket per Divisi</div>
                    {data.tickets.per_division.length === 0 ? (
                        <div className="mt-3 text-sm text-slate-600">Belum ada data.</div>
                    ) : (
                        <div className="mt-3 space-y-2">
                            {data.tickets.per_division.map((row) => (
                                <div key={row.division_id} className="flex items-center justify-between text-sm">
                                    <div className="text-slate-700">{row.division_name}</div>
                                    <div className="font-semibold text-slate-900">{row.count}</div>
                                </div>
                            ))}
                        </div>
                    )}
                </div>

                <div className="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                    <div className="text-sm font-semibold text-slate-900">Rata-rata SLA First Respond</div>
                    <div className="mt-2 text-2xl font-bold text-slate-900">
                        {formatSlaMinutes(data.sla_fr.average_minutes_overall)}
                    </div>
                    {data.sla_fr.per_division.length ? (
                        <div className="mt-3 space-y-2">
                            {data.sla_fr.per_division.map((row) => (
                                <div key={row.division_id} className="flex items-center justify-between text-sm">
                                    <div className="text-slate-700">{row.division_name}</div>
                                    <div className="font-semibold text-slate-900">
                                        {formatSlaMinutes(row.average_minutes)}
                                    </div>
                                </div>
                            ))}
                        </div>
                    ) : (
                        <div className="mt-3 text-sm text-slate-600">Belum ada data.</div>
                    )}
                </div>
            </div>
        </div>
    );
}
