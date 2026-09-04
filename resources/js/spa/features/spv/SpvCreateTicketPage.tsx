import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';

import { TicketLocationFields } from '../../components/common/TicketLocationFields';
import { TicketSubcategoryFields } from '../../components/common/TicketSubcategoryFields';
import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
import { Label } from '../../components/ui/label';
import { Textarea } from '../../components/ui/textarea';
import { api } from '../../lib/axios';
import type { TicketDetail } from '../../types/ticket';

type DivisionOption = { id: string; name: string; is_active: boolean; is_fallback: boolean };

export function SpvCreateTicketPage() {
    const navigate = useNavigate();
    const [divisions, setDivisions] = useState<DivisionOption[]>([]);

    const [phone, setPhone] = useState('');
    const [divisionId, setDivisionId] = useState('');
    const [subject, setSubject] = useState('');
    const [priority, setPriority] = useState<'low' | 'medium' | 'high'>('medium');
    const [notes, setNotes] = useState('');
    const [globalSubcategoryId, setGlobalSubcategoryId] = useState('');
    const [divisionSubcategoryId, setDivisionSubcategoryId] = useState('');
    const [site, setSite] = useState('');
    const [zone, setZone] = useState('');
    const [lotNumber, setLotNumber] = useState('');

    const [isLoading, setIsLoading] = useState(true);
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [errorMessage, setErrorMessage] = useState<string | null>(null);

    useEffect(() => {
        let isMounted = true;
        async function load() {
            setIsLoading(true);
            setErrorMessage(null);
            try {
                const res = await api.get<{ data: DivisionOption[] }>('/api/divisions');
                if (!isMounted) return;
                setDivisions(res.data.data ?? []);
            } catch (error: any) {
                if (!isMounted) return;
                const message =
                    error?.response?.data?.message ?? 'Gagal memuat data divisi.';
                setErrorMessage(String(message));
            } finally {
                if (!isMounted) return;
                setIsLoading(false);
            }
        }
        load();
        return () => {
            isMounted = false;
        };
    }, []);

    async function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        setErrorMessage(null);

        if (!phone.trim() || !divisionId || !subject.trim()) {
            setErrorMessage('Nomor HP, divisi, dan subject wajib diisi.');
            return;
        }

        setIsSubmitting(true);
        try {
            const res = await api.post<{ data: TicketDetail }>('/api/spv/tickets', {
                customer_phone_number: phone,
                division_id: divisionId,
                subject,
                priority,
                notes: notes.trim() ? notes.trim() : null,
                global_subcategory_id: globalSubcategoryId || null,
                division_subcategory_id: divisionSubcategoryId || null,
                site: site.trim() || null,
                zone: zone.trim() || null,
                lot_number: lotNumber.trim() || null,
            });

            navigate(`/spv/tickets/${res.data.data.id}`, { replace: true });
        } catch (error: any) {
            const message =
                error?.response?.data?.message ?? 'Gagal membuat ticket.';
            setErrorMessage(String(message));
        } finally {
            setIsSubmitting(false);
        }
    }

    if (isLoading) {
        return <div className="text-sm text-slate-600">Memuat form…</div>;
    }

    return (
        <div className="space-y-4">
            <h1 className="text-lg font-semibold text-slate-900">Buat Ticket Manual</h1>

            {errorMessage ? (
                <div className="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                    {errorMessage}
                </div>
            ) : null}

            <form onSubmit={handleSubmit} className="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="space-y-2">
                        <Label htmlFor="phone">Nomor HP Customer</Label>
                        <Input
                            id="phone"
                            value={phone}
                            onChange={(e) => setPhone(e.target.value)}
                            placeholder="Contoh: 628123456789"
                            inputMode="numeric"
                            disabled={isSubmitting}
                        />
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="division">Divisi</Label>
                        <select
                            id="division"
                            className="h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                            value={divisionId}
                            onChange={(e) => {
                                setDivisionId(e.target.value);
                                setDivisionSubcategoryId('');
                            }}
                            disabled={isSubmitting}
                        >
                            <option value="" disabled>
                                Pilih divisi…
                            </option>
                            {divisions.map((d) => (
                                <option key={d.id} value={d.id}>
                                    {d.name}
                                    {d.is_fallback ? ' (Fallback)' : ''}
                                    {!d.is_active && !d.is_fallback ? ' (Nonaktif)' : ''}
                                </option>
                            ))}
                        </select>
                    </div>

                    <div className="sm:col-span-2">
                        <TicketSubcategoryFields
                            divisionId={divisionId}
                            globalValue={globalSubcategoryId}
                            divisionValue={divisionSubcategoryId}
                            onGlobalChange={setGlobalSubcategoryId}
                            onDivisionChange={setDivisionSubcategoryId}
                            disabled={isSubmitting}
                        />
                    </div>

                    <div className="sm:col-span-2">
                        <TicketLocationFields
                            site={site}
                            zone={zone}
                            lotNumber={lotNumber}
                            onSiteChange={setSite}
                            onZoneChange={setZone}
                            onLotNumberChange={setLotNumber}
                            disabled={isSubmitting}
                        />
                    </div>

                    <div className="space-y-2 sm:col-span-2">
                        <Label htmlFor="subject">Judul / Subject</Label>
                        <Input
                            id="subject"
                            value={subject}
                            onChange={(e) => setSubject(e.target.value)}
                            placeholder="Contoh: Keluhan layanan"
                            disabled={isSubmitting}
                        />
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="priority">Prioritas</Label>
                        <select
                            id="priority"
                            className="h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                            value={priority}
                            onChange={(e) => setPriority(e.target.value as any)}
                            disabled={isSubmitting}
                        >
                            <option value="high">Tinggi</option>
                            <option value="medium">Sedang</option>
                            <option value="low">Rendah</option>
                        </select>
                    </div>

                    <div className="space-y-2 sm:col-span-2">
                        <Label htmlFor="notes">Deskripsi / Catatan</Label>
                        <Textarea
                            id="notes"
                            rows={5}
                            value={notes}
                            onChange={(e) => setNotes(e.target.value)}
                            placeholder="Tambahkan catatan jika diperlukan…"
                            disabled={isSubmitting}
                        />
                    </div>
                </div>

                <div className="mt-4 flex justify-end">
                    <Button type="submit" disabled={isSubmitting}>
                        {isSubmitting ? 'Membuat…' : 'Buat Ticket'}
                    </Button>
                </div>
            </form>
        </div>
    );
}
