import { useEffect, useMemo, useState } from 'react';

import { Button } from '../../components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '../../components/ui/card';
import { Input } from '../../components/ui/input';
import { Label } from '../../components/ui/label';
import { Select } from '../../components/ui/select';
import { Table, Td, Th } from '../../components/ui/table';
import { Textarea } from '../../components/ui/textarea';
import { api } from '../../lib/axios';
import type { AdminDivision, AdminDivisionWorkingHour, AdminDivisionsIndexResponse } from '../../types/admin';

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

const dayLabels: Record<AdminDivisionWorkingHour['day_of_week'], string> = {
    monday: 'Senin',
    tuesday: 'Selasa',
    wednesday: 'Rabu',
    thursday: 'Kamis',
    friday: 'Jumat',
    saturday: 'Sabtu',
    sunday: 'Minggu',
};

function defaultWorkingHours(): AdminDivisionWorkingHour[] {
    return (Object.keys(dayLabels) as AdminDivisionWorkingHour['day_of_week'][]).map((day) => ({
        day_of_week: day,
        start_time: '08:00',
        end_time: '17:00',
        is_active: !['saturday', 'sunday'].includes(day),
    }));
}

function unitLabel(unit: 'hours' | 'days') {
    return unit === 'hours' ? 'Jam' : 'Hari';
}

export function AdminDivisionsPage() {
    const [data, setData] = useState<AdminDivisionsIndexResponse | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const [search, setSearch] = useState('');
    const [createOpen, setCreateOpen] = useState(false);
    const [editDivision, setEditDivision] = useState<AdminDivision | null>(null);
    const [confirmDelete, setConfirmDelete] = useState<AdminDivision | null>(null);

    const filtered = useMemo(() => {
        const list = data?.data ?? [];
        const q = search.trim().toLowerCase();
        if (!q) return list;
        return list.filter((d) => d.name.toLowerCase().includes(q));
    }, [data, search]);

    async function loadDivisions() {
        setLoading(true);
        setError(null);
        try {
            const response = await api.get<AdminDivisionsIndexResponse>('/api/admin/divisions');
            setData(response.data);
        } catch (e: any) {
            setError(e?.response?.data?.message ?? 'Gagal memuat data divisi.');
        } finally {
            setLoading(false);
        }
    }

    useEffect(() => {
        loadDivisions();
    }, []);

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-lg font-semibold text-slate-900">Manajemen Divisi</h1>
                    <p className="mt-1 text-sm text-slate-600">Kelola divisi, jam kerja, dan SLA resolution.</p>
                </div>
                <Button onClick={() => setCreateOpen(true)}>Tambah Divisi</Button>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>Pencarian</CardTitle>
                    <CardDescription>Cari divisi berdasarkan nama.</CardDescription>
                </CardHeader>
                <CardContent>
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                        <div className="w-full max-w-md space-y-1.5">
                            <Label>Cari</Label>
                            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Nama divisi" />
                        </div>
                        <div className="flex items-center gap-2">
                            <Button variant="secondary" onClick={() => setSearch('')}>
                                Reset
                            </Button>
                            <Button variant="secondary" onClick={loadDivisions} disabled={loading}>
                                {loading ? 'Memuat...' : 'Refresh'}
                            </Button>
                        </div>
                    </div>
                    {error ? <div className="mt-3 text-sm text-red-600">{error}</div> : null}
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Daftar Divisi</CardTitle>
                    <CardDescription>Gunakan edit untuk mengubah jam kerja dan konfigurasi SLA.</CardDescription>
                </CardHeader>
                <CardContent className="p-0">
                    <div className="overflow-auto">
                        <Table>
                            <thead>
                                <tr>
                                    <Th>Nama</Th>
                                    <Th>Status</Th>
                                    <Th>Fallback</Th>
                                    <Th>PIC Aktif</Th>
                                    <Th>SLA Resolution</Th>
                                    <Th>Reminder</Th>
                                    <Th className="text-right">Aksi</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {filtered.length ? (
                                    filtered.map((d) => (
                                        <tr key={d.id}>
                                            <Td className="font-medium text-slate-900">{d.name}</Td>
                                            <Td>
                                                <span
                                                    className={
                                                        d.is_active
                                                            ? 'inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-700'
                                                            : 'inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-600'
                                                    }
                                                >
                                                    {d.is_active ? 'Aktif' : 'Nonaktif'}
                                                </span>
                                            </Td>
                                            <Td>{d.is_fallback ? 'Ya' : 'Tidak'}</Td>
                                            <Td>{d.pic_count}</Td>
                                            <Td>
                                                {d.sla_resolution_value} {unitLabel(d.sla_resolution_unit)}
                                            </Td>
                                            <Td>
                                                {d.sla_resolution_reminder_value} {unitLabel(d.sla_resolution_reminder_unit)}
                                            </Td>
                                            <Td className="whitespace-nowrap text-right">
                                                <div className="inline-flex items-center gap-2">
                                                    <Button variant="secondary" onClick={() => setEditDivision(d)}>
                                                        Edit
                                                    </Button>
                                                    <Button variant="destructive" onClick={() => setConfirmDelete(d)}>
                                                        Hapus
                                                    </Button>
                                                </div>
                                            </Td>
                                        </tr>
                                    ))
                                ) : (
                                    <tr>
                                        <Td colSpan={7} className="py-10 text-center text-slate-500">
                                            {loading ? 'Memuat...' : 'Belum ada data.'}
                                        </Td>
                                    </tr>
                                )}
                            </tbody>
                        </Table>
                    </div>
                </CardContent>
            </Card>

            {createOpen ? (
                <DivisionFormModal
                    mode="create"
                    onClose={() => setCreateOpen(false)}
                    onSaved={() => {
                        setCreateOpen(false);
                        loadDivisions();
                    }}
                />
            ) : null}

            {editDivision ? (
                <DivisionFormModal
                    mode="edit"
                    division={editDivision}
                    onClose={() => setEditDivision(null)}
                    onSaved={() => {
                        setEditDivision(null);
                        loadDivisions();
                    }}
                />
            ) : null}

            {confirmDelete ? (
                <Modal
                    title="Hapus Divisi?"
                    description={`Divisi "${confirmDelete.name}" akan dihapus permanen.`}
                    onClose={() => setConfirmDelete(null)}
                >
                    <div className="space-y-4">
                        <div className="text-sm text-slate-700">
                            Divisi hanya bisa dihapus jika tidak ada ticket aktif dan bukan divisi fallback.
                        </div>
                        <div className="flex items-center justify-end gap-2">
                            <Button variant="secondary" onClick={() => setConfirmDelete(null)}>
                                Batal
                            </Button>
                            <Button
                                variant="destructive"
                                onClick={async () => {
                                    try {
                                        await api.delete(`/api/admin/divisions/${confirmDelete.id}`);
                                        setConfirmDelete(null);
                                        loadDivisions();
                                    } catch (e: any) {
                                        alert(e?.response?.data?.message ?? 'Gagal menghapus divisi.');
                                    }
                                }}
                            >
                                Hapus
                            </Button>
                        </div>
                    </div>
                </Modal>
            ) : null}
        </div>
    );
}

function DivisionFormModal({
    mode,
    division,
    onClose,
    onSaved,
}: {
    mode: 'create' | 'edit';
    division?: AdminDivision;
    onClose: () => void;
    onSaved: () => void;
}) {
    const [submitting, setSubmitting] = useState(false);
    const [message, setMessage] = useState<string | null>(null);

    const [name, setName] = useState(division?.name ?? '');
    const [description, setDescription] = useState(division?.description ?? '');
    const [handles, setHandles] = useState(division?.handles ?? '');
    const [notHandles, setNotHandles] = useState(division?.not_handles ?? '');
    const [ticketExamples, setTicketExamples] = useState(division?.ticket_examples ?? '');
    const [slaValue, setSlaValue] = useState(String(division?.sla_resolution_value ?? 24));
    const [slaUnit, setSlaUnit] = useState<'hours' | 'days'>(division?.sla_resolution_unit ?? 'hours');
    const [reminderValue, setReminderValue] = useState(String(division?.sla_resolution_reminder_value ?? 12));
    const [reminderUnit, setReminderUnit] = useState<'hours' | 'days'>(division?.sla_resolution_reminder_unit ?? 'hours');
    const [isFallback, setIsFallback] = useState(Boolean(division?.is_fallback ?? false));
    const [workingHours, setWorkingHours] = useState<AdminDivisionWorkingHour[]>(
        division?.working_hours?.length ? division.working_hours : defaultWorkingHours()
    );

    const title = mode === 'create' ? 'Tambah Divisi' : 'Edit Divisi';

    return (
        <Modal
            title={title}
            description={
                mode === 'edit'
                    ? `PIC aktif: ${division?.pic_count ?? 0} - Status: ${division?.is_active ? 'Aktif' : 'Nonaktif'}`
                    : 'Isi detail divisi dan jam kerja (7 hari).'
            }
            onClose={onClose}
        >
            <form
                className="space-y-6"
                onSubmit={async (e) => {
                    e.preventDefault();
                    setSubmitting(true);
                    setMessage(null);
                    try {
                        const payload = {
                            name,
                            description,
                            handles,
                            not_handles: notHandles,
                            ticket_examples: ticketExamples,
                            sla_resolution_value: Number(slaValue),
                            sla_resolution_unit: slaUnit,
                            sla_resolution_reminder_value: Number(reminderValue),
                            sla_resolution_reminder_unit: reminderUnit,
                            is_fallback: isFallback,
                            working_hours: workingHours,
                        };

                        if (mode === 'create') {
                            await api.post('/api/admin/divisions', payload);
                        } else {
                            await api.put(`/api/admin/divisions/${division!.id}`, payload);
                        }

                        onSaved();
                    } catch (err: any) {
                        setMessage(err?.response?.data?.message ?? 'Gagal menyimpan divisi.');
                    } finally {
                        setSubmitting(false);
                    }
                }}
            >
                <div className="grid gap-3 lg:grid-cols-2">
                    <div className="space-y-1.5">
                        <Label>Nama</Label>
                        <Input value={name} onChange={(e) => setName(e.target.value)} placeholder="Nama divisi" />
                    </div>
                    <div className="space-y-1.5">
                        <Label>Divisi Fallback</Label>
                        <div className="flex items-center gap-3 pt-2">
                            <label className="flex items-center gap-2 text-sm text-slate-700">
                                <input
                                    type="checkbox"
                                    checked={isFallback}
                                    onChange={(e) => setIsFallback(e.target.checked)}
                                    className="h-4 w-4 rounded border-slate-300"
                                />
                                Jadikan fallback
                            </label>
                            <div className="text-xs text-slate-500">Hanya boleh 1 divisi fallback.</div>
                        </div>
                    </div>

                    <div className="space-y-1.5 lg:col-span-2">
                        <Label>Deskripsi</Label>
                        <Textarea
                            value={description}
                            onChange={(e) => setDescription(e.target.value)}
                            placeholder="Deskripsi singkat divisi"
                            rows={3}
                        />
                    </div>

                    <div className="space-y-1.5 lg:col-span-2">
                        <Label>Handles</Label>
                        <Textarea value={handles} onChange={(e) => setHandles(e.target.value)} rows={3} placeholder="Apa saja yang ditangani divisi ini" />
                    </div>
                    <div className="space-y-1.5 lg:col-span-2">
                        <Label>Not Handles</Label>
                        <Textarea
                            value={notHandles}
                            onChange={(e) => setNotHandles(e.target.value)}
                            rows={3}
                            placeholder="Apa saja yang tidak ditangani divisi ini"
                        />
                    </div>
                    <div className="space-y-1.5 lg:col-span-2">
                        <Label>Contoh Ticket</Label>
                        <Textarea
                            value={ticketExamples}
                            onChange={(e) => setTicketExamples(e.target.value)}
                            rows={3}
                            placeholder="Contoh kasus ticket untuk divisi ini"
                        />
                    </div>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>SLA Resolution</CardTitle>
                        <CardDescription>Dipakai untuk deadline resolution per divisi.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="grid gap-3 lg:grid-cols-4">
                            <div className="space-y-1.5 lg:col-span-2">
                                <Label>Durasi</Label>
                                <Input
                                    inputMode="numeric"
                                    value={slaValue}
                                    onChange={(e) => setSlaValue(e.target.value)}
                                    placeholder="Angka"
                                />
                            </div>
                            <div className="space-y-1.5 lg:col-span-2">
                                <Label>Satuan</Label>
                                <Select value={slaUnit} onChange={(e) => setSlaUnit(e.target.value as 'hours' | 'days')}>
                                    <option value="hours">Jam</option>
                                    <option value="days">Hari</option>
                                </Select>
                            </div>
                            <div className="space-y-1.5 lg:col-span-2">
                                <Label>Reminder</Label>
                                <Input
                                    inputMode="numeric"
                                    value={reminderValue}
                                    onChange={(e) => setReminderValue(e.target.value)}
                                    placeholder="Angka"
                                />
                            </div>
                            <div className="space-y-1.5 lg:col-span-2">
                                <Label>Satuan Reminder</Label>
                                <Select
                                    value={reminderUnit}
                                    onChange={(e) => setReminderUnit(e.target.value as 'hours' | 'days')}
                                >
                                    <option value="hours">Jam</option>
                                    <option value="days">Hari</option>
                                </Select>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Jam Kerja</CardTitle>
                        <CardDescription>Wajib 7 hari. Nonaktifkan Sabtu/Minggu jika tidak bekerja.</CardDescription>
                    </CardHeader>
                    <CardContent className="p-0">
                        <div className="overflow-auto">
                            <Table>
                                <thead>
                                    <tr>
                                        <Th>Hari</Th>
                                        <Th>Mulai</Th>
                                        <Th>Selesai</Th>
                                        <Th>Aktif</Th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {workingHours.map((wh, idx) => (
                                        <tr key={wh.day_of_week}>
                                            <Td className="font-medium text-slate-900">{dayLabels[wh.day_of_week]}</Td>
                                            <Td className="min-w-40">
                                                <Input
                                                    type="time"
                                                    value={wh.start_time}
                                                    onChange={(e) => {
                                                        const next = [...workingHours];
                                                        next[idx] = { ...wh, start_time: e.target.value };
                                                        setWorkingHours(next);
                                                    }}
                                                />
                                            </Td>
                                            <Td className="min-w-40">
                                                <Input
                                                    type="time"
                                                    value={wh.end_time}
                                                    onChange={(e) => {
                                                        const next = [...workingHours];
                                                        next[idx] = { ...wh, end_time: e.target.value };
                                                        setWorkingHours(next);
                                                    }}
                                                />
                                            </Td>
                                            <Td>
                                                <label className="inline-flex items-center gap-2 text-sm text-slate-700">
                                                    <input
                                                        type="checkbox"
                                                        checked={wh.is_active}
                                                        onChange={(e) => {
                                                            const next = [...workingHours];
                                                            next[idx] = { ...wh, is_active: e.target.checked };
                                                            setWorkingHours(next);
                                                        }}
                                                        className="h-4 w-4 rounded border-slate-300"
                                                    />
                                                    Aktif
                                                </label>
                                            </Td>
                                        </tr>
                                    ))}
                                </tbody>
                            </Table>
                        </div>
                    </CardContent>
                </Card>

                {message ? (
                    <div className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                        {message}
                    </div>
                ) : null}

                <div className="flex items-center justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onClose} disabled={submitting}>
                        Batal
                    </Button>
                    <Button type="submit" disabled={submitting}>
                        {submitting ? 'Menyimpan...' : 'Simpan'}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
