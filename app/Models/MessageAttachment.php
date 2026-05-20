<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class MessageAttachment extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'message_attachments';

    public $incrementing = false;

    protected $keyType = 'string';

    public const UPDATED_AT = null;

    protected $fillable = [
        'message_id',
        'type',
        'file_name',
        'r2_key',
        'mime_type',
        'size_bytes',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
