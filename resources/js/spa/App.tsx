import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';

import { LoginPage } from './features/auth/LoginPage';
import { RequireAuth } from './features/auth/RequireAuth';
import { RequireRole } from './features/auth/RequireRole';
import { RoleRedirect } from './features/auth/RoleRedirect';
import { useAuthBootstrap } from './features/auth/useAuthBootstrap';
import { AdminLayout } from './features/admin/AdminLayout';
import { AdminUsersPage } from './features/admin/AdminUsersPage';
import { AdminDivisionsPage } from './features/admin/AdminDivisionsPage';
import { AdminSettingsPage } from './features/admin/AdminSettingsPage';
import { AdminAuditLogsPage } from './features/admin/AdminAuditLogsPage';
import { AdminMetaTemplatesPage } from './features/admin/AdminMetaTemplatesPage';
import { NotFoundPage } from './features/common/NotFoundPage';
import { PicLayout } from './features/pic/PicLayout';
import { PicTicketsListPage } from './features/pic/PicTicketsListPage';
import { PicTicketDetailPage } from './features/pic/PicTicketDetailPage';
import { PicHistoryPage } from './features/pic/PicHistoryPage';
import { SpvLayout } from './features/spv/SpvLayout';
import { SpvDashboardPage } from './features/spv/SpvDashboardPage';
import { SpvTicketsPage } from './features/spv/SpvTicketsPage';
import { SpvCreateTicketPage } from './features/spv/SpvCreateTicketPage';
import { SpvTicketDetailPage } from './features/spv/SpvTicketDetailPage';
import { SpvConversationsPage } from './features/spv/SpvConversationsPage';
import { SpvConversationDetailPage } from './features/spv/SpvConversationDetailPage';

export function App() {
    const { isBootstrapping } = useAuthBootstrap();

    if (isBootstrapping) {
        return (
            <main className="min-h-dvh grid place-items-center p-6">
                <div className="text-sm text-slate-600">Memuat...</div>
            </main>
        );
    }

    return (
        <BrowserRouter basename="/app">
            <Routes>
                <Route path="/login" element={<LoginPage />} />
                <Route element={<RequireAuth />}>
                    <Route path="/" element={<RoleRedirect />} />
                    <Route element={<RequireRole role="admin" />}>
                        <Route path="/admin" element={<AdminLayout />}>
                            <Route index element={<Navigate to="/admin/users" replace />} />
                            <Route path="users" element={<AdminUsersPage />} />
                            <Route path="divisions" element={<AdminDivisionsPage />} />
                            <Route path="settings" element={<AdminSettingsPage />} />
                            <Route path="meta-templates" element={<AdminMetaTemplatesPage />} />
                            <Route path="audit-logs" element={<AdminAuditLogsPage />} />
                        </Route>
                    </Route>
                    <Route element={<RequireRole role="spv" />}>
                        <Route path="/spv" element={<SpvLayout />}>
                            <Route path="dashboard" element={<SpvDashboardPage />} />
                            <Route path="tickets" element={<SpvTicketsPage />} />
                            <Route path="tickets/create" element={<SpvCreateTicketPage />} />
                            <Route path="tickets/:id" element={<SpvTicketDetailPage />} />
                            <Route path="conversations" element={<SpvConversationsPage />} />
                            <Route path="conversations/:customerId" element={<SpvConversationDetailPage />} />
                        </Route>
                    </Route>
                    <Route path="/pic" element={<PicLayout />}>
                        <Route path="tickets" element={<PicTicketsListPage />} />
                        <Route path="tickets/:id" element={<PicTicketDetailPage />} />
                        <Route path="history" element={<PicHistoryPage />} />
                    </Route>
                </Route>
                <Route path="*" element={<NotFoundPage />} />
            </Routes>
        </BrowserRouter>
    );
}
