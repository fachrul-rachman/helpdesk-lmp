export type AdminUserListItem = {
    id: string;
    name: string;
    phone_number: string;
    role: 'admin' | 'pic';
    division: { id: string; name: string } | null;
    is_active: boolean;
    created_at: string | null;
};

export type AdminUsersIndexResponse = {
    data: AdminUserListItem[];
    meta: { total: number; page: number; per_page: number };
};

export type AdminDivisionWorkingHour = {
    day_of_week: 'monday' | 'tuesday' | 'wednesday' | 'thursday' | 'friday' | 'saturday' | 'sunday';
    start_time: string;
    end_time: string;
    is_active: boolean;
};

export type AdminDivision = {
    id: string;
    name: string;
    description: string;
    handles: string;
    not_handles: string;
    ticket_examples: string;
    sla_resolution_value: number;
    sla_resolution_unit: 'hours' | 'days';
    sla_resolution_reminder_value: number;
    sla_resolution_reminder_unit: 'hours' | 'days';
    is_fallback: boolean;
    is_active: boolean;
    pic_count: number;
    working_hours: AdminDivisionWorkingHour[];
};

export type AdminDivisionsIndexResponse = {
    data: AdminDivision[];
};

export type AdminSettingsResponse = {
    sla_fr_duration_minutes: number;
    sla_fr_reminder_minutes: number;
    notify_spv_on_new_ticket: boolean;
};

export type AdminAuditLogListItem = {
    id: string;
    user: { id: string; name: string; role: string } | null;
    action: string;
    subject_type: string | null;
    subject_id: string | null;
    payload: unknown;
    ip_address: string | null;
    created_at: string | null;
};

export type AdminAuditLogsIndexResponse = {
    data: AdminAuditLogListItem[];
    meta: { total: number; page: number; per_page: number };
};
