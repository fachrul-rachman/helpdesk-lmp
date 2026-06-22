<?php

use App\Models\Customer;
use App\Models\Division;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tymon\JWTAuth\Facades\JWTAuth;

uses(RefreshDatabase::class);

function steAuth(User $user): array
{
    return ['Authorization' => 'Bearer ' . JWTAuth::fromUser($user)];
}

function steDivision(string $name): Division
{
    return Division::create([
        'name' => $name,
        'description' => 'Deskripsi',
        'handles' => 'Menangani ticket',
        'not_handles' => 'Tidak menangani ticket lain',
        'ticket_examples' => 'Contoh ticket',
        'sla_resolution_value' => 3,
        'sla_resolution_unit' => 'days',
        'sla_resolution_reminder_value' => 12,
        'sla_resolution_reminder_unit' => 'hours',
        'is_fallback' => false,
        'is_active' => true,
    ]);
}

function steTicket(Customer $customer, Division $division, array $attributes): Ticket
{
    return Ticket::create(array_merge([
        'customer_id' => $customer->id,
        'division_id' => $division->id,
        'assigned_to' => null,
        'created_by' => 'ai',
        'priority' => 'medium',
        'status' => 'solved',
        'subject' => 'Ticket export',
        'sla_fr_status' => 'done',
        'sla_resolution_status' => 'done',
    ], $attributes));
}

test('SPV dapat mengekspor ticket solved dan closed sesuai template dan first response PIC', function () {
    config(['app.business_timezone' => 'Asia/Jakarta']);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-22 10:00:00', 'Asia/Jakarta'));

    $spv = User::factory()->create(['role' => 'spv']);
    $marketing = steDivision('Marketing');
    $operasional = steDivision('Operasional');
    $picPertama = User::factory()->create([
        'name' => 'Budiman',
        'role' => 'pic',
        'division_id' => $marketing->id,
    ]);
    $picTerakhir = User::factory()->create([
        'name' => 'Sari',
        'role' => 'pic',
        'division_id' => $operasional->id,
    ]);
    $customer = Customer::create(['phone_number' => '6281111111111', 'name' => 'Andi']);

    $solved = steTicket($customer, $operasional, [
        'assigned_to' => $picTerakhir->id,
        'subject' => 'Permintaan perubahan data',
        'status' => 'solved',
        'created_at' => CarbonImmutable::parse('2026-06-02 01:15:00', 'UTC'),
        'updated_at' => CarbonImmutable::parse('2026-06-03 03:00:00', 'UTC'),
        'solved_at' => CarbonImmutable::parse('2026-06-03 03:00:00', 'UTC'),
    ]);
    Message::create([
        'ticket_id' => $solved->id,
        'customer_id' => $customer->id,
        'sender_type' => 'spv',
        'sender_id' => $spv->id,
        'content' => 'Pesan SPV tidak dihitung',
        'created_at' => CarbonImmutable::parse('2026-06-02 01:20:00', 'UTC'),
    ]);
    Message::create([
        'ticket_id' => $solved->id,
        'customer_id' => $customer->id,
        'sender_type' => 'pic',
        'sender_id' => $picPertama->id,
        'content' => 'Respons PIC pertama',
        'created_at' => CarbonImmutable::parse('2026-06-02 01:25:00', 'UTC'),
    ]);
    Message::create([
        'ticket_id' => $solved->id,
        'customer_id' => $customer->id,
        'sender_type' => 'pic',
        'sender_id' => $picTerakhir->id,
        'content' => 'Respons PIC setelah ticket dipindah',
        'created_at' => CarbonImmutable::parse('2026-06-02 02:00:00', 'UTC'),
    ]);

    $closed = steTicket($customer, $marketing, [
        'assigned_to' => $picPertama->id,
        'subject' => 'Ticket kedua',
        'status' => 'closed',
        'created_at' => CarbonImmutable::parse('2026-06-10 02:00:00', 'UTC'),
        'updated_at' => CarbonImmutable::parse('2026-06-11 09:30:00', 'UTC'),
        'closed_at' => CarbonImmutable::parse('2026-06-11 09:30:00', 'UTC'),
    ]);
    Message::create([
        'ticket_id' => $closed->id,
        'customer_id' => $customer->id,
        'sender_type' => 'pic',
        'sender_id' => $picPertama->id,
        'content' => 'Respons kedua',
        'created_at' => CarbonImmutable::parse('2026-06-10 02:08:00', 'UTC'),
    ]);

    steTicket($customer, $marketing, [
        'subject' => 'Belum selesai',
        'status' => 'open',
        'sla_resolution_status' => 'running',
        'created_at' => CarbonImmutable::parse('2026-06-05 02:00:00', 'UTC'),
    ]);
    steTicket($customer, $marketing, [
        'subject' => 'Di luar periode',
        'status' => 'solved',
        'created_at' => CarbonImmutable::parse('2026-05-30 02:00:00', 'UTC'),
        'solved_at' => CarbonImmutable::parse('2026-05-31 02:00:00', 'UTC'),
    ]);

    $response = $this->get('/api/spv/tickets/export?period=month', steAuth($spv));

    $response->assertOk();
    expect($response->baseResponse)->toBeInstanceOf(BinaryFileResponse::class);
    expect($response->headers->get('content-disposition'))->toContain('rekap-data-respons-tiket-juni-2026.xlsx');

    $spreadsheet = IOFactory::load($response->baseResponse->getFile()->getPathname());
    $sheet = $spreadsheet->getActiveSheet();

    expect($sheet->getCell('A1')->getValue())->toContain('Periode: 1 – 30 Juni 2026');
    expect($sheet->getMergeCells())->toHaveKeys(['A1:K1', 'A2:A3', 'B2:B3', 'F2:G2', 'H2:I2', 'J2:K2']);
    expect($sheet->rangeToArray('A4:K4', null, true, true, false)[0])->toBe([
        1,
        $solved->ticket_number,
        'Permintaan perubahan data',
        'Budiman',
        'Marketing',
        '02-06-2026',
        '08:15',
        '02-06-2026',
        '08:25',
        '03-06-2026',
        '10:00',
    ]);
    expect($sheet->getCell('A5')->getValue())->toBe(2);
    expect($sheet->getCell('B5')->getValue())->toBe($closed->ticket_number);
    expect($sheet->getCell('A6')->getValue())->toBeNull();

    $spreadsheet->disconnectWorksheets();
});

test('rentang tanggal bebas bersifat inklusif dan divalidasi', function () {
    config(['app.business_timezone' => 'Asia/Jakarta']);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-22 10:00:00', 'Asia/Jakarta'));

    $spv = User::factory()->create(['role' => 'spv']);
    $division = steDivision('Layanan');
    $customer = Customer::create(['phone_number' => '6281222222222', 'name' => 'Budi']);

    $ticket = steTicket($customer, $division, [
        'created_at' => CarbonImmutable::parse('2026-06-20 16:59:59', 'UTC'),
        'solved_at' => CarbonImmutable::parse('2026-06-21 02:00:00', 'UTC'),
    ]);

    $response = $this->get('/api/spv/tickets/export?period=custom&start_date=2026-06-21&end_date=2026-06-21', steAuth($spv));
    $response->assertOk();

    $spreadsheet = IOFactory::load($response->baseResponse->getFile()->getPathname());
    expect($spreadsheet->getActiveSheet()->getCell('B4')->getValue())->toBe($ticket->ticket_number);
    $spreadsheet->disconnectWorksheets();

    $this->getJson('/api/spv/tickets/export?period=custom&start_date=2026-06-22&end_date=2026-06-21', steAuth($spv))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['end_date']);
});

test('PIC tidak dapat mengakses export ticket SPV', function () {
    $pic = User::factory()->create(['role' => 'pic']);

    $this->getJson('/api/spv/tickets/export?period=month', steAuth($pic))->assertForbidden();
});
