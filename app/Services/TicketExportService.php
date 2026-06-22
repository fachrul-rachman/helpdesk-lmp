<?php

namespace App\Services;

use App\Models\Ticket;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use RuntimeException;

class TicketExportService
{
    /**
     * @param  array{period: string, start_date?: string|null, end_date?: string|null}  $filters
     * @return array{path: string, filename: string}
     */
    public function export(array $filters): array
    {
        [$from, $until] = $this->resolvePeriod($filters);
        $timezone = config('app.business_timezone', 'Asia/Jakarta');

        $tickets = Ticket::query()
            ->whereIn('status', ['solved', 'closed'])
            ->whereBetween('created_at', [
                $from->utc(),
                $until->utc(),
            ])
            ->with([
                'messages' => function ($query): void {
                    $query->where('sender_type', 'pic')
                        ->whereNotNull('sender_id')
                        ->oldest('created_at')
                        ->oldest('id');
                },
                'messages.sender' => function ($query): void {
                    $query->withTrashed()->with([
                        'division' => fn ($divisionQuery) => $divisionQuery->withTrashed(),
                    ]);
                },
            ])
            ->oldest('created_at')
            ->oldest('id')
            ->get();

        $template = resource_path('templates/ticket-export/rekap-data-respons-tiket.xlsx');
        if (! is_file($template)) {
            throw new RuntimeException('Template export ticket tidak ditemukan.');
        }

        $spreadsheet = IOFactory::load($template);
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', "REKAP DATA RESPONS TIKET\nPeriode: {$this->periodLabel($from, $until)}");
        $sheet->getStyle('A1')->getAlignment()->setWrapText(true);

        foreach ($tickets as $index => $ticket) {
            $row = 4 + $index;
            if ($row > 4) {
                foreach (range('A', 'K') as $column) {
                    $sheet->duplicateStyle($sheet->getStyle("{$column}4"), "{$column}{$row}");
                }
                $sheet->getRowDimension($row)->setRowHeight($sheet->getRowDimension(4)->getRowHeight());
            }

            $firstResponse = $ticket->messages->first();
            $createdAt = CarbonImmutable::instance($ticket->created_at)->setTimezone($timezone);
            $respondedAt = $firstResponse
                ? CarbonImmutable::instance($firstResponse->created_at)->setTimezone($timezone)
                : null;
            $resolvedValue = $ticket->status === 'closed' ? $ticket->closed_at : $ticket->solved_at;
            $resolvedAt = $resolvedValue
                ? CarbonImmutable::instance($resolvedValue)->setTimezone($timezone)
                : null;

            $sheet->fromArray([[
                $index + 1,
                $ticket->ticket_number,
                $ticket->subject,
                $firstResponse?->sender?->name,
                $firstResponse?->sender?->division?->name,
                $createdAt->format('d-m-Y'),
                $createdAt->format('H:i'),
                $respondedAt?->format('d-m-Y'),
                $respondedAt?->format('H:i'),
                $resolvedAt?->format('d-m-Y'),
                $resolvedAt?->format('H:i'),
            ]], null, "A{$row}", true);
            $sheet->setCellValueExplicit("A{$row}", $index + 1, DataType::TYPE_NUMERIC);
            $sheet->getStyle("A{$row}:K{$row}")->getAlignment()
                ->setVertical(Alignment::VERTICAL_CENTER);
        }

        $directory = storage_path('app/tmp/ticket-exports');
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException('Folder sementara export ticket tidak dapat dibuat.');
        }

        $filename = $this->filename($from, $until, $filters['period']);
        $path = $directory.DIRECTORY_SEPARATOR.Str::uuid().'.xlsx';
        IOFactory::createWriter($spreadsheet, 'Xlsx')->save($path);
        $spreadsheet->disconnectWorksheets();

        return ['path' => $path, 'filename' => $filename];
    }

    /**
     * @param  array{period: string, start_date?: string|null, end_date?: string|null}  $filters
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function resolvePeriod(array $filters): array
    {
        $timezone = config('app.business_timezone', 'Asia/Jakarta');
        $now = CarbonImmutable::now($timezone);

        return match ($filters['period']) {
            'week' => [$now->startOfWeek(), $now->endOfWeek()],
            'month' => [$now->startOfMonth(), $now->endOfMonth()],
            'year' => [$now->startOfYear(), $now->endOfYear()],
            'custom' => [
                CarbonImmutable::createFromFormat('!Y-m-d', (string) $filters['start_date'], $timezone)->startOfDay(),
                CarbonImmutable::createFromFormat('!Y-m-d', (string) $filters['end_date'], $timezone)->endOfDay(),
            ],
        };
    }

    private function periodLabel(CarbonImmutable $from, CarbonImmutable $until): string
    {
        $from = $from->locale('id');
        $until = $until->locale('id');

        if ($from->isSameMonth($until)) {
            return $from->format('j').' – '.$until->translatedFormat('j F Y');
        }

        return $from->translatedFormat('j F Y').' – '.$until->translatedFormat('j F Y');
    }

    private function filename(CarbonImmutable $from, CarbonImmutable $until, string $period): string
    {
        $label = $period === 'month'
            ? $from->locale('id')->translatedFormat('F Y')
            : $this->periodLabel($from, $until);

        return 'rekap-data-respons-tiket-'.Str::slug($label).'.xlsx';
    }
}
