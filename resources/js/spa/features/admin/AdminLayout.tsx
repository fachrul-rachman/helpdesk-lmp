import { Link, NavLink, Outlet, useNavigate } from 'react-router-dom';

import { BuildingIcon, ClipboardIcon, SettingsIcon, TemplateIcon, UsersIcon } from '../../components/common/icons';
import { Button } from '../../components/ui/button';
import { cn } from '../../lib/utils';
import { useAuthStore } from '../../stores/authStore';

function SidebarItem({ to, label, icon }: { to: string; label: string; icon: React.ReactNode }) {
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

function MobileNavItem({ to, label, icon }: { to: string; label: string; icon: React.ReactNode }) {
    return (
        <NavLink
            to={to}
            className={({ isActive }) =>
                cn(
                    'flex flex-col items-center justify-center gap-1 rounded-md px-3 py-2 text-[11px] font-semibold',
                    isActive ? 'text-blue-700' : 'text-slate-600 hover:text-slate-900'
                )
            }
        >
            <span className="h-5 w-5">{icon}</span>
            <span>{label}</span>
        </NavLink>
    );
}

export function AdminLayout() {
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
                <aside className="sticky top-0 hidden h-dvh w-72 shrink-0 border-r border-slate-200 bg-white p-4 lg:flex lg:flex-col">
                    <Link to="/admin/users" className="text-base font-semibold text-slate-900">
                        Helpdesk
                    </Link>
                    <div className="mt-6 space-y-1">
                        <SidebarItem to="/admin/users" label="User" icon={<UsersIcon className="h-5 w-5" />} />
                        <SidebarItem to="/admin/divisions" label="Divisi" icon={<BuildingIcon className="h-5 w-5" />} />
                        <SidebarItem to="/admin/settings" label="Konfigurasi" icon={<SettingsIcon className="h-5 w-5" />} />
                        <SidebarItem to="/admin/meta-templates" label="Template WA" icon={<TemplateIcon className="h-5 w-5" />} />
                        <SidebarItem to="/admin/audit-logs" label="Audit Log" icon={<ClipboardIcon className="h-5 w-5" />} />
                    </div>

                    <div className="mt-auto pt-4">
                        <div className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                            <div className="text-sm font-semibold text-slate-900">{user?.name ?? '-'}</div>
                            <div className="text-xs text-slate-600">Admin</div>
                            <Button className="mt-3 w-full" variant="secondary" onClick={handleLogout}>
                                Keluar
                            </Button>
                        </div>
                    </div>
                </aside>

                <div className="flex min-w-0 flex-1 flex-col">
                    <header className="sticky top-0 z-10 border-b border-slate-200 bg-white/80 backdrop-blur">
                        <div className="px-4 py-3">
                            <div className="text-sm font-semibold text-slate-900">Admin</div>
                            <div className="text-xs text-slate-600">{user?.name ? `Halo, ${user.name}` : 'Memuat user...'}</div>
                        </div>
                    </header>

                    <main className="min-w-0 flex-1 px-4 py-4 pb-24 lg:pb-6">
                        <Outlet />
                    </main>

                    <nav className="fixed bottom-0 left-0 right-0 z-20 border-t border-slate-200 bg-white lg:hidden">
                        <div className="grid grid-cols-5 gap-1 px-2 py-2">
                            <MobileNavItem to="/admin/users" label="User" icon={<UsersIcon className="h-5 w-5" />} />
                            <MobileNavItem to="/admin/divisions" label="Divisi" icon={<BuildingIcon className="h-5 w-5" />} />
                            <MobileNavItem to="/admin/settings" label="Konfig" icon={<SettingsIcon className="h-5 w-5" />} />
                            <MobileNavItem to="/admin/meta-templates" label="Template" icon={<TemplateIcon className="h-5 w-5" />} />
                            <MobileNavItem to="/admin/audit-logs" label="Audit" icon={<ClipboardIcon className="h-5 w-5" />} />
                        </div>
                    </nav>
                </div>
            </div>
        </div>
    );
}
