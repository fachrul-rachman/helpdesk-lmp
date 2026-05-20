import { useEffect, useMemo, useState } from 'react';

import { Button } from '../../components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '../../components/ui/card';
import { Input } from '../../components/ui/input';
import { Label } from '../../components/ui/label';
import { Table, Td, Th } from '../../components/ui/table';
import { api } from '../../lib/axios';

type TemplateRow = {
    id: number;
    meta_template_id: string;
    name: string;
    language: string | null;
    status: string | null;
    category: string | null;
    sub_category: string | null;
    components: any[] | null;
    last_synced_at: string | null;
    updated_at: string | null;
};

type ApiListMeta = { total: number; page: number; per_page: number };
type ApiListResponse<T> = { data: T[]; meta: ApiListMeta };

function formatDate(iso: string | null) {
    if (!iso) return '-';
    try {
        return new Date(iso).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' });
    } catch {
        return iso;
    }
}

function countBodyParams(components: any[] | null) {
    if (!components || !Array.isArray(components)) return 0;
    const body = components.find((c) => c && typeof c === 'object' && c.type === 'BODY');
    const text = body?.text;
    if (typeof text !== 'string') return 0;
    const matches = text.match(/\{\{\d+\}\}/g);
    return matches ? matches.length : 0;
}

export function AdminMetaTemplatesPage() {
    const [items, setItems] = useState<TemplateRow[]>([]);
    const [meta, setMeta] = useState<ApiListMeta>({ total: 0, page: 1, per_page: 100 });
    const [isLoading, setIsLoading] = useState(true);
    const [errorMessage, setErrorMessage] = useState<string | null>(null);

    const [search, setSearch] = useState('');
    const [isSyncing, setIsSyncing] = useState(false);
    const [page, setPage] = useState(1);

    const queryParams = useMemo(() => {
        const params: Record<string, string | number> = { per_page: 100, page };
        if (search.trim()) params.search = search.trim();
        return params;
    }, [page, search]);

    async function load() {
        setIsLoading(true);
        setErrorMessage(null);
        try {
            const resp = await api.get<ApiListResponse<TemplateRow>>('/api/admin/meta-templates', { params: queryParams });
            setItems(resp.data.data ?? []);
            setMeta(resp.data.meta ?? { total: 0, page: 1, per_page: 100 });
        } catch (e: any) {
            setErrorMessage(e?.response?.data?.message ?? 'Gagal memuat template.');
        } finally {
            setIsLoading(false);
        }
    }

    useEffect(() => {
        load();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [queryParams]);

    const lastSync = useMemo(() => {
        const times = items.map((i) => i.last_synced_at).filter(Boolean) as string[];
        if (!times.length) return null;
        return times.sort().at(-1) ?? null;
    }, [items]);

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-lg font-semibold text-slate-900">Template Message (Meta WhatsApp)</h1>
                    <p className="mt-1 text-sm text-slate-600">
                        Data diambil langsung dari Meta (WABA) dan disimpan ke database saat kamu klik Sync.
                    </p>
                </div>
                <Button
                    onClick={async () => {
                        setIsSyncing(true);
                        setErrorMessage(null);
                        try {
                            await api.post('/api/admin/meta-templates/sync');
                            await load();
                        } catch (e: any) {
                            setErrorMessage(e?.response?.data?.message ?? 'Gagal sync template.');
                        } finally {
                            setIsSyncing(false);
                        }
                    }}
                    disabled={isSyncing}
                >
                    {isSyncing ? 'Sync...' : 'Sync'}
                </Button>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>Filter</CardTitle>
                    <CardDescription>
                        Cari berdasarkan nama template atau ID Meta. {lastSync ? `Sync terakhir: ${formatDate(lastSync)}` : ''}
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                        <div className="w-full max-w-md space-y-1.5">
                            <Label>Cari</Label>
                            <Input
                                value={search}
                                onChange={(e) => {
                                    setSearch(e.target.value);
                                    setPage(1);
                                }}
                                placeholder="Nama / ID Meta"
                            />
                        </div>
                        <div className="flex items-center gap-2">
                            <Button
                                variant="secondary"
                                onClick={() => {
                                    setSearch('');
                                    setPage(1);
                                }}
                            >
                                Reset
                            </Button>
                            <Button variant="secondary" onClick={load} disabled={isLoading || isSyncing}>
                                {isLoading ? 'Memuat...' : 'Refresh'}
                            </Button>
                        </div>
                    </div>
                    {errorMessage ? <div className="mt-3 text-sm text-red-600">{errorMessage}</div> : null}
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Daftar Template</CardTitle>
                    <CardDescription>Total: {meta.total}</CardDescription>
                </CardHeader>
                <CardContent className="p-0">
                    <div className="overflow-auto">
                        <Table>
                            <thead>
                                <tr>
                                    <Th>Nama</Th>
                                    <Th>Status</Th>
                                    <Th>Kategori</Th>
                                    <Th>Language</Th>
                                    <Th>Params</Th>
                                    <Th>Meta ID</Th>
                                    <Th>Sync</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {items.length ? (
                                    items.map((t) => (
                                        <tr key={t.id}>
                                            <Td className="font-medium text-slate-900">{t.name}</Td>
                                            <Td className="whitespace-nowrap">{t.status ?? '-'}</Td>
                                            <Td className="whitespace-nowrap">
                                                {(t.category ?? '-') + (t.sub_category ? ` / ${t.sub_category}` : '')}
                                            </Td>
                                            <Td className="whitespace-nowrap">{t.language ?? '-'}</Td>
                                            <Td className="whitespace-nowrap">{countBodyParams(t.components)}</Td>
                                            <Td className="whitespace-nowrap text-xs text-slate-600">{t.meta_template_id}</Td>
                                            <Td className="whitespace-nowrap text-xs text-slate-600">
                                                {formatDate(t.last_synced_at)}
                                            </Td>
                                        </tr>
                                    ))
                                ) : (
                                    <tr>
                                        <Td colSpan={7} className="py-10 text-center text-slate-500">
                                            {isLoading ? 'Memuat...' : 'Belum ada data. Klik Sync untuk mengambil template.'}
                                        </Td>
                                    </tr>
                                )}
                            </tbody>
                        </Table>
                    </div>

                    <div className="flex items-center justify-between gap-3 px-4 py-3">
                        <div className="text-xs text-slate-600">Halaman {meta.page ?? page}</div>
                        <div className="flex items-center gap-2">
                            <Button
                                variant="secondary"
                                onClick={() => setPage((p) => Math.max(1, p - 1))}
                                disabled={page <= 1 || isLoading}
                            >
                                Sebelumnya
                            </Button>
                            <Button
                                variant="secondary"
                                onClick={() => setPage((p) => p + 1)}
                                disabled={isLoading || items.length < meta.per_page}
                            >
                                Berikutnya
                            </Button>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>
    );
}

