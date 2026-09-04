import { useEffect, useState } from 'react';

import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
import { Label } from '../../components/ui/label';
import { api } from '../../lib/axios';
import type { AdminDivision, AdminTicketSubcategory } from '../../types/admin';

export function AdminTicketSubcategoriesPage() {
    const [items, setItems] = useState<AdminTicketSubcategory[]>([]);
    const [divisions, setDivisions] = useState<AdminDivision[]>([]);
    const [editing, setEditing] = useState<AdminTicketSubcategory | null>(null);
    const [name, setName] = useState('');
    const [divisionId, setDivisionId] = useState('');
    const [isActive, setIsActive] = useState(true);
    const [isSaving, setIsSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    async function load() {
        try {
            const [subcategoryRes, divisionRes] = await Promise.all([
                api.get<{ data: AdminTicketSubcategory[] }>('/api/admin/ticket-subcategories'),
                api.get<{ data: AdminDivision[] }>('/api/admin/divisions'),
            ]);
            setItems(subcategoryRes.data.data ?? []);
            setDivisions(divisionRes.data.data ?? []);
        } catch (e: any) {
            setError(String(e?.response?.data?.message ?? 'Gagal memuat subkategori.'));
        }
    }

    useEffect(() => {
        // eslint-disable-next-line react-hooks/set-state-in-effect
        load();
    }, []);

    function resetForm() {
        setEditing(null);
        setName('');
        setDivisionId('');
        setIsActive(true);
    }

    async function submit(e: React.FormEvent) {
        e.preventDefault();
        setError(null);
        setIsSaving(true);

        try {
            const payload = {
                name: name.trim(),
                division_id: divisionId || null,
                is_active: isActive,
            };

            if (editing) {
                await api.put(`/api/admin/ticket-subcategories/${editing.id}`, payload);
            } else {
                await api.post('/api/admin/ticket-subcategories', payload);
            }

            resetForm();
            await load();
        } catch (e: any) {
            setError(String(e?.response?.data?.message ?? 'Gagal menyimpan subkategori.'));
        } finally {
            setIsSaving(false);
        }
    }

    return (
        <div className="space-y-4">
            <div>
                <h1 className="text-lg font-semibold text-slate-900">Subkategori Ticket</h1>
                <p className="mt-1 text-sm text-slate-600">Tanpa divisi berarti subkategori global.</p>
            </div>

            {error ? (
                <div className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">{error}</div>
            ) : null}

            <form
                onSubmit={submit}
                className="grid gap-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200 sm:grid-cols-4"
            >
                <div className="space-y-2 sm:col-span-2">
                    <Label htmlFor="subcategory-name">Nama</Label>
                    <Input
                        id="subcategory-name"
                        value={name}
                        onChange={(e) => setName(e.target.value)}
                        required
                        maxLength={255}
                    />
                </div>
                <div className="space-y-2">
                    <Label htmlFor="subcategory-division">Divisi</Label>
                    <select
                        id="subcategory-division"
                        className="h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm"
                        value={divisionId}
                        onChange={(e) => setDivisionId(e.target.value)}
                    >
                        <option value="">Global</option>
                        {divisions.map((division) => (
                            <option key={division.id} value={division.id}>
                                {division.name}
                            </option>
                        ))}
                    </select>
                </div>
                <div className="flex items-end gap-3">
                    <label className="flex h-10 items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" checked={isActive} onChange={(e) => setIsActive(e.target.checked)} />{' '}
                        Aktif
                    </label>
                    <Button type="submit" disabled={isSaving || !name.trim()}>
                        {isSaving ? 'Menyimpan…' : editing ? 'Update' : 'Tambah'}
                    </Button>
                    {editing ? (
                        <Button type="button" variant="secondary" onClick={resetForm}>
                            Batal
                        </Button>
                    ) : null}
                </div>
            </form>

            <div className="overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
                <table className="w-full text-left text-sm">
                    <thead className="border-b border-slate-200 bg-slate-50 text-xs text-slate-600">
                        <tr>
                            <th className="px-4 py-3">Nama</th>
                            <th className="px-4 py-3">Scope</th>
                            <th className="px-4 py-3">Status</th>
                            <th className="px-4 py-3 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {items.map((item) => (
                            <tr key={item.id}>
                                <td className="px-4 py-3 font-medium text-slate-900">{item.name}</td>
                                <td className="px-4 py-3 text-slate-600">{item.division?.name ?? 'Global'}</td>
                                <td className="px-4 py-3">{item.is_active ? 'Aktif' : 'Nonaktif'}</td>
                                <td className="space-x-2 px-4 py-3 text-right">
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        onClick={() => {
                                            setEditing(item);
                                            setName(item.name);
                                            setDivisionId(item.division_id ?? '');
                                            setIsActive(item.is_active);
                                        }}
                                    >
                                        Edit
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        onClick={async () => {
                                            if (!window.confirm(`Hapus subkategori ${item.name}?`)) {
                                                return;
                                            }

                                            try {
                                                await api.delete(`/api/admin/ticket-subcategories/${item.id}`);
                                                await load();
                                            } catch (e: any) {
                                                setError(
                                                    String(
                                                        e?.response?.data?.message ?? 'Gagal menghapus subkategori.',
                                                    ),
                                                );
                                            }
                                        }}
                                    >
                                        Hapus
                                    </Button>
                                </td>
                            </tr>
                        ))}
                        {!items.length ? (
                            <tr>
                                <td className="px-4 py-6 text-center text-slate-500" colSpan={4}>
                                    Belum ada subkategori.
                                </td>
                            </tr>
                        ) : null}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
