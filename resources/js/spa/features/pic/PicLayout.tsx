import { Link, NavLink, Outlet, useNavigate } from 'react-router-dom';

import { Button } from '../../components/ui/button';
import { cn } from '../../lib/utils';
import { useAuthStore } from '../../stores/authStore';

function NavItem({
    to,
    label,
    icon,
}: {
    to: string;
    label: string;
    icon: React.ReactNode;
}) {
    return (
        <NavLink
            to={to}
            className={({ isActive }) =>
                cn(
                    'flex flex-col items-center justify-center gap-1 rounded-md px-3 py-2 text-xs',
                    isActive ? 'text-blue-700' : 'text-slate-600 hover:text-slate-900'
                )
            }
        >
            <span className="h-5 w-5">{icon}</span>
            <span className="font-medium">{label}</span>
        </NavLink>
    );
}

function HomeIcon(props: React.SVGProps<SVGSVGElement>) {
    return (
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" {...props}>
            <path
                d="M3 10.5 12 3l9 7.5V21a0 0 0 0 1 0 0h-6v-7H9v7H3v-10.5Z"
                stroke="currentColor"
                strokeWidth="2"
                strokeLinejoin="round"
            />
        </svg>
    );
}

function HistoryIcon(props: React.SVGProps<SVGSVGElement>) {
    return (
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" {...props}>
            <path
                d="M3 12a9 9 0 1 0 3-6.7"
                stroke="currentColor"
                strokeWidth="2"
                strokeLinecap="round"
            />
            <path
                d="M3 4v5h5"
                stroke="currentColor"
                strokeWidth="2"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
            <path
                d="M12 7v5l3 2"
                stroke="currentColor"
                strokeWidth="2"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
        </svg>
    );
}

export function PicLayout() {
    const navigate = useNavigate();
    const user = useAuthStore((s) => s.user);
    const clear = useAuthStore((s) => s.clear);

    function handleLogout() {
        clear();
        navigate('/login', { replace: true });
    }

    return (
        <div className="min-h-dvh bg-slate-50">
            <div className="flex min-h-dvh w-full">
                <aside className="sticky top-0 hidden h-dvh w-64 shrink-0 border-r border-slate-200 bg-white p-4 lg:flex lg:flex-col">
                    <Link to="/pic/tickets" className="text-base font-semibold text-slate-900">
                        Helpdesk
                    </Link>
                    <div className="mt-6 space-y-1">
                        <NavLink
                            to="/pic/tickets"
                            className={({ isActive }) =>
                                cn(
                                    'flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium',
                                    isActive
                                        ? 'bg-blue-50 text-blue-700'
                                        : 'text-slate-700 hover:bg-slate-50'
                                )
                            }
                        >
                            <HomeIcon className="h-5 w-5" />
                            Ticket Saya
                        </NavLink>
                        <NavLink
                            to="/pic/history"
                            className={({ isActive }) =>
                                cn(
                                    'flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium',
                                    isActive
                                        ? 'bg-blue-50 text-blue-700'
                                        : 'text-slate-700 hover:bg-slate-50'
                                )
                            }
                        >
                            <HistoryIcon className="h-5 w-5" />
                            Riwayat
                        </NavLink>
                    </div>

                    <div className="mt-auto pt-4">
                        <div className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                            <div className="text-sm font-semibold text-slate-900">{user?.name ?? '-'}</div>
                            <div className="text-xs text-slate-600">PIC</div>
                            <Button className="mt-3 w-full" variant="secondary" onClick={handleLogout}>
                                Keluar
                            </Button>
                        </div>
                    </div>
                </aside>

                <div className="flex min-w-0 flex-1 flex-col">
                    <header className="sticky top-0 z-10 border-b border-slate-200 bg-white/80 backdrop-blur">
                        <div className="px-4 py-3">
                            <div className="text-sm font-semibold text-slate-900">PIC</div>
                            <div className="text-xs text-slate-600">
                                {user?.name ? `Halo, ${user.name}` : 'Memuat user…'}
                            </div>
                        </div>
                    </header>

                    <main className="min-w-0 flex-1 px-4 py-4 pb-20 lg:pb-6">
                        <Outlet />
                    </main>

                    <nav className="fixed bottom-0 left-0 right-0 z-20 border-t border-slate-200 bg-white lg:hidden">
                        <div className="grid grid-cols-2 gap-1 px-2 py-2">
                            <NavItem to="/pic/tickets" label="Ticket" icon={<HomeIcon className="h-5 w-5" />} />
                            <NavItem to="/pic/history" label="Riwayat" icon={<HistoryIcon className="h-5 w-5" />} />
                        </div>
                    </nav>
                </div>
            </div>
        </div>
    );
}
