<?php

namespace App\Http\Controllers;

use App\Services\TicketService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AttachmentController extends Controller
{
    public function __construct(private readonly TicketService $ticketService)
    {
    }

    public function url(Request $request, string $id)
    {
        $user = $request->user();
        if (!$user) {
            throw new HttpException(401, 'Token tidak valid.');
        }

        return response()->json($this->ticketService->getAttachmentUrl($user, $id));
    }
}

