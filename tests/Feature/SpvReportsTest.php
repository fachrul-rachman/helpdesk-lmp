<?php

use App\Models\Customer;
use App\Models\Division;
use App\Models\DivisionWorkingHour;
use App\Models\Ticket;
use App\Models\TicketSatisfactionReview;
use App\Models\User;
use App\Services\SlaService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tymon\JWTAuth\Facades\JWTAuth;

uses(RefreshDatabase::class);

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite');
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
    Http::preventStrayRequests();
    config(['app.business_timezone' => 'Asia/Jakarta']);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20 12:00:00', 'Asia/Jakarta')->utc());
    $this->spv = User::factory()->create(['role' => 'spv']);
    $this->headers = ['Authorization' => 'Bearer '.JWTAuth::fromUser($this->spv)];
    $this->division = Division::create([
        'name' => 'Services', 'description' => '', 'handles' => '', 'not_handles' => '',
        'ticket_examples' => '', 'sla_resolution_value' => 3, 'sla_resolution_unit' => 'days',
        'sla_resolution_reminder_value' => 12, 'sla_resolution_reminder_unit' => 'hours',
        'is_active' => true,
    ]);
    $this->customer = Customer::create(['name' => 'Customer test', 'phone_number' => '6281111111000']);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function reportTicket(object $test, array $attributes = []): Ticket
{
    $createdAt = $attributes['created_at'] ?? '2026-10-10 03:00:00';
    unset($attributes['created_at']);
    $ticket = Ticket::create(array_merge([
        'customer_id' => $test->customer->id, 'division_id' => $test->division->id,
        'created_by' => 'ai', 'priority' => 'medium', 'status' => 'open', 'subject' => 'Laporan',
    ], $attributes));
    $ticket->forceFill(['created_at' => $createdAt])->saveQuietly();

    return $ticket;
}

function reportUrl(string $section, array $filters = []): string
{
    return '/api/spv/reports?'.http_build_query(array_merge([
        'section' => $section, 'start_date' => '2026-10-01', 'end_date' => '2026-10-20',
    ], $filters));
}

test('report overview counts only in-period active tickets and separates queue', function () {
    reportTicket($this, ['status' => 'new']);
    reportTicket($this, ['status' => 'pending']);
    reportTicket($this, ['status' => 'queue']);
    reportTicket($this, ['status' => 'solved']);
    reportTicket($this, ['status' => 'open', 'created_at' => '2026-09-25 03:00:00']);
    reportTicket($this)->delete();

    $this->getJson(reportUrl('overview'), $this->headers)->assertOk()
        ->assertJsonPath('data.summary.total', 4)
        ->assertJsonPath('data.summary.active', 2)
        ->assertJsonPath('data.summary.completed', 1)
        ->assertJsonPath('data.summary.queued', 1)
        ->assertJsonPath('data.active_by_division.0.count', 2);
});

test('report date boundaries use business timezone and drilldowns match the chart', function () {
    reportTicket($this, ['status' => 'new', 'created_at' => '2026-09-30 17:00:00']);
    reportTicket($this, ['status' => 'new', 'created_at' => '2026-09-30 16:59:59']);
    reportTicket($this, ['status' => 'queue', 'created_at' => '2026-10-20 16:59:59']);
    reportTicket($this, ['status' => 'open', 'created_at' => '2026-10-20 17:00:00']);
    $this->getJson(reportUrl('overview'), $this->headers)->assertOk()->assertJsonPath('data.summary.total', 2);
    $this->getJson('/api/spv/reports/tickets?'.http_build_query([
        'start_date' => '2026-10-01', 'end_date' => '2026-10-20', 'filter' => 'active',
        'division_id' => $this->division->id,
    ]), $this->headers)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.status', 'new');
});

test('report SLA compares recorded deadlines and excludes incomplete and reversed times', function () {
    $base = ['sla_fr_started_at' => '2026-10-10 01:00:00', 'sla_fr_deadline_at' => '2026-10-10 01:05:00',
        'sla_fr_completed_at' => '2026-10-10 01:10:00', 'sla_fr_status' => 'done'];
    reportTicket($this, $base);
    reportTicket($this, array_merge($base, ['sla_fr_completed_at' => '2026-10-10 01:05:00']));
    reportTicket($this, array_merge($base, ['sla_fr_completed_at' => '2026-10-10 00:59:00']));
    reportTicket($this, ['status' => 'solved', 'sla_resolution_started_at' => '2026-10-10 01:00:00',
        'sla_resolution_deadline_at' => '2026-10-10 02:00:00', 'solved_at' => '2026-10-10 03:00:00',
        'sla_resolution_status' => 'done']);
    reportTicket($this, ['status' => 'pending', 'sla_resolution_status' => 'paused']);
    $this->getJson(reportUrl('sla'), $this->headers)->assertOk()
        ->assertJsonPath('data.first_response.eligible', 2)
        ->assertJsonPath('data.first_response.on_time', 1)
        ->assertJsonPath('data.first_response.late', 1)
        ->assertJsonPath('data.first_response.average_minutes', 7.5)
        ->assertJsonPath('data.resolution.eligible', 1)
        ->assertJsonPath('data.resolution.late', 1)
        ->assertJsonPath('data.divisions.0.resolution_target_minutes', 4320);
    $this->getJson('/api/spv/reports/tickets?'.http_build_query([
        'start_date' => '2026-10-01', 'end_date' => '2026-10-20', 'filter' => 'fr_late',
    ]), $this->headers)->assertOk()->assertJsonPath('meta.total', 1);
});

test('report average response follows division working hours', function () {
    foreach (['friday', 'monday'] as $day) {
        DivisionWorkingHour::create(['division_id' => $this->division->id, 'day_of_week' => $day,
            'start_time' => '08:00', 'end_time' => '17:00', 'is_active' => true]);
    }
    reportTicket($this, ['sla_fr_started_at' => '2026-10-09 09:50:00',
        'sla_fr_completed_at' => '2026-10-12 01:10:00', 'sla_fr_deadline_at' => '2026-10-12 02:00:00']);
    $this->getJson(reportUrl('sla'), $this->headers)->assertOk()
        ->assertJsonPath('data.first_response.average_minutes', 20);
});

test('satisfaction counts rating three as good and unreviewed completed tickets by solved date', function () {
    foreach ([1, 2, 3, 4, 5] as $rating) {
        $ticket = reportTicket($this, ['status' => 'solved', 'solved_at' => '2026-10-10 03:00:00']);
        TicketSatisfactionReview::create(['ticket_id' => $ticket->id, 'customer_id' => $this->customer->id,
            'rating' => $rating, 'feedback' => 'Feedback', 'submitted_at' => '2026-10-11 03:00:00']);
    }
    reportTicket($this, ['status' => 'closed', 'created_at' => '2026-09-10 03:00:00', 'solved_at' => '2026-10-12 03:00:00']);
    reportTicket($this, ['status' => 'open']);
    reportTicket($this, ['status' => 'closed', 'solved_at' => '2026-09-20 03:00:00']);
    $this->getJson(reportUrl('satisfaction'), $this->headers)->assertOk()
        ->assertJsonPath('data.summary.total', 5)->assertJsonPath('data.summary.good', 3)
        ->assertJsonPath('data.summary.bad', 2)->assertJsonPath('data.summary.unreviewed', 1)
        ->assertJsonPath('data.divisions.0.good', 3)->assertJsonPath('data.divisions.0.bad', 2);
    $this->getJson('/api/spv/reports/reviews?'.http_build_query([
        'start_date' => '2026-10-01', 'end_date' => '2026-10-20', 'rating_group' => 'good', 'per_page' => 2,
    ]), $this->headers)->assertOk()->assertJsonPath('meta.total', 3)->assertJsonCount(2, 'data');
    $this->getJson('/api/spv/reports/tickets?'.http_build_query([
        'start_date' => '2026-10-01', 'end_date' => '2026-10-20', 'filter' => 'unreviewed',
    ]), $this->headers)->assertOk()->assertJsonPath('meta.total', 1);
});

test('category and location empty groups retain exact drilldown counts', function () {
    reportTicket($this, ['site' => null]);
    reportTicket($this, ['site' => '']);
    reportTicket($this, ['site' => 'Site Utara']);
    $this->getJson(reportUrl('category'), $this->headers)->assertOk()
        ->assertJsonPath('data.categories.0.count', 3);
    $this->getJson('/api/spv/reports/tickets?'.http_build_query([
        'start_date' => '2026-10-01', 'end_date' => '2026-10-20', 'dimension' => 'site', 'missing' => 1,
    ]), $this->headers)->assertOk()->assertJsonPath('meta.total', 2);
});

test('report endpoints enforce SPV access and validate dates and filters', function () {
    $this->getJson(reportUrl('overview'))->assertUnauthorized();
    foreach (['pic', 'admin'] as $role) {
        $user = User::factory()->create(['role' => $role]);
        $headers = ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
        foreach (['/api/spv/reports', '/api/spv/reports/tickets', '/api/spv/reports/reviews'] as $url) {
            $this->getJson($url, $headers)->assertForbidden();
        }
    }
    $this->getJson(reportUrl('overview', ['end_date' => '2026-09-01']), $this->headers)->assertUnprocessable();
    $this->getJson(reportUrl('overview', ['start_date' => '2024-01-01']), $this->headers)->assertUnprocessable();
    $this->getJson(reportUrl('invalid'), $this->headers)->assertUnprocessable();
    $this->getJson(reportUrl('overview', ['dimension' => 'notes']), $this->headers)->assertUnprocessable();
    $this->getJson(reportUrl('overview', ['division_id' => 'invalid']), $this->headers)->assertUnprocessable();
    $this->getJson(reportUrl('category', ['dimension' => 'category', 'value' => 'invalid']), $this->headers)->assertUnprocessable();
    $this->getJson(reportUrl('category', ['dimension' => 'site']), $this->headers)->assertUnprocessable();
});

test('SLA report calendar cache is bounded and does not leak into other operations', function () {
    DivisionWorkingHour::create(['division_id' => $this->division->id, 'day_of_week' => 'monday',
        'start_time' => '08:00', 'end_time' => '17:00', 'is_active' => true]);
    $service = app(SlaService::class);
    $start = CarbonImmutable::parse('2026-10-12 01:00:00', 'UTC');
    $end = $start->addHour();
    DB::enableQueryLog();
    $service->withCachedCalendar(function () use ($service, $start, $end): void {
        for ($i = 0; $i < 10; $i++) {
            expect($service->calculateElapsedWorkingMinutes($start, $end, $this->division->id))->toBe(60);
        }
    });
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();
    expect($count)->toBeLessThanOrEqual(2);
    DivisionWorkingHour::where('division_id', $this->division->id)->update(['is_active' => false]);
    expect($service->calculateElapsedWorkingMinutes($start, $end, $this->division->id))->toBe(60);
    // A newly configured schedule must be read after the report callback ends.
    DivisionWorkingHour::where('division_id', $this->division->id)->update(['is_active' => true, 'start_time' => '09:00']);
    expect($service->calculateElapsedWorkingMinutes($start, $end, $this->division->id))->toBe(0);
});
