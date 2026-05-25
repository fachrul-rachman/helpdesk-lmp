<?php

use App\Models\Ticket;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{userId}', function ($user, string $userId) {
    return (string) $user->id === (string) $userId;
});

Broadcast::channel('dashboard.spv', function ($user) {
    return (string) ($user->role ?? '') === 'spv';
});

Broadcast::channel('ticket.{ticketId}', function ($user, string $ticketId) {
    if ((string) ($user->role ?? '') === 'spv') {
        return true;
    }

    $ticket = Ticket::query()->find($ticketId);
    if (!$ticket) {
        return false;
    }

    return (string) ($ticket->assigned_to ?? '') === (string) $user->id;
});
