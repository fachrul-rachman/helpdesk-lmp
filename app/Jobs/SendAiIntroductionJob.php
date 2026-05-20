<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Services\NotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SendAiIntroductionJob implements ShouldQueue
{
    use Queueable;

    public function handle(NotificationService $notificationService): void
    {
        $now = CarbonImmutable::now();
        $threshold = $now->subDays(14);

        Customer::query()
            ->where(function ($q) use ($threshold) {
                $q->whereNull('last_interaction_at')
                    ->orWhere('last_interaction_at', '<', $threshold);
            })
            ->chunkById(200, function ($customers) use ($notificationService, $now) {
                foreach ($customers as $customer) {
                    try {
                        if (!$customer->phone_number) {
                            continue;
                        }

                        $audit = DB::table('audit_logs')
                            ->where('action', 'customer.ai_introduction_sent')
                            ->where('subject_type', 'Customer')
                            ->where('subject_id', $customer->id);

                        if ($customer->last_interaction_at) {
                            $audit->where('created_at', '>=', $customer->last_interaction_at);
                        }

                        if ($audit->exists()) {
                            continue;
                        }

                        $notificationService->sendAiIntroduction($customer->phone_number, (string) ($customer->name ?? ''));

                        DB::table('audit_logs')->insert([
                            'user_id' => null,
                            'action' => 'customer.ai_introduction_sent',
                            'subject_type' => 'Customer',
                            'subject_id' => $customer->id,
                            'payload' => null,
                            'ip_address' => null,
                            'created_at' => $now,
                        ]);
                    } catch (\Throwable $e) {
                        Log::warning('ai_introduction.send_failed', [
                            'customer_id' => $customer->id ?? null,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });
    }
}

