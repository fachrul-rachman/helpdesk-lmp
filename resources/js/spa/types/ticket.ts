import type { TicketPriority, TicketStatus } from '../components/common/badges';

export type ApiListMeta = {
    total: number;
    page: number;
    per_page: number;
};

export type ApiListResponse<T> = {
    data: T[];
    meta: ApiListMeta;
};

export type Customer = {
    id: string | null;
    name: string | null;
    phone_number: string | null;
    notes?: string | null;
    deleted: boolean;
};

export type TicketSubcategory = { id: string; name: string };

export type TicketSubcategoryOptions = {
    global: Array<TicketSubcategory & { division_id: null }>;
    division: Array<TicketSubcategory & { division_id: string }>;
};

export type TicketListItem = {
    id: string;
    ticket_number?: string | null;
    subject: string;
    status: TicketStatus;
    priority: TicketPriority;
    site: string | null;
    zone: string | null;
    lot_number: string | null;
    has_takeover_request?: boolean;
    takeover_request_status?: 'pending' | 'approved' | null;
    customer: Customer;
    division: { id: string; name: string } | null;
    global_subcategory: TicketSubcategory | null;
    division_subcategory: TicketSubcategory | null;
    assigned_to: { id: string; name: string } | null;
    sla_fr_status: string | null;
    sla_resolution_status: string | null;
    sla_resolution_deadline_at: string | null;
    created_at: string | null;
};

export type TicketDetail = {
    id: string;
    ticket_number?: string | null;
    subject: string;
    status: TicketStatus;
    priority: TicketPriority;
    notes: string | null;
    site: string | null;
    zone: string | null;
    lot_number: string | null;
    takeover_request?: {
        id: string;
        status: 'pending' | 'approved';
        reason: string;
        requested_by: { id: string; name?: string | null };
        approved_at?: string | null;
        created_at?: string | null;
    } | null;
    customer: Customer;
    division: { id: string; name: string } | null;
    global_subcategory: TicketSubcategory | null;
    division_subcategory: TicketSubcategory | null;
    assigned_to: { id: string; name: string; role: string } | null;
    created_by: string | null;
    ai_confidence: number | null;
    sla: {
        fr_status: string | null;
        fr_started_at: string | null;
        fr_deadline_at: string | null;
        fr_completed_at: string | null;
        resolution_status: string | null;
        resolution_started_at: string | null;
        resolution_deadline_at: string | null;
    };
    created_at: string | null;
};

export type MessageAttachment = {
    id: string;
    type: string;
    file_name: string;
    url?: string | null;
    mime_type: string;
    size_bytes: number;
};

export type TicketMessage = {
    id: string;
    ticket_id: string | null;
    sender_type: 'customer' | 'pic' | 'spv' | 'ai' | 'system';
    sender: { name: string | null };
    content: string;
    attachments: MessageAttachment[];
    created_at: string | null;
};
