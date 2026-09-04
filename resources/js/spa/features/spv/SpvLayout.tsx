import { Link, NavLink, Outlet, useNavigate } from 'react-router-dom';

import { ChatIcon, DashboardIcon, TicketIcon } from '../../components/common/icons';
import { Button } from '../../components/ui/button';
import { PushNotifications } from '../../components/common/PushNotifications';
import { cn } from '../../lib/utils';
import { useAuthStore } from '../../stores/authStore';

function NavItem({ to, label, icon }: { to: string; label: string; icon: React.ReactNode }) {
    return (
        <NavLink
            to={to}
            className={({ isActive }) =>
                cn(
                    'flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium',
                    isActive ? 'bg-blue-50 text-blue-700' : 'text-slate-700 hover:bg-slate-50'
                )
            }
        >
            <span className="h-5 w-5">{icon}</span>
            <span>{label}</span>
        </NavLink>
    );
}

export function SpvLayout() {
    const navigate = useNavigate();
    const user = useAuthStore((s) => s.user);
    const clear = useAuthStore((s) => s.clear);

    async function handleLogout() {
        await clear();
        navigate('/login', { replace: true });
    }

    return (
        <div className="min-h-dvh bg-slate-50">
            <div className="flex min-h-dvh w-full">
                <aside className="sticky top-0 hidden h-dvh w-72 shrink-0 border-r border-slate-200 bg-white p-4 lg:flex lg:flex-col">
                    <Link to="/spv/dashboard" className="text-base font-semibold text-slate-900">
                        Helpdesk
                    </Link>
                    <div className="mt-6 space-y-1">
                        <NavItem to="/spv/dashboard" label="Dashboard" icon={<DashboardIcon className="h-5 w-5" />} />
                        <NavItem to="/spv/tickets" label="Semua Ticket" icon={<TicketIcon className="h-5 w-5" />} />
                        <NavItem to="/spv/conversations" label="Percakapan" icon={<ChatIcon className="h-5 w-5" />} />
                    </div>

                    <div className="mt-auto pt-4">
                        <div className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                            <div className="text-sm font-semibold text-slate-900">{user?.name ?? '-'}</div>
                            <div className="text-xs text-slate-600">SPV</div>
                            <Button className="mt-3 w-full" variant="secondary" onClick={handleLogout}>
                                Keluar
                            </Button>
                        </div>
                    </div>
                </aside>

                <div className="flex min-w-0 flex-1 flex-col">
                    <header className="sticky top-0 z-10 border-b border-slate-200 bg-white/80 backdrop-blur">
                        <div className="flex items-center justify-between gap-3 px-4 py-3">
                            <div>
                                <div className="text-sm font-semibold text-slate-900">SPV</div>
                                <div className="text-xs text-slate-600">
                                    {user?.name ? `Halo, ${user.name}` : 'Memuat user…'}
                                </div>
                            </div>
                            <div className="lg:hidden">
                                <Button variant="secondary" onClick={() => navigate('/spv/tickets/create')}>
                                    Buat Ticket
                                </Button>
                            </div>
                        </div>
                    </header>

                    <PushNotifications />

                    <main className="min-w-0 flex-1 px-4 py-4 pb-20 lg:pb-6">
                        <Outlet />
                    </main>

                    <nav className="fixed bottom-0 left-0 right-0 z-20 border-t border-slate-200 bg-white lg:hidden">
                        <div className="grid grid-cols-3 gap-1 px-2 py-2">
                            <NavLink
                                to="/spv/dashboard"
                                className={({ isActive }) =>
                                    cn(
                                        'flex flex-col items-center justify-center gap-1 rounded-md px-3 py-2 text-xs font-semibold',
                                        isActive
                                            ? 'text-blue-700'
                                            : 'text-slate-600 hover:text-slate-900'
                                    )
                                }
                            >
                                <DashboardIcon className="h-5 w-5" />
                                <span>Dashboard</span>
                            </NavLink>
                            <NavLink
                                to="/spv/tickets"
                                className={({ isActive }) =>
                                    cn(
                                        'flex flex-col items-center justify-center gap-1 rounded-md px-3 py-2 text-xs font-semibold',
                                        isActive
                                            ? 'text-blue-700'
                                            : 'text-slate-600 hover:text-slate-900'
                                    )
                                }
                            >
                                <TicketIcon className="h-5 w-5" />
                                <span>Ticket</span>
                            </NavLink>
                            <NavLink
                                to="/spv/conversations"
                                className={({ isActive }) =>
                                    cn(
                                        'flex flex-col items-center justify-center gap-1 rounded-md px-3 py-2 text-xs font-semibold',
                                        isActive
                                            ? 'text-blue-700'
                                            : 'text-slate-600 hover:text-slate-900'
                                    )
                                }
                            >
                                <ChatIcon className="h-5 w-5" />
                                <span>Chat</span>
                            </NavLink>
                        </div>
                    </nav>
                </div>
            </div>
        </div>
    );
}
