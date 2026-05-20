<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $query = AuditLog::query()
            ->with(['user:id,name,role'])
            ->orderByDesc('created_at');

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->string('user_id'));
        }

        if ($request->filled('action')) {
            $action = trim((string) $request->input('action'));
            $query->where('action', 'ilike', "%{$action}%");
        }

        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->date('date_from')->startOfDay());
        }

        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->date('date_to')->endOfDay());
        }

        $perPage = (int) ($request->input('per_page') ?? 50);
        $logs = $query->paginate($perPage);

        return response()->json([
            'data' => $logs->getCollection()->map(function (AuditLog $log) {
                return [
                    'id' => $log->id,
                    'user' => $log->user ? [
                        'id' => $log->user->id,
                        'name' => $log->user->name,
                        'role' => $log->user->role,
                    ] : null,
                    'action' => $log->action,
                    'subject_type' => $log->subject_type,
                    'subject_id' => $log->subject_id,
                    'payload' => $log->payload,
                    'ip_address' => $log->ip_address,
                    'created_at' => optional($log->created_at)->toISOString(),
                ];
            })->values(),
            'meta' => [
                'total' => $logs->total(),
                'page' => $logs->currentPage(),
                'per_page' => $logs->perPage(),
            ],
        ]);
    }
}

