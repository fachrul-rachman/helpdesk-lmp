export type UserRole = 'admin' | 'spv' | 'pic';

export type AuthUser = {
    id: string;
    name: string;
    role: UserRole;
    division_id: string | null;
};

export type LoginResponse = {
    access_token: string;
    refresh_token: string;
    token_type: 'Bearer';
    expires_in: number;
    user: AuthUser;
};

