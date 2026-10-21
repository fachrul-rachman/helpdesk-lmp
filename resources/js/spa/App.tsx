import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';

import { Button } from './components/ui/button';
import { AdminAuditLogsPage } from './features/admin/AdminAuditLogsPage';
import { AdminDivisionsPage } from './features/admin/AdminDivisionsPage';
import { AdminLayout } from './features/admin/AdminLayout';
import { AdminMetaTemplatesPage } from './features/admin/AdminMetaTemplatesPage';
import { AdminSettingsPage } from './features/admin/AdminSettingsPage';
import { AdminTicketSubcategoriesPage } from './features/admin/AdminTicketSubcategoriesPage';
import { AdminUsersPage } from './features/admin/AdminUsersPage';
import { LoginPage } from './features/auth/LoginPage';
import { RequireAuth } from './features/auth/RequireAuth';
import { RequireRole } from './features/auth/RequireRole';
import { RoleRedirect } from './features/auth/RoleRedirect';
import { useAuthBootstrap } from './features/auth/useAuthBootstrap';
import { NotFoundPage } from './features/common/NotFoundPage';
import { PicHistoryPage } from './features/pic/PicHistoryPage';
import { PicLayout } from './features/pic/PicLayout';
import { PicTicketDetailPage } from './features/pic/PicTicketDetailPage';
import { PicTicketsListPage } from './features/pic/PicTicketsListPage';
import { SpvConversationDetailPage } from './features/spv/SpvConversationDetailPage';
import { SpvConversationsPage } from './features/spv/SpvConversationsPage';
import { SpvCreateTicketPage } from './features/spv/SpvCreateTicketPage';
import { SpvDashboardPage } from './features/spv/SpvDashboardPage';
import { SpvLayout } from './features/spv/SpvLayout';
import { SpvReportsPage } from './features/spv/SpvReportsPage';
import { SpvTicketDetailPage } from './features/spv/SpvTicketDetailPage';
import { SpvTicketsPage } from './features/spv/SpvTicketsPage';

export function App() {
    const { isBootstrapping, sessionError, retry, isRetrying, accessToken } =
        useAuthBootstrap();

    if (isBootstrapping) {
        return (
            <main className="grid min-h-dvh place-items-center p-6">
                <div className="text-sm text-slate-600">Memuat...</div>
            </main>
        );
    }

    const connectionNotice = sessionError ? (
        <div
            role="alert"
            className="flex flex-wrap items-center justify-center gap-3 bg-amber-50 px-4 py-3 text-sm text-amber-950"
        >
            <p>{sessionError}</p>
            <Button
                type="button"
                variant="outline"
                disabled={isRetrying}
                onClick={() => void retry()}
            >
                {isRetrying ? 'Menghubungkan...' : 'Coba lagi'}
            </Button>
        </div>
    ) : null;

    if (sessionError && !accessToken) {
        return (
            <main className="grid min-h-dvh place-items-center p-4">
                {connectionNotice}
            </main>
        );
    }

    return (
        <BrowserRouter basename="/app">
            {connectionNotice}
            <Routes>
                <Route path="/login" element={<LoginPage />} />
                <Route element={<RequireAuth />}>
                    <Route path="/" element={<RoleRedirect />} />
                    <Route element={<RequireRole role="admin" />}>
                        <Route path="/admin" element={<AdminLayout />}>
                            <Route
                                index
                                element={<Navigate to="/admin/users" replace />}
                            />
                            <Route path="users" element={<AdminUsersPage />} />
                            <Route
                                path="divisions"
                                element={<AdminDivisionsPage />}
                            />
                            <Route
                                path="ticket-subcategories"
                                element={<AdminTicketSubcategoriesPage />}
                            />
                            <Route
                                path="settings"
                                element={<AdminSettingsPage />}
                            />
                            <Route
                                path="meta-templates"
                                element={<AdminMetaTemplatesPage />}
                            />
                            <Route
                                path="audit-logs"
                                element={<AdminAuditLogsPage />}
                            />
                        </Route>
                    </Route>
                    <Route element={<RequireRole role="spv" />}>
                        <Route path="/spv" element={<SpvLayout />}>
                            <Route
                                path="dashboard"
                                element={<SpvDashboardPage />}
                            />
                            <Route
                                path="reports"
                                element={<SpvReportsPage />}
                            />
                            <Route
                                path="tickets"
                                element={<SpvTicketsPage />}
                            />
                            <Route
                                path="tickets/create"
                                element={<SpvCreateTicketPage />}
                            />
                            <Route
                                path="tickets/:id"
                                element={<SpvTicketDetailPage />}
                            />
                            <Route
                                path="conversations"
                                element={<SpvConversationsPage />}
                            />
                            <Route
                                path="conversations/:customerId"
                                element={<SpvConversationDetailPage />}
                            />
                        </Route>
                    </Route>
                    <Route path="/pic" element={<PicLayout />}>
                        <Route
                            path="tickets"
                            element={<PicTicketsListPage />}
                        />
                        <Route
                            path="tickets/:id"
                            element={<PicTicketDetailPage />}
                        />
                        <Route path="history" element={<PicHistoryPage />} />
                    </Route>
                </Route>
                <Route path="*" element={<NotFoundPage />} />
            </Routes>
        </BrowserRouter>
    );
}
