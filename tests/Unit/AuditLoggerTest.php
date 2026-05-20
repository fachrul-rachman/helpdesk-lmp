<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('audit logger membuat record audit log', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    AuditLogger::log(action: 'test.action');

    $log = AuditLog::query()->first();
    expect($log)->not()->toBeNull();
    expect($log->action)->toBe('test.action');
    expect((string) $log->user_id)->toBe((string) $user->id);
});

test('audit logger menyimpan subject dan payload', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $subject = User::factory()->create(['name' => 'Budi']);

    AuditLogger::log(
        action: 'admin.user.updated',
        subject: $subject,
        payload: ['before' => ['name' => 'A'], 'after' => ['name' => 'B']],
    );

    $log = AuditLog::query()->firstOrFail();
    expect($log->subject_type)->toBe('User');
    expect((string) $log->subject_id)->toBe((string) $subject->id);
    expect($log->payload)->toBe(['before' => ['name' => 'A'], 'after' => ['name' => 'B']]);
    expect($log->ip_address)->not()->toBeNull();
});

test('audit logger bisa override user', function () {
    $actor = User::factory()->create();
    $override = User::factory()->create();
    $this->actingAs($actor);

    AuditLogger::log(action: 'x', user: $override);

    $log = AuditLog::query()->firstOrFail();
    expect((string) $log->user_id)->toBe((string) $override->id);
});
