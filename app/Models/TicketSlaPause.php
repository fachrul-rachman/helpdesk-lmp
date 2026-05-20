<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class TicketSlaPause extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'ticket_sla_pauses';

    public $incrementing = false;

    protected $keyType = 'string';

    public const CREATED_AT = null;
    public const UPDATED_AT = null;

    protected $fillable = [
        'ticket_id',
        'paused_at',
        'resumed_at',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'paused_at' => 'datetime',
            'resumed_at' => 'datetime',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
