<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MetaWhatsappTemplate extends Model
{
    protected $table = 'meta_whatsapp_templates';

    protected $fillable = [
        'meta_template_id',
        'name',
        'language',
        'status',
        'category',
        'sub_category',
        'components',
        'raw',
        'last_synced_at',
    ];

    protected $casts = [
        'components' => 'array',
        'raw' => 'array',
        'last_synced_at' => 'datetime',
    ];
}

