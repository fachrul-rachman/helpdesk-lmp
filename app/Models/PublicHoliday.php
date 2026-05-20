<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PublicHoliday extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'date',
        'name',
        'year',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }
}

