import { useState, type FormEvent } from 'react';

import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
import { Textarea } from '../../components/ui/textarea';
import { api } from '../../lib/axios';

type Props = {
    ticketId: string;
    customerName: string;
    summary: string;
};

export function ResumeConversationForm({
    ticketId,
    customerName: initialCustomerName,
    summary: initialSummary,
}: Props) {
    const [customerName, setCustomerName] = useState(initialCustomerName);
    const [summary, setSummary] = useState(initialSummary.slice(0, 250));
    const [isSending, setIsSending] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [isSent, setIsSent] = useState(false);

    async function handleSubmit(event: FormEvent) {
        event.preventDefault();
        setError(null);

        if (!customerName.trim() || !summary.trim()) {
            setError('Nama penerima dan pembaruan singkat wajib diisi.');
            return;
        }

        setIsSending(true);
        try {
            await api.post(`/api/tickets/${ticketId}/resume-conversation`, {
                customer_name: customerName.trim(),
                summary: summary.trim(),
            });
            setIsSent(true);
        } catch (requestError: any) {
            setError(
                String(
                    requestError?.response?.data?.message ??
                        'Pesan belum berhasil dikirim. Silakan coba lagi.',
                ),
            );
        } finally {
            setIsSending(false);
        }
    }

    if (isSent) {
        return (
            <div className="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">
                Pesan penghubung sedang dikirim. Anda dapat melanjutkan
                percakapan setelah customer merespons.
            </div>
        );
    }

    return (
        <form
            className="space-y-3 rounded-lg border border-amber-200 bg-amber-50 p-3"
            onSubmit={handleSubmit}
        >
            <div>
                <div className="text-sm font-semibold text-amber-950">
                    Balasan langsung belum tersedia
                </div>
                <p className="mt-1 text-sm text-amber-800">
                    Kirim pesan penghubung terlebih dahulu. Setelah customer
                    merespons, Anda dapat melanjutkan percakapan seperti biasa.
                </p>
            </div>

            <label className="block space-y-1 text-sm font-medium text-slate-700">
                <span>Nama penerima</span>
                <Input
                    value={customerName}
                    maxLength={100}
                    onChange={(event) => setCustomerName(event.target.value)}
                    disabled={isSending}
                />
            </label>

            <label className="block space-y-1 text-sm font-medium text-slate-700">
                <span>Pembaruan singkat</span>
                <Textarea
                    value={summary}
                    maxLength={250}
                    rows={3}
                    onChange={(event) => setSummary(event.target.value)}
                    disabled={isSending}
                />
                <span className="block text-right text-xs font-normal text-slate-500">
                    {summary.length}/250
                </span>
            </label>

            <div className="rounded-md border border-amber-200 bg-white p-3 text-sm text-slate-700">
                <div className="mb-1 text-xs font-semibold text-slate-500">
                    Preview pesan
                </div>
                Halo {customerName || 'Customer'}, tim kami sudah kembali
                tersedia.
                <br />
                Kami membutuhkan balasan dari Anda agar dapat memulai dan
                melanjutkan percakapan mengenai {summary || 'ticket Anda'}.
                Silakan balas pesan ini untuk melanjutkan.
            </div>

            {error ? <div className="text-sm text-red-700">{error}</div> : null}

            <Button type="submit" disabled={isSending}>
                {isSending ? 'Mengirim...' : 'Hubungi customer'}
            </Button>
        </form>
    );
}
