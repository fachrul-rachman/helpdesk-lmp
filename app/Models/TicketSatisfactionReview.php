<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TicketSatisfactionReview extends Model
{
    protected $table = 'ticket_satisfaction_reviews';

    protected $fillable = [
        'ticket_id',
        'customer_id',
        'rating',
        'feedback',
        'ip_address',
        'user_agent',
        'submitted_at',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
    ];
}

