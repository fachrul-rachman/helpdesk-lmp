import { Link } from 'react-router-dom';

export function NotFoundPage() {
    return (
        <main className="min-h-dvh grid place-items-center p-6">
            <div className="w-full max-w-sm rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
                <h1 className="text-xl font-semibold">Halaman tidak ditemukan</h1>
                <p className="mt-1 text-sm text-slate-600">
                    URL yang Anda buka tidak tersedia.
                </p>
                <div className="mt-4">
                    <Link className="text-sm font-medium text-blue-600 hover:underline" to="/login">
                        Kembali ke Login
                    </Link>
                </div>
            </div>
        </main>
    );
}
