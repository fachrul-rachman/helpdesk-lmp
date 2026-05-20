<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Support\AuditLogger;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function show()
    {
        $settings = AppSetting::query()
            ->whereIn('key', ['sla_fr_duration_minutes', 'sla_fr_reminder_minutes'])
            ->pluck('value', 'key');

        return response()->json([
            'sla_fr_duration_minutes' => (int) ($settings['sla_fr_duration_minutes'] ?? 5),
            'sla_fr_reminder_minutes' => (int) ($settings['sla_fr_reminder_minutes'] ?? 3),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'sla_fr_duration_minutes' => ['required', 'integer', 'min:1'],
            'sla_fr_reminder_minutes' => ['required', 'integer', 'min:0'],
        ]);

        if ($data['sla_fr_reminder_minutes'] >= $data['sla_fr_duration_minutes']) {
            return response()->json([
                'message' => 'Threshold reminder harus lebih kecil dari durasi SLA FR.',
            ], 422);
        }

        AppSetting::upsert([
            ['key' => 'sla_fr_duration_minutes', 'value' => (string) $data['sla_fr_duration_minutes']],
            ['key' => 'sla_fr_reminder_minutes', 'value' => (string) $data['sla_fr_reminder_minutes']],
        ], ['key'], ['value']);

        AuditLogger::log(
            action: 'admin.settings.updated',
            subject: null,
            payload: ['data' => $data],
        );

        return response()->json(['message' => 'Konfigurasi berhasil disimpan.'], 200);
    }
}

