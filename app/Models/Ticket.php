<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Ticket extends Model
{
    use HasFactory, SoftDeletes, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'ticket_seq',
        'ticket_number',
        'customer_id',
        'division_id',
        'global_subcategory_id',
        'division_subcategory_id',
        'site',
        'zone',
        'lot_number',
        'assigned_to',
        'created_by',
        'priority',
        'status',
        'subject',
        'notes',
        'ai_confidence',
        'sla_fr_started_at',
        'sla_fr_deadline_at',
        'sla_fr_completed_at',
        'sla_fr_status',
        'sla_resolution_started_at',
        'sla_resolution_deadline_at',
        'sla_resolution_paused_at',
        'sla_resolution_paused_duration',
        'sla_resolution_status',
        'queue_position',
        'queue_priority',
        'activated_at',
        'solved_at',
        'closed_at',
        'satisfaction_review_sent_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (Ticket $ticket): void {
            if ($ticket->ticket_number && $ticket->ticket_seq) {
                return;
            }

            $seq = 0;
            if (DB::getDriverName() === 'pgsql') {
                $seqRow = DB::selectOne("SELECT nextval('tickets_ticket_seq_seq') AS seq");
                $seq = (int) ($seqRow->seq ?? 0);
            } else {
                $seq = (int) (DB::table('tickets')->max('ticket_seq') ?? 0) + 1;
            }
            if ($seq <= 0) {
                throw new \RuntimeException('Gagal membuat nomor ticket.');
            }

            $now = CarbonImmutable::now('Asia/Jakarta');
            $yearTwoDigits = $now->format('y');
            $padded = str_pad((string) $seq, 5, '0', STR_PAD_LEFT);

            $ticket->ticket_seq = $seq;
            $ticket->ticket_number = "T{$yearTwoDigits}-{$padded}";
        });
    }

    protected function casts(): array
    {
        return [
            'ai_confidence' => 'decimal:2',
            'sla_fr_started_at' => 'datetime',
            'sla_fr_deadline_at' => 'datetime',
            'sla_fr_completed_at' => 'datetime',
            'sla_resolution_started_at' => 'datetime',
            'sla_resolution_deadline_at' => 'datetime',
            'sla_resolution_paused_at' => 'datetime',
            'activated_at' => 'datetime',
            'solved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    public function globalSubcategory(): BelongsTo
    {
        return $this->belongsTo(TicketSubcategory::class, 'global_subcategory_id');
    }

    public function divisionSubcategory(): BelongsTo
    {
        return $this->belongsTo(TicketSubcategory::class, 'division_subcategory_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function slaPauses(): HasMany
    {
        return $this->hasMany(TicketSlaPause::class);
    }

    public function takeoverRequest(): HasOne
    {
        return $this->hasOne(TicketTakeoverRequest::class);
    }
}
