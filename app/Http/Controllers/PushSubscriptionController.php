<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use App\Models\RefreshToken;
use App\Services\WebPushService;
use App\Support\PushEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushSubscriptionController extends Controller
{
    public function config(): JsonResponse
    {
        return response()->json([
            'enabled' => WebPushService::enabled(),
            'public_key' => WebPushService::enabled() ? config('webpush.public_key') : null,
        ])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless(WebPushService::enabled(), 503, 'Push notifikasi belum diaktifkan di server.');
        abort_unless($request->user()->is_active, 403);
        $data = $request->validate([
            'refresh_token' => ['required', 'string', 'max:512'],
            'endpoint' => ['required', 'string', 'max:2048', fn ($attribute, $value, $fail) => PushEndpoint::allowed($value) ?: $fail('Endpoint push tidak didukung.')],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{87}=?$/'],
            'keys.auth' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{22}(==)?$/'],
        ]);

        $session = RefreshToken::query()->where('user_id', $request->user()->id)
            ->where('token_hash', hash('sha256', $data['refresh_token']))
            ->whereNull('revoked_at')->where('expires_at', '>', now())->first();
        abort_unless($session, 403, 'Sesi perangkat tidak valid. Silakan login kembali.');

        $hash = hash('sha256', $data['endpoint']);
        $attributes = [
            'user_id' => $request->user()->id,
            'refresh_token_id' => $session->id,
            'endpoint' => $data['endpoint'],
            'public_key' => $data['keys']['p256dh'],
            'auth_token' => $data['keys']['auth'],
            'vapid_key_hash' => hash('sha256', config('webpush.public_key')),
        ];
        // firstOrCreate also handles concurrent inserts without rebinding another account.
        $subscription = PushSubscription::query()->firstOrCreate(['endpoint_hash' => $hash], $attributes);
        abort_if((string) $subscription->user_id !== (string) $request->user()->id, 409, 'Perangkat masih terhubung ke akun lain. Nonaktifkan lalu aktifkan kembali notifikasi.');
        $subscription->fill($attributes)->save();

        return response()->json(['message' => 'Notifikasi perangkat aktif.']);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:2048']]);
        if (WebPushService::enabled()) {
            PushSubscription::query()->where('user_id', $request->user()->id)
                ->where('endpoint_hash', hash('sha256', $data['endpoint']))->delete();
        }

        return response()->json(['message' => 'Notifikasi perangkat nonaktif.']);
    }
}
