import { useEffect, useMemo, useState } from 'react';

import { Button } from '../../components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '../../components/ui/card';
import { Input } from '../../components/ui/input';
import { Label } from '../../components/ui/label';
import { Table, Td, Th } from '../../components/ui/table';
import { api } from '../../lib/axios';

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
        <div
            className="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4"
            role="dialog"
            aria-modal="true"
        >
            <div className="w-full max-w-4xl rounded-xl bg-white shadow-xl">
                <div className="flex items-start justify-between gap-4 border-b border-slate-200 px-4 py-3">
                    <div>
                        <div className="text-sm font-semibold text-slate-900">
                            {title}
                        </div>
                        {description ? (
                            <div className="mt-1 text-xs text-slate-600">
                                {description}
                            </div>
                        ) : null}
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
                <div className="max-h-[80vh] overflow-auto px-4 py-4">
                    {children}
                </div>
            </div>
        </div>
    );
}

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
    if (!iso) {
        return '-';
    }

    try {
        return new Date(iso).toLocaleString('id-ID', {
            dateStyle: 'medium',
            timeStyle: 'short',
        });
    } catch {
        return iso;
    }
}

function countBodyParams(components: any[] | null) {
    if (!components || !Array.isArray(components)) {
        return 0;
    }

    const body = components.find(
        (c) => c && typeof c === 'object' && c.type === 'BODY',
    );
    const text = body?.text;

    if (typeof text !== 'string') {
        return 0;
    }

    const matches = text.match(/\{\{\d+\}\}/g);

    return matches ? matches.length : 0;
}

function asText(value: unknown) {
    return typeof value === 'string' && value.trim() ? value : '-';
}

function getBodyText(components: any[] | null) {
    if (!components || !Array.isArray(components)) {
        return '';
    }

    const body = components.find(
        (c) => c && typeof c === 'object' && c.type === 'BODY',
    );

    return typeof body?.text === 'string' ? body.text : '';
}

function componentTitle(component: any) {
    const type =
        typeof component?.type === 'string' ? component.type : 'COMPONENT';
    const format =
        typeof component?.format === 'string' ? component.format : null;

    return format ? `${type} / ${format}` : type;
}

function renderTemplateComponent(component: any, index: number) {
    const text = typeof component?.text === 'string' ? component.text : null;
    const buttons = Array.isArray(component?.buttons) ? component.buttons : [];
    const example = component?.example;

    return (
        <div
            key={`${component?.type ?? 'component'}-${index}`}
            className="rounded-lg border border-slate-200 bg-white"
        >
            <div className="border-b border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-700">
                {componentTitle(component)}
            </div>
            <div className="space-y-3 px-3 py-3">
                {text ? (
                    <div>
                        <div className="mb-1 text-xs font-semibold text-slate-600">
                            Konten
                        </div>
                        <pre className="rounded-md bg-slate-50 px-3 py-2 text-sm leading-6 break-words whitespace-pre-wrap text-slate-900">
                            {text}
                        </pre>
                    </div>
                ) : null}

                {buttons.length ? (
                    <div>
                        <div className="mb-1 text-xs font-semibold text-slate-600">
                            Buttons
                        </div>
                        <div className="space-y-2">
                            {buttons.map((button: any, buttonIndex: number) => (
                                <div
                                    key={`${button?.type ?? 'button'}-${buttonIndex}`}
                                    className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2"
                                >
                                    <div className="text-sm font-medium text-slate-900">
                                        {asText(button?.text)}
                                    </div>
                                    <div className="mt-1 text-xs text-slate-600">
                                        {asText(button?.type)}
                                        {button?.url ? ` - ${button.url}` : ''}
                                        {button?.phone_number
                                            ? ` - ${button.phone_number}`
                                            : ''}
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                ) : null}

                {example ? (
                    <div>
                        <div className="mb-1 text-xs font-semibold text-slate-600">
                            Example / Parameter
                        </div>
                        <pre className="max-h-56 overflow-auto rounded-md bg-slate-950 px-3 py-2 text-xs break-words whitespace-pre-wrap text-slate-100">
                            {JSON.stringify(example, null, 2)}
                        </pre>
                    </div>
                ) : null}

                {!text && !buttons.length && !example ? (
                    <pre className="max-h-56 overflow-auto rounded-md bg-slate-50 px-3 py-2 text-xs break-words whitespace-pre-wrap text-slate-700">
                        {JSON.stringify(component, null, 2)}
                    </pre>
                ) : null}
            </div>
        </div>
    );
}

export function AdminMetaTemplatesPage() {
    const [items, setItems] = useState<TemplateRow[]>([]);
    const [meta, setMeta] = useState<ApiListMeta>({
        total: 0,
        page: 1,
        per_page: 100,
    });
    const [isLoading, setIsLoading] = useState(true);
    const [errorMessage, setErrorMessage] = useState<string | null>(null);
    const [selected, setSelected] = useState<TemplateRow | null>(null);

    const [search, setSearch] = useState('');
    const [isSyncing, setIsSyncing] = useState(false);
    const [page, setPage] = useState(1);

    const queryParams = useMemo(() => {
        const params: Record<string, string | number> = { per_page: 100, page };

        if (search.trim()) {
            params.search = search.trim();
        }

        return params;
    }, [page, search]);

    async function load() {
        setIsLoading(true);
        setErrorMessage(null);

        try {
            const resp = await api.get<ApiListResponse<TemplateRow>>(
                '/api/admin/meta-templates',
                { params: queryParams },
            );
            setItems(resp.data.data ?? []);
            setMeta(resp.data.meta ?? { total: 0, page: 1, per_page: 100 });
        } catch (e: any) {
            setErrorMessage(
                e?.response?.data?.message ?? 'Gagal memuat template.',
            );
        } finally {
            setIsLoading(false);
        }
    }

    useEffect(() => {
        // eslint-disable-next-line react-hooks/set-state-in-effect
        load();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [queryParams]);

    const lastSync = useMemo(() => {
        const times = items
            .map((i) => i.last_synced_at)
            .filter(Boolean) as string[];

        if (!times.length) {
            return null;
        }

        return times.sort().at(-1) ?? null;
    }, [items]);

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-lg font-semibold text-slate-900">
                        Template Message (Meta WhatsApp)
                    </h1>
                    <p className="mt-1 text-sm text-slate-600">
                        Data diambil langsung dari Meta (WABA) dan disimpan ke
                        database saat kamu klik Sync.
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
                            setErrorMessage(
                                e?.response?.data?.message ??
                                    'Gagal sync template.',
                            );
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
                        Cari berdasarkan nama template atau ID Meta.{' '}
                        {lastSync
                            ? `Sync terakhir: ${formatDate(lastSync)}`
                            : ''}
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
                            <Button
                                variant="secondary"
                                onClick={load}
                                disabled={isLoading || isSyncing}
                            >
                                {isLoading ? 'Memuat...' : 'Refresh'}
                            </Button>
                        </div>
                    </div>
                    {errorMessage ? (
                        <div className="mt-3 text-sm text-red-600">
                            {errorMessage}
                        </div>
                    ) : null}
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
                                    <Th>Konten</Th>
                                    <Th>Params</Th>
                                    <Th>Meta ID</Th>
                                    <Th>Sync</Th>
                                    <Th className="text-right">Aksi</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {items.length ? (
                                    items.map((t) => {
                                        const bodyText = getBodyText(
                                            t.components,
                                        );

                                        return (
                                            <tr key={t.id}>
                                                <Td className="font-medium text-slate-900">
                                                    {t.name}
                                                </Td>
                                                <Td className="whitespace-nowrap">
                                                    {t.status ?? '-'}
                                                </Td>
                                                <Td className="whitespace-nowrap">
                                                    {(t.category ?? '-') +
                                                        (t.sub_category
                                                            ? ` / ${t.sub_category}`
                                                            : '')}
                                                </Td>
                                                <Td className="whitespace-nowrap">
                                                    {t.language ?? '-'}
                                                </Td>
                                                <Td className="max-w-xl min-w-64">
                                                    <div className="line-clamp-2 text-xs leading-5 break-words whitespace-pre-wrap text-slate-700">
                                                        {bodyText || '-'}
                                                    </div>
                                                </Td>
                                                <Td className="whitespace-nowrap">
                                                    {countBodyParams(
                                                        t.components,
                                                    )}
                                                </Td>
                                                <Td className="text-xs whitespace-nowrap text-slate-600">
                                                    {t.meta_template_id}
                                                </Td>
                                                <Td className="text-xs whitespace-nowrap text-slate-600">
                                                    {formatDate(
                                                        t.last_synced_at,
                                                    )}
                                                </Td>
                                                <Td className="text-right whitespace-nowrap">
                                                    <Button
                                                        variant="secondary"
                                                        onClick={() =>
                                                            setSelected(t)
                                                        }
                                                    >
                                                        Detail
                                                    </Button>
                                                </Td>
                                            </tr>
                                        );
                                    })
                                ) : (
                                    <tr>
                                        <Td
                                            colSpan={9}
                                            className="py-10 text-center text-slate-500"
                                        >
                                            {isLoading
                                                ? 'Memuat...'
                                                : 'Belum ada data. Klik Sync untuk mengambil template.'}
                                        </Td>
                                    </tr>
                                )}
                            </tbody>
                        </Table>
                    </div>

                    <div className="flex items-center justify-between gap-3 px-4 py-3">
                        <div className="text-xs text-slate-600">
                            Halaman {meta.page ?? page}
                        </div>
                        <div className="flex items-center gap-2">
                            <Button
                                variant="secondary"
                                onClick={() =>
                                    setPage((p) => Math.max(1, p - 1))
                                }
                                disabled={page <= 1 || isLoading}
                            >
                                Sebelumnya
                            </Button>
                            <Button
                                variant="secondary"
                                onClick={() => setPage((p) => p + 1)}
                                disabled={
                                    isLoading || items.length < meta.per_page
                                }
                            >
                                Berikutnya
                            </Button>
                        </div>
                    </div>
                </CardContent>
            </Card>

            {selected ? (
                <Modal
                    title="Detail Template WhatsApp"
                    description={`${selected.name} - ${selected.language ?? '-'} - ${selected.status ?? '-'}`}
                    onClose={() => setSelected(null)}
                >
                    <div className="space-y-4">
                        <div className="grid gap-3 lg:grid-cols-3">
                            <div className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <div className="text-xs font-semibold text-slate-700">
                                    Meta ID
                                </div>
                                <div className="mt-1 text-sm break-all text-slate-900">
                                    {selected.meta_template_id}
                                </div>
                            </div>
                            <div className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <div className="text-xs font-semibold text-slate-700">
                                    Kategori
                                </div>
                                <div className="mt-1 text-sm text-slate-900">
                                    {(selected.category ?? '-') +
                                        (selected.sub_category
                                            ? ` / ${selected.sub_category}`
                                            : '')}
                                </div>
                            </div>
                            <div className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <div className="text-xs font-semibold text-slate-700">
                                    Sync Terakhir
                                </div>
                                <div className="mt-1 text-sm text-slate-900">
                                    {formatDate(selected.last_synced_at)}
                                </div>
                            </div>
                        </div>

                        <div className="space-y-3">
                            {selected.components?.length ? (
                                selected.components.map((component, index) =>
                                    renderTemplateComponent(component, index),
                                )
                            ) : (
                                <div className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-8 text-center text-sm text-slate-500">
                                    Konten template belum tersedia. Klik Sync
                                    untuk mengambil data terbaru dari Meta.
                                </div>
                            )}
                        </div>
                    </div>
                </Modal>
            ) : null}
        </div>
    );
}
