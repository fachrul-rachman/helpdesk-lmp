import { Navigate, Outlet, useLocation } from 'react-router-dom';

import { useAuthStore } from '../../stores/authStore';

export function RequireAuth() {
    const accessToken = useAuthStore((s) => s.accessToken);
    const location = useLocation();

    if (!accessToken) {
        return <Navigate to="/login" replace state={{ from: location.pathname }} />;
    }

    return <Outlet />;
}

