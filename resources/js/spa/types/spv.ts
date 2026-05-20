export type SpvAnalyticsResponse = {
    tickets: {
        total: number;
        active: number;
        per_division: Array<{ division_id: string; division_name: string; count: number }>;
    };
    sla_fr: {
        average_minutes_overall: number;
        per_division: Array<{ division_id: string; division_name: string; average_minutes: number }>;
    };
};

export type SpvConversationListItem = {
    customer: { id: string; name: string | null; phone_number: string | null };
    last_message: { content: string; sender_type: string; created_at: string } | null;
    active_ticket: { id: string; status: string; subject: string } | null;
};

