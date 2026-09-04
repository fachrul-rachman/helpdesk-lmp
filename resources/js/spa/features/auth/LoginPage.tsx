import { useEffect, useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';

import { api } from '../../lib/axios';
import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
import { Label } from '../../components/ui/label';
import { EyeIcon, EyeOffIcon } from '../../components/common/icons';
import { useAuthStore } from '../../stores/authStore';
import { clearLocalPush } from '../../lib/pushRegistration';
import type { LoginResponse, UserRole } from '../../types/auth';

function defaultPathForRole(role: UserRole) {
    if (role === 'admin') return '/admin/users';
    if (role === 'spv') return '/spv/dashboard';
    return '/pic/tickets';
}

export function LoginPage() {
    const navigate = useNavigate();
    const location = useLocation();
    const from = location.state?.from;
    const destination = typeof from === 'string' && /^\/(pic|spv)\/tickets\/[a-zA-Z0-9-]+$/.test(from) ? from : null;
    const accessToken = useAuthStore((s) => s.accessToken);
    const user = useAuthStore((s) => s.user);
    const setTokens = useAuthStore((s) => s.setTokens);
    const setUser = useAuthStore((s) => s.setUser);

    const [phoneNumber, setPhoneNumber] = useState('');
    const [password, setPassword] = useState('');
    const [showPassword, setShowPassword] = useState(false);
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [errorMessage, setErrorMessage] = useState<string | null>(null);

    useEffect(() => {
        if (accessToken && user) {
            navigate(destination ?? defaultPathForRole(user.role), { replace: true });
        }
    }, [accessToken, user, navigate, destination]);

    async function handleSubmit(event: React.FormEvent) {
        event.preventDefault();
        setErrorMessage(null);

        if (!phoneNumber.trim() || !password.trim()) {
            setErrorMessage('Nomor HP dan password wajib diisi.');
            return;
        }

        setIsSubmitting(true);
        try {
            await clearLocalPush();
            const response = await api.post<LoginResponse>('/api/auth/login', {
                phone_number: phoneNumber,
                password,
            });

            setTokens({
                accessToken: response.data.access_token,
                refreshToken: response.data.refresh_token,
            });
            setUser(response.data.user);

            navigate(destination ?? defaultPathForRole(response.data.user.role), { replace: true });
        } catch (error: any) {
            const message =
                error?.response?.data?.message ??
                (error?.response?.status === 422
                    ? 'Data yang Anda kirim tidak valid.'
                    : 'Gagal login. Silakan coba lagi.');
            setErrorMessage(String(message));
        } finally {
            setIsSubmitting(false);
        }
    }

    return (
        <main className="min-h-dvh grid place-items-center p-6">
            <div className="w-full max-w-sm rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
                <div className="text-center">
                    <h1 className="text-xl font-semibold">Selamat Datang</h1>
                    <p className="mt-1 text-sm text-slate-600">Masuk untuk melanjutkan</p>
                </div>

                <form className="mt-6 space-y-4" onSubmit={handleSubmit}>
                    <div className="space-y-2">
                        <Label htmlFor="phone_number">Nomor HP</Label>
                        <Input
                            id="phone_number"
                            name="phone_number"
                            placeholder="Contoh: 628123456789"
                            autoComplete="username"
                            inputMode="numeric"
                            value={phoneNumber}
                            onChange={(e) => setPhoneNumber(e.target.value)}
                            disabled={isSubmitting}
                        />
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="password">Password</Label>
                        <div className="flex gap-2">
                            <Input
                                id="password"
                                name="password"
                                type={showPassword ? 'text' : 'password'}
                                placeholder="Masukkan password"
                                autoComplete="current-password"
                                value={password}
                                onChange={(e) => setPassword(e.target.value)}
                                disabled={isSubmitting}
                            />
                            <Button
                                type="button"
                                variant="secondary"
                                className="shrink-0 w-10 px-0"
                                onClick={() => setShowPassword((v) => !v)}
                                disabled={isSubmitting}
                                aria-label={showPassword ? 'Sembunyikan password' : 'Lihat password'}
                                title={showPassword ? 'Sembunyikan password' : 'Lihat password'}
                            >
                                {showPassword ? (
                                    <EyeOffIcon className="h-5 w-5" />
                                ) : (
                                    <EyeIcon className="h-5 w-5" />
                                )}
                            </Button>
                        </div>
                    </div>

                    {errorMessage ? (
                        <div className="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                            {errorMessage}
                        </div>
                    ) : null}

                    <Button type="submit" className="w-full" disabled={isSubmitting}>
                        {isSubmitting ? 'Memproses…' : 'Masuk'}
                    </Button>
                </form>
            </div>
        </main>
    );
}
