import { Navigate } from 'react-router-dom';

import { useAuthStore } from '../../stores/authStore';
import type { UserRole } from '../../types/auth';

function defaultPathForRole(role: UserRole) {
    if (role === 'admin') return '/admin/users';
    if (role === 'spv') return '/spv/dashboard';
    return '/pic/tickets';
}

export function RoleRedirect() {
    const user = useAuthStore((s) => s.user);

    if (!user) {
        return <Navigate to="/login" replace />;
    }

    return <Navigate to={defaultPathForRole(user.role)} replace />;
}

