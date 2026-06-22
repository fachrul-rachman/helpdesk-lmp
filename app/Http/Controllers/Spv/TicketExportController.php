<?php

namespace App\Http\Controllers\Spv;

use App\Http\Controllers\Controller;
use App\Http\Requests\Spv\ExportTicketRequest;
use App\Services\TicketExportService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TicketExportController extends Controller
{
    public function __invoke(
        ExportTicketRequest $request,
        TicketExportService $exportService,
    ): BinaryFileResponse {
        $export = $exportService->export($request->validated());

        return response()->download(
            $export['path'],
            $export['filename'],
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        )->deleteFileAfterSend(true);
    }
}
