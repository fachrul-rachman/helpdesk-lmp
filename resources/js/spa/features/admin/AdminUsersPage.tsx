import { useEffect, useMemo, useState } from 'react';

import { Button } from '../../components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '../../components/ui/card';
import { Input } from '../../components/ui/input';
import { Label } from '../../components/ui/label';
import { Select } from '../../components/ui/select';
import { Table, Td, Th } from '../../components/ui/table';
import { api } from '../../lib/axios';
import type { AdminUsersIndexResponse, AdminUserListItem } from '../../types/admin';

type DivisionOption = { id: string; name: string };

function roleLabel(role: string) {
    if (role === 'admin') return 'Admin';
    if (role === 'pic') return 'PIC';
    return role;
}

function formatDate(iso: string | null) {
    if (!iso) return '-';
    try {
        return new Date(iso).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' });
    } catch {
        return iso;
    }
}

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
            <div className="w-full max-w-xl rounded-xl bg-white shadow-xl">
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
                <div className="px-4 py-4">{children}</div>
            </div>
        </div>
    );
}

export function AdminUsersPage() {
    const [divisions, setDivisions] = useState<DivisionOption[]>([]);
    const [data, setData] = useState<AdminUsersIndexResponse | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const [search, setSearch] = useState('');
    const [role, setRole] = useState<string>('');
    const [divisionId, setDivisionId] = useState<string>('');
    const [isActive, setIsActive] = useState<string>('');
    const [page, setPage] = useState(1);

    const [createOpen, setCreateOpen] = useState(false);
    const [editUser, setEditUser] = useState<AdminUserListItem | null>(null);
    const [resetUser, setResetUser] = useState<AdminUserListItem | null>(null);
    const [confirmDelete, setConfirmDelete] = useState<AdminUserListItem | null>(null);

    const queryParams = useMemo(() => {
        const params: Record<string, string | number> = { page, per_page: 20 };
        if (search.trim()) params.search = search.trim();
        if (role) params.role = role;
        if (divisionId) params.division_id = divisionId;
        if (isActive) params.is_active = isActive;
        return params;
    }, [divisionId, isActive, page, role, search]);

    async function loadDivisions() {
        const response = await api.get<{ data: Array<{ id: string; name: string }> }>('/api/divisions');
        setDivisions(response.data.data);
    }

    async function loadUsers() {
        setLoading(true);
        setError(null);
        try {
            const response = await api.get<AdminUsersIndexResponse>('/api/admin/users', { params: queryParams });
            setData(response.data);
        } catch (e: any) {
            setError(e?.response?.data?.message ?? 'Gagal memuat data user.');
        } finally {
            setLoading(false);
        }
    }

    useEffect(() => {
        loadDivisions().catch(() => setDivisions([]));
    }, []);

    useEffect(() => {
        loadUsers();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [queryParams]);

    function resetFilters() {
        setSearch('');
        setRole('');
        setDivisionId('');
        setIsActive('');
        setPage(1);
    }

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-lg font-semibold text-slate-900">Manajemen User</h1>
                    <p className="mt-1 text-sm text-slate-600">Kelola akun Admin & PIC.</p>
                </div>
                <Button onClick={() => setCreateOpen(true)}>Tambah User</Button>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>Filter</CardTitle>
                    <CardDescription>Gunakan filter untuk mempercepat pencarian.</CardDescription>
                </CardHeader>
                <CardContent>
                    <div className="grid gap-3 lg:grid-cols-5">
                        <div className="space-y-1.5 lg:col-span-2">
                            <Label htmlFor="search">Cari</Label>
                            <Input
                                id="search"
                                value={search}
                                onChange={(e) => {
                                    setSearch(e.target.value);
                                    setPage(1);
                                }}
                                placeholder="Nama / No. HP"
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label>Role</Label>
                            <Select
                                value={role}
                                onChange={(e) => {
                                    setRole(e.target.value);
                                    setPage(1);
                                }}
                            >
                                <option value="">Semua</option>
                                <option value="admin">Admin</option>
                                <option value="pic">PIC</option>
                            </Select>
                        </div>
                        <div className="space-y-1.5">
                            <Label>Divisi</Label>
                            <Select
                                value={divisionId}
                                onChange={(e) => {
                                    setDivisionId(e.target.value);
                                    setPage(1);
                                }}
                            >
                                <option value="">Semua</option>
                                {divisions.map((d) => (
                                    <option key={d.id} value={d.id}>
                                        {d.name}
                                    </option>
                                ))}
                            </Select>
                        </div>
                        <div className="space-y-1.5">
                            <Label>Status</Label>
                            <Select
                                value={isActive}
                                onChange={(e) => {
                                    setIsActive(e.target.value);
                                    setPage(1);
                                }}
                            >
                                <option value="">Semua</option>
                                <option value="true">Aktif</option>
                                <option value="false">Nonaktif</option>
                            </Select>
                        </div>
                    </div>
                    <div className="mt-3 flex flex-wrap items-center justify-between gap-2">
                        <div className="text-xs text-slate-600">
                            {data ? `Total: ${data.meta.total}` : '-'}
                        </div>
                        <div className="flex items-center gap-2">
                            <Button variant="secondary" onClick={resetFilters}>
                                Reset
                            </Button>
                            <Button variant="secondary" onClick={loadUsers} disabled={loading}>
                                {loading ? 'Memuat...' : 'Refresh'}
                            </Button>
                        </div>
                    </div>
                    {error ? <div className="mt-3 text-sm text-red-600">{error}</div> : null}
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Daftar User</CardTitle>
                    <CardDescription>Klik aksi di kanan untuk edit/reset password/hapus.</CardDescription>
                </CardHeader>
                <CardContent className="p-0">
                    <div className="overflow-auto">
                        <Table>
                            <thead>
                                <tr>
                                    <Th>Nama</Th>
                                    <Th>No. HP</Th>
                                    <Th>Role</Th>
                                    <Th>Divisi</Th>
                                    <Th>Status</Th>
                                    <Th>Dibuat</Th>
                                    <Th className="text-right">Aksi</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {data?.data?.length ? (
                                    data.data.map((u) => (
                                        <tr key={u.id}>
                                            <Td className="font-medium text-slate-900">{u.name}</Td>
                                            <Td>{u.phone_number}</Td>
                                            <Td>
                                                <span className="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-700">
                                                    {roleLabel(u.role)}
                                                </span>
                                            </Td>
                                            <Td>{u.division?.name ?? '-'}</Td>
                                            <Td>
                                                <span
                                                    className={
                                                        u.is_active
                                                            ? 'inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-700'
                                                            : 'inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-600'
                                                    }
                                                >
                                                    {u.is_active ? 'Aktif' : 'Nonaktif'}
                                                </span>
                                            </Td>
                                            <Td className="whitespace-nowrap">{formatDate(u.created_at)}</Td>
                                            <Td className="whitespace-nowrap text-right">
                                                <div className="inline-flex items-center gap-2">
                                                    <Button variant="secondary" onClick={() => setEditUser(u)}>
                                                        Edit
                                                    </Button>
                                                    <Button variant="secondary" onClick={() => setResetUser(u)}>
                                                        Reset Password
                                                    </Button>
                                                    <Button variant="destructive" onClick={() => setConfirmDelete(u)}>
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
                    <div className="flex items-center justify-between gap-3 px-4 py-3">
                        <div className="text-xs text-slate-600">
                            Halaman {data?.meta.page ?? page}
                        </div>
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

            {createOpen ? (
                <UserFormModal
                    mode="create"
                    divisions={divisions}
                    onClose={() => setCreateOpen(false)}
                    onSaved={() => {
                        setCreateOpen(false);
                        loadUsers();
                    }}
                />
            ) : null}

            {editUser ? (
                <UserFormModal
                    mode="edit"
                    user={editUser}
                    divisions={divisions}
                    onClose={() => setEditUser(null)}
                    onSaved={() => {
                        setEditUser(null);
                        loadUsers();
                    }}
                />
            ) : null}

            {resetUser ? (
                <ResetPasswordModal
                    user={resetUser}
                    onClose={() => setResetUser(null)}
                    onSaved={() => {
                        setResetUser(null);
                    }}
                />
            ) : null}

            {confirmDelete ? (
                <Modal
                    title="Hapus User?"
                    description={`User "${confirmDelete.name}" akan dihapus permanen.`}
                    onClose={() => setConfirmDelete(null)}
                >
                    <div className="space-y-4">
                        <div className="text-sm text-slate-700">
                            Pastikan user ini tidak memiliki ticket aktif dan bukan akun kamu sendiri.
                        </div>
                        <div className="flex items-center justify-end gap-2">
                            <Button variant="secondary" onClick={() => setConfirmDelete(null)}>
                                Batal
                            </Button>
                            <Button
                                variant="destructive"
                                onClick={async () => {
                                    try {
                                        await api.delete(`/api/admin/users/${confirmDelete.id}`);
                                        setConfirmDelete(null);
                                        loadUsers();
                                    } catch (e: any) {
                                        alert(e?.response?.data?.message ?? 'Gagal menghapus user.');
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

function UserFormModal({
    mode,
    user,
    divisions,
    onClose,
    onSaved,
}: {
    mode: 'create' | 'edit';
    user?: AdminUserListItem;
    divisions: DivisionOption[];
    onClose: () => void;
    onSaved: () => void;
}) {
    const [submitting, setSubmitting] = useState(false);
    const [message, setMessage] = useState<string | null>(null);

    const [name, setName] = useState(user?.name ?? '');
    const [phoneNumber, setPhoneNumber] = useState(user?.phone_number ?? '');
    const [role, setRole] = useState<'admin' | 'pic'>(user?.role ?? 'pic');
    const [divisionId, setDivisionId] = useState(user?.division?.id ?? '');
    const [isActive, setIsActive] = useState(user?.is_active ?? true);
    const [password, setPassword] = useState('');

    const title = mode === 'create' ? 'Tambah User' : 'Edit User';

    return (
        <Modal title={title} onClose={onClose}>
            <form
                className="space-y-4"
                onSubmit={async (e) => {
                    e.preventDefault();
                    setSubmitting(true);
                    setMessage(null);
                    try {
                        if (mode === 'create') {
                            await api.post('/api/admin/users', {
                                name,
                                phone_number: phoneNumber,
                                role,
                                division_id: role === 'pic' ? divisionId || null : null,
                                password,
                            });
                        } else {
                            await api.put(`/api/admin/users/${user!.id}`, {
                                name,
                                phone_number: phoneNumber,
                                division_id: role === 'pic' ? (divisionId || null) : null,
                                is_active: isActive,
                            });
                        }
                        onSaved();
                    } catch (err: any) {
                        setMessage(err?.response?.data?.message ?? 'Gagal menyimpan user.');
                    } finally {
                        setSubmitting(false);
                    }
                }}
            >
                <div className="grid gap-3 lg:grid-cols-2">
                    <div className="space-y-1.5">
                        <Label>Nama</Label>
                        <Input value={name} onChange={(e) => setName(e.target.value)} placeholder="Nama lengkap" />
                    </div>
                    <div className="space-y-1.5">
                        <Label>No. HP</Label>
                        <Input
                            value={phoneNumber}
                            onChange={(e) => setPhoneNumber(e.target.value)}
                            placeholder="Contoh: 0812xxxx atau 62812xxxx"
                        />
                    </div>
                    <div className="space-y-1.5">
                        <Label>Role</Label>
                        <Select
                            value={role}
                            onChange={(e) => {
                                const value = e.target.value as 'admin' | 'pic';
                                setRole(value);
                                if (value === 'admin') setDivisionId('');
                            }}
                            disabled={mode === 'edit'}
                        >
                            <option value="admin">Admin</option>
                            <option value="pic">PIC</option>
                        </Select>
                        {mode === 'edit' ? (
                            <div className="text-xs text-slate-500">Role tidak dapat diubah.</div>
                        ) : null}
                    </div>
                    <div className="space-y-1.5">
                        <Label>Divisi (untuk PIC)</Label>
                        <Select
                            value={divisionId}
                            onChange={(e) => setDivisionId(e.target.value)}
                            disabled={role !== 'pic'}
                        >
                            <option value="">-</option>
                            {divisions.map((d) => (
                                <option key={d.id} value={d.id}>
                                    {d.name}
                                </option>
                            ))}
                        </Select>
                    </div>
                    {mode === 'create' ? (
                        <div className="space-y-1.5 lg:col-span-2">
                            <Label>Password</Label>
                            <Input
                                type="password"
                                value={password}
                                onChange={(e) => setPassword(e.target.value)}
                                placeholder="Minimal 8 karakter"
                            />
                        </div>
                    ) : (
                        <div className="space-y-1.5 lg:col-span-2">
                            <Label>Status</Label>
                            <div className="flex items-center gap-3">
                                <label className="flex items-center gap-2 text-sm text-slate-700">
                                    <input
                                        type="checkbox"
                                        checked={isActive}
                                        onChange={(e) => setIsActive(e.target.checked)}
                                        className="h-4 w-4 rounded border-slate-300"
                                    />
                                    Aktif
                                </label>
                                <div className="text-xs text-slate-500">
                                    Menonaktifkan PIC dapat memicu reassign ticket.
                                </div>
                            </div>
                        </div>
                    )}
                </div>

                {message ? <div className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{message}</div> : null}

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

function ResetPasswordModal({ user, onClose, onSaved }: { user: AdminUserListItem; onClose: () => void; onSaved: () => void }) {
    const [newPassword, setNewPassword] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [message, setMessage] = useState<string | null>(null);

    return (
        <Modal title="Reset Password" description={`Reset password untuk "${user.name}"`} onClose={onClose}>
            <form
                className="space-y-4"
                onSubmit={async (e) => {
                    e.preventDefault();
                    setSubmitting(true);
                    setMessage(null);
                    try {
                        await api.post(`/api/admin/users/${user.id}/reset-password`, { new_password: newPassword });
                        onSaved();
                        onClose();
                        alert('Password berhasil direset.');
                    } catch (err: any) {
                        setMessage(err?.response?.data?.message ?? 'Gagal reset password.');
                    } finally {
                        setSubmitting(false);
                    }
                }}
            >
                <div className="space-y-1.5">
                    <Label>Password Baru</Label>
                    <Input
                        type="password"
                        value={newPassword}
                        onChange={(e) => setNewPassword(e.target.value)}
                        placeholder="Minimal 8 karakter"
                    />
                </div>
                {message ? <div className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{message}</div> : null}
                <div className="flex items-center justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={onClose} disabled={submitting}>
                        Batal
                    </Button>
                    <Button type="submit" disabled={submitting}>
                        {submitting ? 'Memproses...' : 'Reset'}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
