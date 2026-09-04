<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Division extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'name',
        'description',
        'handles',
        'not_handles',
        'ticket_examples',
        'sla_resolution_value',
        'sla_resolution_unit',
        'sla_resolution_reminder_value',
        'sla_resolution_reminder_unit',
        'is_fallback',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_fallback' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function workingHours(): HasMany
    {
        return $this->hasMany(DivisionWorkingHour::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function subcategories(): HasMany
    {
        return $this->hasMany(TicketSubcategory::class);
    }
}
