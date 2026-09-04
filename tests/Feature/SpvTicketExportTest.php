<?php

use App\Models\Customer;
use App\Models\Division;
use App\Models\Ticket;
use App\Models\TicketSubcategory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tymon\JWTAuth\Facades\JWTAuth;

uses(RefreshDatabase::class);

function steAuth(User $user): array
{
    return ['Authorization' => 'Bearer '.JWTAuth::fromUser($user)];
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
    $timestamps = array_intersect_key($attributes, array_flip(['created_at', 'updated_at']));
    $ticket = Ticket::create(array_merge([
        'customer_id' => $customer->id,
        'division_id' => $division->id,
        'assigned_to' => null,
        'created_by' => 'ai',
        'priority' => 'medium',
        'status' => 'solved',
        'subject' => 'Ticket export',
        'sla_fr_status' => 'done',
        'sla_resolution_status' => 'done',
    ], array_diff_key($attributes, $timestamps)));

    if ($timestamps !== []) {
        $ticket->forceFill($timestamps)->saveQuietly();
    }

    return $ticket->fresh();
}

test('SPV dapat mengekspor ticket solved dan closed sesuai kolom laporan baru', function () {
    config(['app.business_timezone' => 'Asia/Jakarta']);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-22 10:00:00', 'Asia/Jakarta'));

    $spv = User::factory()->create(['role' => 'spv']);
    $operasional = steDivision('Operasional');
    $complaint = TicketSubcategory::create([
        'division_id' => null,
        'name' => 'Complaint',
        'is_active' => true,
    ]);
    $tombPecah = TicketSubcategory::create([
        'division_id' => $operasional->id,
        'name' => 'Tomb Pecah',
        'is_active' => true,
    ]);
    $customer = Customer::create(['phone_number' => '6281111111111', 'name' => 'Andi']);

    steTicket($customer, $operasional, [
        'global_subcategory_id' => $complaint->id,
        'division_subcategory_id' => $tombPecah->id,
        'site' => 'Cluster A',
        'zone' => 'Zona 2',
        'lot_number' => 'A-17',
        'subject' => 'Permintaan perubahan data',
        'notes' => 'Data customer sudah diperbarui.',
        'status' => 'solved',
        'sla_fr_completed_at' => CarbonImmutable::parse('2026-06-02 08:20:00', 'Asia/Jakarta'),
        'created_at' => CarbonImmutable::parse('2026-06-02 08:15:00', 'Asia/Jakarta'),
        'updated_at' => CarbonImmutable::parse('2026-06-03 10:00:00', 'Asia/Jakarta'),
        'solved_at' => CarbonImmutable::parse('2026-06-03 10:00:00', 'Asia/Jakarta'),
    ]);

    steTicket($customer, $operasional, [
        'subject' => '=2+2',
        'notes' => '=SUM(1,2)',
        'status' => 'closed',
        'sla_fr_completed_at' => CarbonImmutable::parse('2026-06-10 09:08:00', 'Asia/Jakarta'),
        'created_at' => CarbonImmutable::parse('2026-06-10 09:00:00', 'Asia/Jakarta'),
        'updated_at' => CarbonImmutable::parse('2026-06-11 16:30:00', 'Asia/Jakarta'),
        'closed_at' => CarbonImmutable::parse('2026-06-11 16:30:00', 'Asia/Jakarta'),
    ]);

    steTicket($customer, $operasional, [
        'subject' => 'Belum selesai',
        'status' => 'open',
        'sla_resolution_status' => 'running',
        'created_at' => CarbonImmutable::parse('2026-06-05 09:00:00', 'Asia/Jakarta'),
    ]);
    steTicket($customer, $operasional, [
        'subject' => 'Di luar periode',
        'status' => 'solved',
        'created_at' => CarbonImmutable::parse('2026-05-30 09:00:00', 'Asia/Jakarta'),
        'solved_at' => CarbonImmutable::parse('2026-05-31 09:00:00', 'Asia/Jakarta'),
    ]);

    $response = $this->get('/api/spv/tickets/export?period=month', steAuth($spv));

    $response->assertOk();
    expect($response->baseResponse)->toBeInstanceOf(BinaryFileResponse::class);
    expect($response->headers->get('content-disposition'))->toContain('rekap-data-respons-tiket-juni-2026.xlsx');

    $spreadsheet = IOFactory::load($response->baseResponse->getFile()->getPathname());
    $sheet = $spreadsheet->getActiveSheet();

    expect($sheet->getCell('A1')->getValue())->toContain('Periode: 1 – 30 Juni 2026');
    expect($sheet->getMergeCells())->toHaveKey('A1:P1');
    expect($sheet->rangeToArray('A2:P2', null, true, true, false)[0])->toBe([
        'No',
        'Nama Customer',
        'No Telp Customer',
        'Tanggal diterima',
        'Jam diterima',
        'Site',
        'Zone',
        'Nomor Lot',
        'Deskripsi',
        'Tanggal respon pertama',
        'Waktu respon pertama',
        'Kategori',
        'Sub-kategori',
        'Department',
        'Konklusi',
        'Status',
    ]);
    expect($sheet->getCell('A3')->getValue())->toBe(1);
    expect($sheet->getCell('B3')->getValue())->toBe('Andi');
    expect($sheet->getCell('C3')->getValue())->toBe('6281111111111');
    expect($sheet->getCell('C3')->getDataType())->toBe(DataType::TYPE_STRING);
    expect($sheet->getCell('D3')->getFormattedValue())->toBe('02-06-2026');
    expect($sheet->getCell('E3')->getFormattedValue())->toBe('08:15');
    expect($sheet->rangeToArray('F3:P3', null, true, true, false)[0])->toBe([
        'Cluster A',
        'Zona 2',
        'A-17',
        'Permintaan perubahan data',
        '02-06-2026',
        '08:20',
        'Complaint',
        'Tomb Pecah',
        'Operasional',
        'Data customer sudah diperbarui.',
        'Solved',
    ]);
    expect($sheet->getCell('A4')->getValue())->toBe(2);
    expect($sheet->getCell('I4')->getValue())->toBe('=2+2');
    expect($sheet->getCell('I4')->getDataType())->toBe(DataType::TYPE_STRING);
    expect($sheet->getCell('O4')->getValue())->toBe('=SUM(1,2)');
    expect($sheet->getCell('O4')->getDataType())->toBe(DataType::TYPE_STRING);
    expect($sheet->getCell('A5')->getValue())->toBeNull();
    expect($sheet->getAutoFilter()->getRange())->toBe('A2:P4');
    expect($sheet->getFreezePane())->toBe('A3');

    $spreadsheet->disconnectWorksheets();
});

test('rentang tanggal bebas bersifat inklusif dan divalidasi', function () {
    config(['app.business_timezone' => 'Asia/Jakarta']);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-22 10:00:00', 'Asia/Jakarta'));

    $spv = User::factory()->create(['role' => 'spv']);
    $division = steDivision('Layanan');
    $customer = Customer::create(['phone_number' => '6281222222222', 'name' => 'Budi']);

    $ticket = steTicket($customer, $division, [
        'created_at' => CarbonImmutable::parse('2026-06-21 00:00:00', 'Asia/Jakarta'),
        'solved_at' => CarbonImmutable::parse('2026-06-21 09:00:00', 'Asia/Jakarta'),
    ]);

    $response = $this->get('/api/spv/tickets/export?period=custom&start_date=2026-06-21&end_date=2026-06-21', steAuth($spv));
    $response->assertOk();

    $spreadsheet = IOFactory::load($response->baseResponse->getFile()->getPathname());
    expect($spreadsheet->getActiveSheet()->getCell('I3')->getValue())->toBe($ticket->subject);
    $spreadsheet->disconnectWorksheets();

    $this->getJson('/api/spv/tickets/export?period=custom&start_date=2026-06-22&end_date=2026-06-21', steAuth($spv))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['end_date']);
});

test('PIC tidak dapat mengakses export ticket SPV', function () {
    $pic = User::factory()->create(['role' => 'pic']);

    $this->getJson('/api/spv/tickets/export?period=month', steAuth($pic))->assertForbidden();
});
