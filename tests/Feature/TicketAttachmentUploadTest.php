<?php

use App\Models\Customer;
use App\Models\Division;
use App\Models\MessageAttachment;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tymon\JWTAuth\Facades\JWTAuth;

uses(RefreshDatabase::class);

function tauAuth(User $user): array
{
    return ['Authorization' => 'Bearer ' . JWTAuth::fromUser($user)];
}

test('POST messages mendukung upload attachment dan menyimpan ke r2', function () {
    config(['queue.default' => 'sync']);
    Storage::fake('r2');

    config([
        'filesystems.disks.r2.url' => 'https://cdn.example.com',
        'services.meta_whatsapp.token' => 'test-token',
        'services.meta_whatsapp.phone_number_id' => '123',
        'services.meta_whatsapp.api_url' => 'https://graph.facebook.com/v18.0',
    ]);

    Http::fake([
        'https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.out']]], 200),
    ]);

    $division = Division::create([
        'name' => 'Teknis',
        'description' => 'Desc',
        'handles' => 'Handles',
        'not_handles' => 'Not',
        'ticket_examples' => 'Examples',
        'sla_resolution_value' => 3,
        'sla_resolution_unit' => 'days',
        'sla_resolution_reminder_value' => 12,
        'sla_resolution_reminder_unit' => 'hours',
        'is_fallback' => false,
        'is_active' => true,
    ]);

    $pic = User::factory()->create([
        'role' => 'pic',
        'division_id' => $division->id,
        'is_active' => true,
        'phone_number' => '6281111111111',
        'name' => 'Budi',
    ]);

    $customer = Customer::create(['phone_number' => '6282222222222', 'name' => 'Andi']);
    $ticket = Ticket::create([
        'customer_id' => $customer->id,
        'division_id' => $division->id,
        'assigned_to' => $pic->id,
        'created_by' => 'ai',
        'priority' => 'high',
        'status' => 'open',
        'subject' => 'Test',
        'sla_fr_status' => 'done',
        'sla_resolution_status' => 'running',
    ]);

    $img = UploadedFile::fake()->image('foto.jpg')->size(500);
    $pdf = UploadedFile::fake()->create('laporan.pdf', 200, 'application/pdf');

    $this->post("/api/tickets/{$ticket->id}/messages", [
        'content' => 'Ini lampirannya.',
        'attachments' => [$img, $pdf],
    ], tauAuth($pic))->assertStatus(201);

    expect(MessageAttachment::count())->toBe(2);

    $year = now()->format('Y');
    $month = now()->format('m');
    $files = Storage::disk('r2')->allFiles("media/{$year}/{$month}");
    expect(count($files))->toBe(2);

    Http::assertSentCount(2);
    Http::assertSent(function ($request) {
        return ($request['type'] ?? null) !== 'text' && ($request['type'] ?? null) !== 'template';
    });
});
