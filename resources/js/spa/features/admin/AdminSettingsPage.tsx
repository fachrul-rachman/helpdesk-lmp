import { useEffect, useState } from 'react';

import { Button } from '../../components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '../../components/ui/card';
import { Input } from '../../components/ui/input';
import { Label } from '../../components/ui/label';
import { api } from '../../lib/axios';
import type { AdminSettingsResponse } from '../../types/admin';

export function AdminSettingsPage() {
    const [loading, setLoading] = useState(false);
    const [saving, setSaving] = useState(false);
    const [message, setMessage] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);

    const [duration, setDuration] = useState('5');
    const [reminder, setReminder] = useState('3');
    const [notifySpvOnNewTicket, setNotifySpvOnNewTicket] = useState(false);

    async function loadSettings() {
        setLoading(true);
        setError(null);
        try {
            const response = await api.get<AdminSettingsResponse>('/api/admin/settings');
            setDuration(String(response.data.sla_fr_duration_minutes));
            setReminder(String(response.data.sla_fr_reminder_minutes));
            setNotifySpvOnNewTicket(Boolean(response.data.notify_spv_on_new_ticket));
        } catch (e: any) {
            setError(e?.response?.data?.message ?? 'Gagal memuat konfigurasi.');
        } finally {
            setLoading(false);
        }
    }

    useEffect(() => {
        loadSettings();
    }, []);

    return (
        <div className="space-y-4">
            <div>
                <h1 className="text-lg font-semibold text-slate-900">Konfigurasi Global</h1>
                <p className="mt-1 text-sm text-slate-600">SLA First Response dan notifikasi ticket baru.</p>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>SLA First Response</CardTitle>
                    <CardDescription>
                        Reminder harus lebih kecil dari durasi SLA FR.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <form
                        className="space-y-4"
                        onSubmit={async (e) => {
                            e.preventDefault();
                            setSaving(true);
                            setMessage(null);
                            setError(null);
                            try {
                                await api.put('/api/admin/settings', {
                                    sla_fr_duration_minutes: Number(duration),
                                    sla_fr_reminder_minutes: Number(reminder),
                                    notify_spv_on_new_ticket: notifySpvOnNewTicket,
                                });
                                setMessage('Konfigurasi berhasil disimpan.');
                            } catch (err: any) {
                                setError(err?.response?.data?.message ?? 'Gagal menyimpan konfigurasi.');
                            } finally {
                                setSaving(false);
                            }
                        }}
                    >
                        <div className="grid gap-3 lg:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label>Durasi SLA FR (menit)</Label>
                                <Input
                                    inputMode="numeric"
                                    value={duration}
                                    onChange={(e) => setDuration(e.target.value)}
                                    placeholder="Contoh: 5"
                                    disabled={loading}
                                />
                            </div>
                            <div className="space-y-1.5">
                                <Label>Threshold Reminder (menit)</Label>
                                <Input
                                    inputMode="numeric"
                                    value={reminder}
                                    onChange={(e) => setReminder(e.target.value)}
                                    placeholder="Contoh: 3"
                                    disabled={loading}
                                />
                            </div>
                        </div>

                        <label className="flex items-start gap-3 rounded-md border border-slate-200 p-3">
                            <input
                                type="checkbox"
                                className="mt-1 h-4 w-4 rounded border-slate-300"
                                checked={notifySpvOnNewTicket}
                                onChange={(e) => setNotifySpvOnNewTicket(e.target.checked)}
                                disabled={loading}
                            />
                            <div>
                                <div className="text-sm font-medium text-slate-900">Kirim juga notifikasi ticket baru ke SPV</div>
                                <div className="text-xs text-slate-600">Jika aktif, saat ticket baru masuk ke team maka SPV juga ikut menerima template pesan.</div>
                            </div>
                        </label>

                        {message ? (
                            <div className="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-700">
                                {message}
                            </div>
                        ) : null}
                        {error ? (
                            <div className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                                {error}
                            </div>
                        ) : null}

                        <div className="flex items-center justify-end gap-2">
                            <Button type="button" variant="secondary" onClick={loadSettings} disabled={loading || saving}>
                                {loading ? 'Memuat...' : 'Refresh'}
                            </Button>
                            <Button type="submit" disabled={loading || saving}>
                                {saving ? 'Menyimpan...' : 'Simpan'}
                            </Button>
                        </div>
                    </form>
                </CardContent>
            </Card>
        </div>
    );
}
