import { useEffect, useMemo, useState } from 'react';

import { Button } from '../../components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '../../components/ui/card';
import { Input } from '../../components/ui/input';
import { Label } from '../../components/ui/label';
import { Table, Td, Th } from '../../components/ui/table';
import { api } from '../../lib/axios';
import type { AdminAuditLogListItem, AdminAuditLogsIndexResponse } from '../../types/admin';

function Modal({
    title,
    description,
    children,
    onClose,
}: {
    title: string;
    description?: string;
    children: React.ReactNode;
    onClose: () => void;
}) {
    return (
        <div className="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4" role="dialog" aria-modal="true">
            <div className="w-full max-w-3xl rounded-xl bg-white shadow-xl">
                <div className="flex items-start justify-between gap-4 border-b border-slate-200 px-4 py-3">
                    <div>
                        <div className="text-sm font-semibold text-slate-900">{title}</div>
                        {description ? <div className="mt-1 text-xs text-slate-600">{description}</div> : null}
                    </div>
                    <button
                        type="button"
                        className="rounded-md px-2 py-1 text-sm text-slate-500 hover:bg-slate-100 hover:text-slate-700"
                        onClick={onClose}
                        aria-label="Tutup"
                    >
                        x
                    </button>
                </div>
                <div className="max-h-[80vh] overflow-auto px-4 py-4">{children}</div>
            </div>
        </div>
    );
}

function formatDate(iso: string | null) {
    if (!iso) return '-';
    try {
        return new Date(iso).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' });
    } catch {
        return iso;
    }
}

export function AdminAuditLogsPage() {
    const [data, setData] = useState<AdminAuditLogsIndexResponse | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const [action, setAction] = useState('');
    const [userId, setUserId] = useState('');
    const [dateFrom, setDateFrom] = useState('');
    const [dateTo, setDateTo] = useState('');
    const [page, setPage] = useState(1);

    const [selected, setSelected] = useState<AdminAuditLogListItem | null>(null);

    const queryParams = useMemo(() => {
        const params: Record<string, string | number> = { page, per_page: 50 };
        if (action.trim()) params.action = action.trim();
        if (userId.trim()) params.user_id = userId.trim();
        if (dateFrom) params.date_from = dateFrom;
        if (dateTo) params.date_to = dateTo;
        return params;
    }, [action, dateFrom, dateTo, page, userId]);

    async function loadLogs() {
        setLoading(true);
        setError(null);
        try {
            const response = await api.get<AdminAuditLogsIndexResponse>('/api/admin/audit-logs', { params: queryParams });
            setData(response.data);
        } catch (e: any) {
            setError(e?.response?.data?.message ?? 'Gagal memuat audit log.');
        } finally {
            setLoading(false);
        }
    }

    useEffect(() => {
        loadLogs();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [queryParams]);

    function resetFilters() {
        setAction('');
        setUserId('');
        setDateFrom('');
        setDateTo('');
        setPage(1);
    }

    return (
        <div className="space-y-4">
            <div>
                <h1 className="text-lg font-semibold text-slate-900">Audit Log</h1>
                <p className="mt-1 text-sm text-slate-600">Jejak aktivitas admin/spv/pic di sistem.</p>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>Filter</CardTitle>
                    <CardDescription>Filter sederhana berdasarkan action, user, dan tanggal.</CardDescription>
                </CardHeader>
                <CardContent>
                    <div className="grid gap-3 lg:grid-cols-4">
                        <div className="space-y-1.5 lg:col-span-2">
                            <Label>Action</Label>
                            <Input
                                value={action}
                                onChange={(e) => {
                                    setAction(e.target.value);
                                    setPage(1);
                                }}
                                placeholder="Contoh: admin.user.updated"
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label>User ID</Label>
                            <Input
                                value={userId}
                                onChange={(e) => {
                                    setUserId(e.target.value);
                                    setPage(1);
                                }}
                                placeholder="UUID user"
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label>Tanggal Mulai</Label>
                            <Input
                                type="date"
                                value={dateFrom}
                                onChange={(e) => {
                                    setDateFrom(e.target.value);
                                    setPage(1);
                                }}
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label>Tanggal Akhir</Label>
                            <Input
                                type="date"
                                value={dateTo}
                                onChange={(e) => {
                                    setDateTo(e.target.value);
                                    setPage(1);
                                }}
                            />
                        </div>
                        <div className="flex items-end gap-2 lg:col-span-4">
                            <Button variant="secondary" onClick={resetFilters}>
                                Reset
                            </Button>
                            <Button variant="secondary" onClick={loadLogs} disabled={loading}>
                                {loading ? 'Memuat...' : 'Refresh'}
                            </Button>
                            <div className="ml-auto text-xs text-slate-600">{data ? `Total: ${data.meta.total}` : '-'}</div>
                        </div>
                    </div>
                    {error ? <div className="mt-3 text-sm text-red-600">{error}</div> : null}
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Daftar Log</CardTitle>
                    <CardDescription>Klik "Detail" untuk melihat payload JSON.</CardDescription>
                </CardHeader>
                <CardContent className="p-0">
                    <div className="overflow-auto">
                        <Table>
                            <thead>
                                <tr>
                                    <Th>Waktu</Th>
                                    <Th>User</Th>
                                    <Th>Action</Th>
                                    <Th>Subject</Th>
                                    <Th>IP</Th>
                                    <Th className="text-right">Aksi</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {data?.data?.length ? (
                                    data.data.map((log) => (
                                        <tr key={log.id}>
                                            <Td className="whitespace-nowrap">{formatDate(log.created_at)}</Td>
                                            <Td>
                                                {log.user ? (
                                                    <div>
                                                        <div className="font-medium text-slate-900">{log.user.name}</div>
                                                        <div className="text-xs text-slate-600">{log.user.role}</div>
                                                    </div>
                                                ) : (
                                                    '-'
                                                )}
                                            </Td>
                                            <Td className="whitespace-nowrap font-medium text-slate-900">{log.action}</Td>
                                            <Td className="whitespace-nowrap">
                                                {log.subject_type ? (
                                                    <div>
                                                        <div className="text-xs text-slate-600">{log.subject_type}</div>
                                                        <div className="text-xs text-slate-500">{log.subject_id}</div>
                                                    </div>
                                                ) : (
                                                    '-'
                                                )}
                                            </Td>
                                            <Td className="whitespace-nowrap">{log.ip_address ?? '-'}</Td>
                                            <Td className="whitespace-nowrap text-right">
                                                <Button variant="secondary" onClick={() => setSelected(log)}>
                                                    Detail
                                                </Button>
                                            </Td>
                                        </tr>
                                    ))
                                ) : (
                                    <tr>
                                        <Td colSpan={6} className="py-10 text-center text-slate-500">
                                            {loading ? 'Memuat...' : 'Belum ada data.'}
                                        </Td>
                                    </tr>
                                )}
                            </tbody>
                        </Table>
                    </div>
                    <div className="flex items-center justify-between gap-3 px-4 py-3">
                        <div className="text-xs text-slate-600">Halaman {data?.meta.page ?? page}</div>
                        <div className="flex items-center gap-2">
                            <Button
                                variant="secondary"
                                onClick={() => setPage((p) => Math.max(1, p - 1))}
                                disabled={page <= 1 || loading}
                            >
                                Sebelumnya
                            </Button>
                            <Button
                                variant="secondary"
                                onClick={() => setPage((p) => p + 1)}
                                disabled={loading || (data ? data.data.length < data.meta.per_page : false)}
                            >
                                Berikutnya
                            </Button>
                        </div>
                    </div>
                </CardContent>
            </Card>

            {selected ? (
                <Modal
                    title="Detail Audit Log"
                    description={`${selected.action} - ${formatDate(selected.created_at)}`}
                    onClose={() => setSelected(null)}
                >
                    <div className="space-y-3">
                        <div className="grid gap-3 lg:grid-cols-2">
                            <div className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <div className="text-xs font-semibold text-slate-700">User</div>
                                <div className="mt-1 text-sm text-slate-900">
                                    {selected.user ? `${selected.user.name} (${selected.user.role})` : '-'}
                                </div>
                                {selected.user?.id ? <div className="mt-1 text-xs text-slate-500">{selected.user.id}</div> : null}
                            </div>
                            <div className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <div className="text-xs font-semibold text-slate-700">Subject</div>
                                <div className="mt-1 text-sm text-slate-900">{selected.subject_type ?? '-'}</div>
                                {selected.subject_id ? <div className="mt-1 text-xs text-slate-500">{selected.subject_id}</div> : null}
                            </div>
                        </div>

                        <div className="rounded-lg border border-slate-200 bg-white">
                            <div className="border-b border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700">
                                Payload
                            </div>
                            <pre className="max-h-[50vh] overflow-auto whitespace-pre-wrap break-words px-3 py-3 text-xs text-slate-700">
                                {JSON.stringify(selected.payload, null, 2)}
                            </pre>
                        </div>
                    </div>
                </Modal>
            ) : null}
        </div>
    );
}
