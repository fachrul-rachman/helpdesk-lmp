<?php

namespace App\Support;

use App\Events\MessageSent;
use App\Events\SlaWarning;
use App\Events\TicketAssigned;
use App\Events\TicketStatusChanged;
use App\Models\Ticket;

final class TicketPushNotification
{
    private const BODIES = [
        'message' => 'Pesan baru dari customer.',
        'assigned' => 'Ticket ditugaskan kepada Anda.',
        'reopened' => 'Status ticket berubah dari on progress menjadi open.',
        'sla_fr_reminder' => 'SLA respons pertama mendekati batas waktu.',
        'sla_fr_overdue' => 'SLA respons pertama melewati batas waktu.',
        'sla_resolution_reminder' => 'SLA penyelesaian mendekati batas waktu.',
        'sla_resolution_overdue' => 'SLA penyelesaian melewati batas waktu.',
    ];

    public static function kind(object $event): ?string
    {
        $data = $event->data;

        return match (true) {
            $event instanceof MessageSent && ($data['sender_type'] ?? '') === 'customer' => 'message',
            $event instanceof TicketAssigned => 'assigned',
            $event instanceof TicketStatusChanged && ($data['from_status'] ?? '') === 'on_progress' && ($data['to_status'] ?? '') === 'open' => 'reopened',
            $event instanceof SlaWarning && isset(self::BODIES[$key = 'sla_'.($data['sla_type'] ?? '').'_'.($data['warning_type'] ?? '')]) => $key,
            default => null,
        };
    }

    public static function recipientId(Ticket $ticket): ?string
    {
        $takeover = $ticket->takeoverRequest;
        if ($takeover?->status === 'approved' && $takeover->requested_by === $ticket->assigned_to && $takeover->approved_by) {
            return (string) $takeover->approved_by;
        }

        return $ticket->assigned_to ? (string) $ticket->assigned_to : null;
    }

    public static function payload(Ticket $ticket, string $role, string $kind, string $eventId): array
    {
        return [
            'title' => 'Ticket '.$ticket->ticket_number,
            'body' => self::BODIES[$kind],
            'url' => '/app/'.($role === 'spv' ? 'spv' : 'pic').'/tickets/'.rawurlencode($ticket->id),
            'tag' => 'ticket-'.$ticket->id.'-'.$kind,
            'event_id' => $eventId,
        ];
    }

    public static function relevant(Ticket $ticket, string $kind, ?string $deadlineAt = null): bool
    {
        if (in_array($ticket->status, ['solved', 'closed', 'queue'], true) || ($kind === 'reopened' && $ticket->status !== 'open')) {
            return false;
        }
        if (str_starts_with($kind, 'sla_')) {
            $type = str_starts_with($kind, 'sla_fr_') ? 'fr' : 'resolution';
            $deadline = $ticket->{'sla_'.$type.'_deadline_at'};

            $expectedStatus = str_ends_with($kind, '_reminder') ? 'running' : 'overdue';

            return $ticket->{'sla_'.$type.'_status'} === $expectedStatus
                && $deadline && $deadlineAt && $deadline->equalTo($deadlineAt);
        }

        return true;
    }
}
