<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AuditLogger
{
    public static function log(
        string $action,
        ?Model $subject = null,
        array $payload = [],
        ?User $user = null,
    ): void {
        AuditLog::create([
            'user_id' => $user?->id ?? auth()->id(),
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->id,
            'payload' => $payload ?: null,
            'ip_address' => request()->ip(),
        ]);
    }
}

