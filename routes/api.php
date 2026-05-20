<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\DivisionController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\UserPasswordController;
use App\Http\Controllers\Admin\MetaWhatsappTemplateController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\DivisionController as PublicDivisionController;
use App\Http\Controllers\Pic\TicketController as PicTicketController;
use App\Http\Controllers\Pic\TakeoverRequestController as PicTakeoverRequestController;
use App\Http\Controllers\Spv\AnalyticsController;
use App\Http\Controllers\Spv\ConversationController;
use App\Http\Controllers\Spv\TicketController as SpvTicketController;
use App\Http\Controllers\Spv\PicLookupController;
use App\Http\Controllers\Spv\TakeoverRequestController as SpvTakeoverRequestController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\Webhook\WhatsAppWebhookController;
use App\Http\Controllers\Webhook\N8nWebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function (): void {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('refresh', [AuthController::class, 'refresh']);
    Route::post('logout', [AuthController::class, 'logout'])->middleware('auth.jwt');
    Route::post('change-password', [AuthController::class, 'changePassword'])->middleware('auth.jwt');
});

Route::post('auth/force-logout/{user_id}', [AuthController::class, 'forceLogout'])
    ->middleware(['auth.jwt', 'role:admin']);

Route::post('admin/users/{id}/reset-password', [UserPasswordController::class, 'resetPassword'])
    ->middleware(['auth.jwt', 'role:admin']);

Route::prefix('webhook')->group(function (): void {
    Route::get('whatsapp', [WhatsAppWebhookController::class, 'verify']);
    Route::post('whatsapp', [WhatsAppWebhookController::class, 'handle']);
    Route::post('n8n', [N8nWebhookController::class, 'handle']);
});

Route::prefix('admin')->middleware(['auth.jwt', 'role:admin'])->group(function (): void {
    Route::get('users', [UserController::class, 'index']);
    Route::post('users', [UserController::class, 'store']);
    Route::get('users/{id}', [UserController::class, 'show']);
    Route::put('users/{id}', [UserController::class, 'update']);
    Route::delete('users/{id}', [UserController::class, 'destroy']);

    Route::get('divisions', [DivisionController::class, 'index']);
    Route::post('divisions', [DivisionController::class, 'store']);
    Route::put('divisions/{id}', [DivisionController::class, 'update']);
    Route::delete('divisions/{id}', [DivisionController::class, 'destroy']);

    Route::get('settings', [SettingsController::class, 'show']);
    Route::put('settings', [SettingsController::class, 'update']);

    Route::get('audit-logs', [AuditLogController::class, 'index']);

    Route::get('meta-templates', [MetaWhatsappTemplateController::class, 'index']);
    Route::post('meta-templates/sync', [MetaWhatsappTemplateController::class, 'sync']);

    Route::delete('customers/{id}', [CustomerController::class, 'destroy']);
});

Route::middleware(['auth.jwt'])->group(function (): void {
    Route::get('divisions', [DivisionController::class, 'index']);
    Route::get('tickets', [TicketController::class, 'index']);
    Route::get('tickets/{id}', [TicketController::class, 'show']);

    Route::patch('tickets/{id}/status', [TicketController::class, 'updateStatus']);
    Route::patch('tickets/{id}/notes', [TicketController::class, 'updateNotes']);

    Route::get('tickets/{id}/messages', [TicketController::class, 'messagesIndex']);
    Route::post('tickets/{id}/messages', [TicketController::class, 'messagesStore']);

    Route::get('attachments/{id}/url', [AttachmentController::class, 'url']);

    Route::patch('customers/{id}/notes', [TicketController::class, 'updateCustomerNotes']);

    Route::prefix('spv')->middleware('role:spv')->group(function (): void {
        Route::post('tickets', [SpvTicketController::class, 'store']);
        Route::get('customers/{id}/tickets', [SpvTicketController::class, 'customerTickets']);
        Route::get('analytics', AnalyticsController::class);
        Route::get('conversations', ConversationController::class);
        Route::get('pics', PicLookupController::class);

        Route::post('tickets/{id}/takeover-request/approve', [SpvTakeoverRequestController::class, 'approve']);
        Route::post('tickets/{id}/takeover-request/reject', [SpvTakeoverRequestController::class, 'reject']);
        Route::post('tickets/{id}/takeover-request/close', [SpvTakeoverRequestController::class, 'close']);
    });

    Route::middleware('role:spv')->group(function (): void {
        Route::patch('tickets/{id}/priority', [SpvTicketController::class, 'updatePriority']);
        Route::patch('tickets/{id}/assign', [SpvTicketController::class, 'assign']);
        Route::patch('tickets/{id}/division', [SpvTicketController::class, 'changeDivision']);
    });

    Route::prefix('pic')->middleware('role:pic')->group(function (): void {
        Route::get('tickets/history', [PicTicketController::class, 'history']);

        Route::post('tickets/{id}/takeover-request', [PicTakeoverRequestController::class, 'store']);
        Route::post('tickets/{id}/takeover-request/cancel', [PicTakeoverRequestController::class, 'cancel']);
    });
});
