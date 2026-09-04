<?php

namespace App\Services;

use App\Models\Ticket;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
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
            ->select([
                'id',
                'customer_id',
                'division_id',
                'global_subcategory_id',
                'division_subcategory_id',
                'site',
                'zone',
                'lot_number',
                'subject',
                'notes',
                'status',
                'sla_fr_completed_at',
                'created_at',
            ])
            ->whereIn('status', ['solved', 'closed'])
            ->whereBetween('created_at', [
                $from->utc(),
                $until->utc(),
            ])
            ->with([
                'customer' => fn ($query) => $query->withTrashed()->select(['id', 'name', 'phone_number']),
                'division' => fn ($query) => $query->withTrashed()->select(['id', 'name']),
                'globalSubcategory:id,name',
                'divisionSubcategory:id,name',
            ])
            ->oldest('created_at')
            ->oldest('id')
            ->lazy(500);

        $template = resource_path('templates/ticket-export/rekap-data-respons-tiket.xlsx');
        if (! is_file($template)) {
            throw new RuntimeException('Template export ticket tidak ditemukan.');
        }

        $spreadsheet = IOFactory::load($template);
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', "REKAP DATA RESPONS TIKET\nPeriode: {$this->periodLabel($from, $until)}");
        $sheet->getStyle('A1')->getAlignment()->setWrapText(true);
        $sheet->freezePane('A3');
        $sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setFitToWidth(1)
            ->setFitToHeight(0);

        $ticketCount = 0;
        foreach ($tickets as $ticket) {
            $row = 3 + $ticketCount;
            if ($row > 3) {
                foreach (range('A', 'P') as $column) {
                    $sheet->duplicateStyle($sheet->getStyle("{$column}3"), "{$column}{$row}");
                }
            }

            $createdAt = CarbonImmutable::instance($ticket->created_at)->setTimezone($timezone);
            $respondedAt = $ticket->sla_fr_completed_at
                ? CarbonImmutable::instance($ticket->sla_fr_completed_at)->setTimezone($timezone)
                : null;

            $values = [
                'B' => $ticket->customer?->name,
                'C' => $ticket->customer?->phone_number,
                'F' => $ticket->site,
                'G' => $ticket->zone,
                'H' => $ticket->lot_number,
                'I' => $ticket->subject,
                'L' => $ticket->globalSubcategory?->name,
                'M' => $ticket->divisionSubcategory?->name,
                'N' => $ticket->division?->name,
                'O' => $ticket->notes,
                'P' => ucfirst((string) $ticket->status),
            ];

            $sheet->setCellValueExplicit("A{$row}", $ticketCount + 1, DataType::TYPE_NUMERIC);
            foreach ($values as $column => $value) {
                if ($value !== null) {
                    $sheet->setCellValueExplicit("{$column}{$row}", (string) $value, DataType::TYPE_STRING);
                }
            }

            $sheet->setCellValue("D{$row}", ExcelDate::PHPToExcel($createdAt));
            $sheet->setCellValue("E{$row}", ExcelDate::PHPToExcel($createdAt));
            if ($respondedAt) {
                $sheet->setCellValue("J{$row}", ExcelDate::PHPToExcel($respondedAt));
                $sheet->setCellValue("K{$row}", ExcelDate::PHPToExcel($respondedAt));
            }

            $sheet->getStyle("A{$row}:P{$row}")->getAlignment()
                ->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getStyle("I{$row}")->getAlignment()->setWrapText(true);
            $sheet->getStyle("O{$row}")->getAlignment()->setWrapText(true);
            $sheet->getRowDimension($row)->setRowHeight(-1);
            $ticketCount++;
        }

        $lastRow = max(3, 2 + $ticketCount);
        $sheet->setAutoFilter("A2:P{$lastRow}");

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
