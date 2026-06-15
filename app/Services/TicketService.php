<?php

namespace App\Services;

use App\Events\AiConversationUpdated;
use App\Events\MessageSent;
use App\Events\TicketAssigned;
use App\Events\TicketCreated;
use App\Events\TicketStatusChanged;
use App\Models\AppSetting;
use App\Models\Customer;
use App\Models\Division;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Ticket;
use App\Models\TicketTakeoverRequest;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TicketService
{
    public function __construct(
        private readonly AssignService $assignService,
        private readonly SlaService $slaService,
        private readonly NotificationService $notificationService,
        private readonly TicketTakeoverRequestService $takeoverRequestService,
    ) {}

    /**
     * Buat ticket dari n8n + validasi divisi aktif + queue logic.
     *
     * @param  array<string, mixed>  $payload
     */
    public function createFromN8n(array $payload): Ticket
    {
        $customerPhone = PhoneNumber::normalize((string) ($payload['customer_phone_number'] ?? ''));
        if ($customerPhone === '') {
            throw new HttpException(422, 'Payload tidak valid: customer_phone_number wajib diisi.');
        }
        $this->assertValidPhone($customerPhone);

        $ticketData = $payload['ticket'] ?? null;
        if (! is_array($ticketData)) {
            throw new HttpException(422, 'Payload tidak valid: ticket wajib diisi.');
        }

        $subject = (string) ($ticketData['subject'] ?? '');
        $description = $ticketData['description'] ?? ($ticketData['notes'] ?? null);
        $notes = is_string($description) && trim($description) !== '' ? trim($description) : null;
        $priority = (string) ($ticketData['priority'] ?? '');
        $divisionId = $ticketData['division_id'] ?? null;
        $aiConfidence = $ticketData['ai_confidence'] ?? null;
        $isFallback = (bool) ($ticketData['is_fallback'] ?? false);

        if ($subject === '' || ! in_array($priority, ['low', 'medium', 'high'], true)) {
            throw new HttpException(422, 'Payload tidak valid: subject/priority tidak valid.');
        }

        /** @var Customer $customer */
        $customer = Customer::query()->firstOrCreate(
            ['phone_number' => $customerPhone],
            ['name' => null],
        );

        // Tentukan divisi
        $division = null;
        if ($isFallback) {
            $division = $this->getFallbackDivisionOrFail();
        } else {
            if (! is_string($divisionId) || $divisionId === '') {
                $division = $this->getFallbackDivisionOrFail();
                $isFallback = true;
            } else {
                $division = Division::query()->find($divisionId);
                if (! $division) {
                    $division = $this->getFallbackDivisionOrFail();
                    $isFallback = true;
                } elseif (! $division->is_active) {
                    $division = $this->getFallbackDivisionOrFail();
                    $isFallback = true;
                }
            }
        }

        $hasActiveTicket = Ticket::query()
            ->where('customer_id', $customer->id)
            ->whereIn('status', ['new', 'open', 'pending', 'on_progress'])
            ->exists();

        $status = $hasActiveTicket ? 'queue' : 'new';

        // Fallback: handler SPV (assigned_to = spv)
        $assignedTo = null;
        if ($isFallback) {
            $spv = User::query()->where('role', 'spv')->first();
            $assignedTo = $spv?->id;
        }

        $now = CarbonImmutable::now();
        $ticket = Ticket::create([
            'customer_id' => $customer->id,
            'division_id' => $division->id,
            'assigned_to' => $assignedTo,
            'created_by' => 'ai',
            'priority' => $priority,
            'status' => $status,
            'subject' => $subject,
            'notes' => $notes,
            'ai_confidence' => $aiConfidence,
            'sla_fr_started_at' => $status === 'new' ? $now : null,
            'sla_fr_deadline_at' => $status === 'new'
                ? $this->slaService->calculateDeadline($now, $this->getSlaFrDurationMinutes(), (string) $division->id)
                : null,
            'sla_fr_status' => 'running',
            'sla_resolution_status' => 'waiting',
            'queue_priority' => $status === 'queue' ? $this->priorityToQueuePriority($priority) : null,
        ]);

        if ($status === 'new' && ! $division->is_fallback) {
            $this->assignService->autoAssign($ticket);
        }

        // Broadcast ticket created (minimal payload; auto-assign/SLA detail di Modul 6+)
        event(new TicketCreated([
            'id' => $ticket->id,
            'subject' => $ticket->subject,
            'status' => $ticket->status,
            'priority' => $ticket->priority,
            'customer' => [
                'name' => $customer->name,
                'phone_number' => $customer->phone_number,
            ],
            'division' => ['name' => $division->name],
            'assigned_to' => $ticket->assigned_to ? ['id' => $ticket->assigned_to] : null,
            'created_at' => optional($ticket->created_at)->toISOString(),
            'pic_id' => (string) ($ticket->assigned_to ?? ''),
        ]));

        // Jika ticket fallback dan langsung ditangani SPV, kirim notifikasi "ticket baru" ke SPV.
        // (Template yang sama dipakai untuk PIC dan SPV.)
        if ($status === 'new' && $ticket->assigned_to) {
            /** @var User|null $assignee */
            $assignee = User::query()->withTrashed()->find((string) $ticket->assigned_to);
            if ($assignee && $assignee->role === 'spv' && $assignee->phone_number) {
                $ticket->setRelation('customer', $customer);
                $ticket->setRelation('division', $division);
                $this->notificationService->sendTicketAssignedToAgent($assignee, $ticket);
            }
        }

        return $ticket;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function listTickets(User $user, array $filters): array
    {
        $perPage = (int) ($filters['per_page'] ?? 20);
        $page = (int) ($filters['page'] ?? 1);

        $query = Ticket::query()->with([
            'customer' => fn ($q) => $q->withTrashed(),
            'division',
            'assignee' => fn ($q) => $q->withTrashed(),
            'takeoverRequest',
        ]);

        $this->applyTicketVisibilityScope($query, $user);
        $this->applyTicketFilters($query, $user, $filters);

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->orderByDesc('created_at')->paginate(perPage: $perPage, page: $page);

        $items = [];
        foreach ($paginator->items() as $ticket) {
            if ($ticket instanceof Ticket) {
                $items[] = $this->formatTicketListItem($ticket);
            }
        }

        return [
            'data' => $items,
            'meta' => [
                'total' => $paginator->total(),
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getTicketDetail(User $user, string $ticketId): array
    {
        $ticket = $this->getTicketForUserOrFail($user, $ticketId);

        return $this->formatTicketDetail($ticket);
    }

    public function changeStatus(User $actor, string $ticketId, string $toStatus): Ticket
    {
        $ticket = $this->getTicketForUserOrFail($actor, $ticketId);
        $this->assertCanChangeStatus($actor, $ticket);

        $fromStatus = (string) $ticket->status;
        $allowed = $this->allowedHumanTransitions($fromStatus);
        if (! in_array($toStatus, $allowed, true)) {
            throw new HttpException(422, 'Transisi status tidak valid.');
        }

        $now = CarbonImmutable::now();

        return DB::transaction(function () use ($actor, $ticket, $fromStatus, $toStatus, $now): Ticket {
            $ticket->status = $toStatus;

            if (in_array($toStatus, ['pending', 'on_progress'], true)) {
                $this->slaService->pauseSla($ticket->id, $toStatus);
                $ticket->refresh();
                $ticket->status = $toStatus;
            }

            if (in_array($fromStatus, ['pending', 'on_progress'], true) && $toStatus === 'open') {
                $this->slaService->resumeSla($ticket->id);
                $ticket->refresh();
                $ticket->status = $toStatus;
            }

            if ($toStatus === 'solved') {
                $ticket->solved_at = $now;
                $ticket->sla_resolution_status = 'done';
            }

            $ticket->save();

            AuditLogger::log('ticket.status_changed', $ticket, [
                'from' => $fromStatus,
                'to' => $toStatus,
            ], $actor);

            event(new TicketStatusChanged([
                'ticket_id' => $ticket->id,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'changed_by' => [
                    'id' => $actor->id,
                    'name' => $actor->name,
                    'role' => $actor->role,
                ],
                'changed_at' => $now->toISOString(),
                'pic_id' => (string) ($ticket->assigned_to ?? ''),
            ]));

            if (in_array($toStatus, ['solved', 'closed'], true)) {
                $this->activateNextQueueIfAny($ticket->customer_id);
            }

            if ($toStatus === 'solved') {
                $ticket->refresh();
                if (! $ticket->satisfaction_review_sent_at) {
                    $this->notificationService->sendSatisfactionReview($ticket);
                    $ticket->satisfaction_review_sent_at = $now;
                    $ticket->save();
                }
            }

            return $ticket->fresh([
                'customer' => fn ($q) => $q->withTrashed(),
                'division',
                'assignee' => fn ($q) => $q->withTrashed(),
            ]) ?? $ticket;
        });
    }

    public function changePriority(User $actor, string $ticketId, string $priority): Ticket
    {
        if ($actor->role !== 'spv') {
            throw new HttpException(403, 'Anda tidak memiliki akses ke halaman ini.');
        }

        /** @var Ticket|null $ticket */
        $ticket = Ticket::query()
            ->with(['customer' => fn ($q) => $q->withTrashed(), 'division', 'assignee' => fn ($q) => $q->withTrashed()])
            ->find($ticketId);
        if (! $ticket) {
            throw new HttpException(404, 'Ticket tidak ditemukan.');
        }

        $ticket->priority = $priority;
        if ($ticket->status === 'queue') {
            $ticket->queue_priority = $this->priorityToQueuePriority($priority);
        }
        $ticket->save();

        AuditLogger::log('ticket.priority_changed', $ticket, ['priority' => $priority], $actor);

        return $ticket;
    }

    public function assignToPic(User $actor, string $ticketId, string $picUserId): Ticket
    {
        if ($actor->role !== 'spv') {
            throw new HttpException(403, 'Anda tidak memiliki akses ke halaman ini.');
        }

        /** @var Ticket|null $ticket */
        $ticket = Ticket::query()
            ->with(['customer' => fn ($q) => $q->withTrashed(), 'division', 'assignee' => fn ($q) => $q->withTrashed()])
            ->find($ticketId);
        if (! $ticket) {
            throw new HttpException(404, 'Ticket tidak ditemukan.');
        }

        /** @var User|null $pic */
        $pic = User::query()->where('role', 'pic')->where('is_active', true)->find($picUserId);
        if (! $pic || ! $pic->division_id) {
            throw new HttpException(422, 'User PIC tidak valid.');
        }

        if ((string) $pic->division_id !== (string) $ticket->division_id) {
            throw new HttpException(422, 'PIC harus berasal dari divisi yang sama dengan ticket.');
        }

        $prevAssignedTo = (string) ($ticket->assigned_to ?? '');
        $ticket->assigned_to = $pic->id;
        $ticket->save();

        if ($prevAssignedTo !== (string) $pic->id) {
            $this->takeoverRequestService->autoCloseOnReassign($ticket, $actor);
        }

        AuditLogger::log('ticket.assigned', $ticket, ['assigned_to' => $pic->id], $actor);

        $now = CarbonImmutable::now();
        event(new TicketAssigned([
            'ticket_id' => $ticket->id,
            'assigned_to' => ['id' => $pic->id, 'name' => $pic->name],
            'assigned_by' => ['id' => $actor->id, 'name' => $actor->name],
            'assigned_at' => $now->toISOString(),
            'pic_id' => $pic->id,
        ]));

        $ticket->loadMissing(['customer' => fn ($q) => $q->withTrashed(), 'division']);
        $this->notificationService->sendTicketAssignedToAgent($pic, $ticket);

        return $ticket->fresh(['customer' => fn ($q) => $q->withTrashed(), 'division', 'assignee' => fn ($q) => $q->withTrashed()]) ?? $ticket;
    }

    public function changeDivision(User $actor, string $ticketId, string $divisionId, ?string $assignedTo): Ticket
    {
        if ($actor->role !== 'spv') {
            throw new HttpException(403, 'Anda tidak memiliki akses ke halaman ini.');
        }

        /** @var Ticket|null $ticket */
        $ticket = Ticket::query()
            ->with(['customer' => fn ($q) => $q->withTrashed(), 'division', 'assignee' => fn ($q) => $q->withTrashed()])
            ->find($ticketId);
        if (! $ticket) {
            throw new HttpException(404, 'Ticket tidak ditemukan.');
        }

        /** @var Division|null $division */
        $division = Division::query()->find($divisionId);
        if (! $division || (! $division->is_active && ! $division->is_fallback)) {
            throw new HttpException(422, 'Divisi tidak aktif. Tidak ada PIC yang tersedia.');
        }

        return DB::transaction(function () use ($actor, $ticket, $division, $assignedTo): Ticket {
            $ticket->division_id = $division->id;
            $ticket->save();

            if (in_array((string) $ticket->status, ['open', 'pending', 'on_progress'], true)) {
                $this->slaService->resetResolutionSla($ticket->id);
                $ticket->refresh();
            }

            $newAssignee = null;
            $shouldBroadcastAssigned = false;
            if ($division->is_fallback) {
                $spv = User::query()->where('role', 'spv')->first();
                $newAssignee = $spv?->id;
                $shouldBroadcastAssigned = true;
            } elseif (is_string($assignedTo) && $assignedTo !== '') {
                $pic = User::query()->where('role', 'pic')->where('is_active', true)->find($assignedTo);
                if (! $pic || (string) $pic->division_id !== (string) $division->id) {
                    throw new HttpException(422, 'PIC tidak valid untuk divisi tujuan.');
                }
                $newAssignee = $pic->id;
                $shouldBroadcastAssigned = true;
            } else {
                $assigned = $this->assignService->autoAssign($ticket);
                $newAssignee = $assigned?->id;
            }

            $prevAssignedTo = (string) ($ticket->assigned_to ?? '');
            $ticket->assigned_to = $newAssignee;
            $ticket->save();

            if ($prevAssignedTo !== (string) ($newAssignee ?? '')) {
                $this->takeoverRequestService->autoCloseOnReassign($ticket, $actor);
            }

            AuditLogger::log('ticket.division_changed', $ticket, [
                'division_id' => $division->id,
                'assigned_to' => $newAssignee,
            ], $actor);

            if ($newAssignee && $shouldBroadcastAssigned) {
                $assignee = User::query()->withTrashed()->find($newAssignee);
                event(new TicketAssigned([
                    'ticket_id' => $ticket->id,
                    'assigned_to' => $assignee ? ['id' => $assignee->id, 'name' => $assignee->name] : ['id' => $newAssignee],
                    'assigned_by' => ['id' => $actor->id, 'name' => $actor->name],
                    'assigned_at' => CarbonImmutable::now()->toISOString(),
                    'pic_id' => $newAssignee,
                ]));

                if ($assignee && $assignee->phone_number) {
                    $ticket->loadMissing(['customer' => fn ($q) => $q->withTrashed(), 'division']);
                    $this->notificationService->sendTicketAssignedToAgent($assignee, $ticket);
                }
            }

            return $ticket->fresh(['customer' => fn ($q) => $q->withTrashed(), 'division', 'assignee' => fn ($q) => $q->withTrashed()]) ?? $ticket;
        });
    }

    public function updateNotes(User $actor, string $ticketId, ?string $notes): Ticket
    {
        $ticket = $this->getTicketForUserOrFail($actor, $ticketId);

        if ($actor->role === 'pic' && (string) $ticket->assigned_to !== (string) $actor->id) {
            throw new HttpException(403, 'Anda tidak memiliki akses ke halaman ini.');
        }

        $ticket->notes = $notes;
        $ticket->save();

        AuditLogger::log('ticket.notes_updated', $ticket, [], $actor);

        return $ticket->fresh(['customer' => fn ($q) => $q->withTrashed(), 'division', 'assignee' => fn ($q) => $q->withTrashed()]) ?? $ticket;
    }

    public function updateCustomerNotes(User $actor, string $customerId, ?string $notes): Customer
    {
        /** @var Customer|null $customer */
        $customer = Customer::query()->withTrashed()->find($customerId);
        if (! $customer) {
            throw new HttpException(404, 'Customer tidak ditemukan.');
        }

        $customer->notes = $notes;
        $customer->save();

        AuditLogger::log('customer.notes_updated', $customer, [], $actor);

        return $customer;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listMessages(User $user, string $ticketId): array
    {
        $ticket = $this->getTicketForUserOrFail($user, $ticketId);

        /** @var Collection<int, Message> $ticketMessages */
        $ticketMessages = Message::query()
            ->where('ticket_id', $ticket->id)
            ->with(['attachments', 'sender' => fn ($q) => $q->withTrashed(), 'customer' => fn ($q) => $q->withTrashed()])
            ->orderBy('created_at')
            ->get();

        // Sertakan riwayat percakapan customer <-> AI (ticket_id NULL) agar PIC/SPV bisa melihat konteks.
        // Default: ambil 7 hari ke belakang dari waktu ticket dibuat supaya tidak menarik seluruh history lama.
        $historyDays = 7;
        $cutoffStart = $ticket->created_at
            ? CarbonImmutable::parse($ticket->created_at)->subDays($historyDays)
            : CarbonImmutable::now()->subDays($historyDays);

        $cutoffEnd = null;
        if ($user->role === 'pic') {
            $cutoffEnd = $ticket->closed_at ?: $ticket->solved_at;
        }

        /** @var Collection<int, Message> $aiConversationMessages */
        $aiConversationMessages = Message::query()
            ->whereNull('ticket_id')
            ->where('customer_id', $ticket->customer_id)
            ->where('created_at', '>=', $cutoffStart)
            ->when($cutoffEnd, fn ($q) => $q->where('created_at', '<=', $cutoffEnd))
            ->with(['attachments', 'sender' => fn ($q) => $q->withTrashed(), 'customer' => fn ($q) => $q->withTrashed()])
            ->orderBy('created_at')
            ->get();

        $messages = $ticketMessages
            ->merge($aiConversationMessages)
            ->sort(function (Message $a, Message $b): int {
                $at = optional($a->created_at)->getTimestamp() ?? 0;
                $bt = optional($b->created_at)->getTimestamp() ?? 0;
                if ($at === $bt) {
                    return strcmp((string) $a->id, (string) $b->id);
                }

                return $at <=> $bt;
            })
            ->values();

        return $messages->map(fn (Message $m) => $this->formatMessage($m))->all();
    }

    public function createHumanMessage(User $actor, string $ticketId, string $content): Message
    {
        return $this->createHumanMessageWithAttachments($actor, $ticketId, $content, []);
    }

    /**
     * @param  array<int, UploadedFile>  $attachments
     */
    public function createHumanMessageWithAttachments(User $actor, string $ticketId, string $content, array $attachments): Message
    {
        $ticket = $this->getTicketForUserOrFail($actor, $ticketId);
        $this->assertCanReply($actor, $ticket);

        if ($ticket->status === 'queue') {
            throw new HttpException(422, 'Ticket masih dalam antrian (queue).');
        }

        if (in_array((string) $ticket->status, ['solved', 'closed'], true)) {
            throw new HttpException(422, 'Ticket sudah ditutup dan tidak bisa dibalas.');
        }

        $now = CarbonImmutable::now();

        $mediaService = app(MediaService::class);

        return DB::transaction(function () use ($actor, $ticket, $content, $attachments, $now, $mediaService): Message {
            $fromStatus = (string) $ticket->status;
            if ($fromStatus === 'new') {
                $ticket->status = 'open';
                $ticket->sla_fr_completed_at = $now;
                $ticket->sla_fr_status = 'done';
                $ticket->sla_resolution_started_at = $now;
                $durationMinutes = $this->slaService->divisionResolutionMinutes((string) $ticket->division_id);
                $ticket->sla_resolution_deadline_at = $this->slaService->calculateDeadline($now, $durationMinutes, (string) $ticket->division_id);
                $ticket->sla_resolution_status = 'running';
                $ticket->save();

                event(new TicketStatusChanged([
                    'ticket_id' => $ticket->id,
                    'from_status' => 'new',
                    'to_status' => 'open',
                    'changed_by' => [
                        'id' => $actor->id,
                        'name' => $actor->name,
                        'role' => $actor->role,
                    ],
                    'changed_at' => $now->toISOString(),
                    'pic_id' => (string) ($ticket->assigned_to ?? ''),
                ]));
            }

            $message = Message::create([
                'ticket_id' => $ticket->id,
                'customer_id' => $ticket->customer_id,
                'sender_type' => $actor->role,
                'sender_id' => $actor->id,
                'content' => $content,
                'wa_message_id' => null,
                'created_at' => $now,
            ]);

            if ($attachments !== []) {
                foreach ($attachments as $file) {
                    if (! $file instanceof UploadedFile) {
                        continue;
                    }

                    $mime = (string) ($file->getMimeType() ?? '');
                    $r2Key = $mediaService->uploadUploadedFileToR2($file, 'media');

                    MessageAttachment::create([
                        'message_id' => $message->id,
                        'type' => $mediaService->classifyTypeFromMime($mime),
                        'file_name' => (string) ($file->getClientOriginalName() ?: 'file'),
                        'r2_key' => $r2Key,
                        'mime_type' => $mime !== '' ? $mime : 'application/octet-stream',
                        'size_bytes' => (int) $file->getSize(),
                    ]);
                }
            }

            $message->loadMissing('attachments');

            AuditLogger::log(
                action: 'message.sent',
                subject: $message,
                payload: [
                    'data' => [
                        'ticket_id' => $ticket->id,
                        'sender_type' => $actor->role,
                        'content' => $content,
                        'attachments_count' => $message->attachments->count(),
                    ],
                ],
                user: $actor,
            );

            event(new MessageSent($this->formatMessage($message)));

            $ticket->loadMissing(['customer' => fn ($q) => $q->withTrashed()]);
            $toPhone = (string) ($ticket->customer?->phone_number ?? '');

            if ($toPhone !== '') {
                if ($attachments === []) {
                    $this->notificationService->sendText($toPhone, $content);
                } else {
                    $sentCaption = false;
                    foreach ($message->attachments as $att) {
                        $caption = '';
                        if (! $sentCaption && $content !== '') {
                            $caption = $content;
                            $sentCaption = true;
                        }

                        $url = $mediaService->getPublicUrl((string) $att->r2_key);
                        $this->notificationService->sendMedia(
                            $toPhone,
                            $url,
                            (string) $att->type,
                            $caption,
                            (string) $att->file_name,
                        );
                    }
                }
            }

            return $message->fresh(['attachments', 'sender' => fn ($q) => $q->withTrashed(), 'customer' => fn ($q) => $q->withTrashed()]) ?? $message;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createManualTicket(User $actor, array $payload): Ticket
    {
        if ($actor->role !== 'spv') {
            throw new HttpException(403, 'Anda tidak memiliki akses ke halaman ini.');
        }

        $customerPhone = PhoneNumber::normalize((string) $payload['customer_phone_number']);
        $this->assertValidPhone($customerPhone);

        /** @var Division|null $division */
        $division = Division::query()->find((string) $payload['division_id']);
        if (! $division || (! $division->is_active && ! $division->is_fallback)) {
            throw new HttpException(422, 'Divisi tidak aktif. Tidak ada PIC yang tersedia.');
        }

        /** @var Customer $customer */
        $customer = Customer::query()->firstOrCreate(['phone_number' => $customerPhone], ['name' => null]);

        $hasActiveTicket = Ticket::query()
            ->where('customer_id', $customer->id)
            ->whereIn('status', ['new', 'open', 'pending', 'on_progress'])
            ->exists();

        $status = $hasActiveTicket ? 'queue' : 'new';

        $ticket = DB::transaction(function () use ($actor, $payload, $division, $customer, $status): Ticket {
            $now = CarbonImmutable::now();

            $ticket = Ticket::create([
                'customer_id' => $customer->id,
                'division_id' => $division->id,
                'assigned_to' => $division->is_fallback ? (User::query()->where('role', 'spv')->first()?->id) : null,
                'created_by' => 'spv',
                'priority' => (string) $payload['priority'],
                'status' => $status,
                'subject' => (string) $payload['subject'],
                'notes' => $payload['notes'] ?? null,
                'ai_confidence' => null,
                'sla_fr_started_at' => $status === 'new' ? $now : null,
                'sla_fr_deadline_at' => $status === 'new'
                    ? $this->slaService->calculateDeadline($now, $this->getSlaFrDurationMinutes(), (string) $division->id)
                    : null,
                'sla_fr_status' => 'running',
                'sla_resolution_status' => 'waiting',
                'queue_priority' => $status === 'queue' ? $this->priorityToQueuePriority((string) $payload['priority']) : null,
            ]);

            if ($status === 'new' && ! $division->is_fallback) {
                $assigned = $this->assignService->autoAssign($ticket);
                if ($assigned) {
                    // AssignService sudah update ticket + broadcast TicketAssigned.
                }
            }

            AuditLogger::log('ticket.created', $ticket, [], $actor);

            event(new TicketCreated([
                'id' => $ticket->id,
                'subject' => $ticket->subject,
                'status' => $ticket->status,
                'priority' => $ticket->priority,
                'customer' => ['name' => $customer->name, 'phone_number' => $customer->phone_number],
                'division' => ['name' => $division->name],
                'assigned_to' => $ticket->assigned_to ? ['id' => $ticket->assigned_to] : null,
                'created_at' => optional($ticket->created_at)->toISOString(),
                'pic_id' => (string) ($ticket->assigned_to ?? ''),
            ]));

            return $ticket->fresh(['customer' => fn ($q) => $q->withTrashed(), 'division', 'assignee' => fn ($q) => $q->withTrashed()]) ?? $ticket;
        });

        // Ticket manual ke fallback: assign ke SPV dan SPV boleh membalas, jadi kirim notifikasi juga.
        if ($ticket->status === 'new' && $ticket->assigned_to) {
            /** @var User|null $assignee */
            $assignee = User::query()->withTrashed()->find((string) $ticket->assigned_to);
            if ($assignee && $assignee->role === 'spv' && $assignee->phone_number) {
                $ticket->loadMissing(['customer' => fn ($q) => $q->withTrashed(), 'division']);
                $this->notificationService->sendTicketAssignedToAgent($assignee, $ticket);
            }
        }

        return $ticket;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function listPicHistory(User $pic, array $filters): array
    {
        if ($pic->role !== 'pic') {
            throw new HttpException(403, 'Anda tidak memiliki akses ke halaman ini.');
        }

        $perPage = (int) ($filters['per_page'] ?? 20);
        $page = (int) ($filters['page'] ?? 1);

        $query = Ticket::query()
            ->with(['customer' => fn ($q) => $q->withTrashed(), 'division', 'assignee' => fn ($q) => $q->withTrashed()])
            ->where('assigned_to', $pic->id)
            ->whereIn('status', ['solved', 'closed']);

        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', (string) $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', (string) $filters['date_to']);
        }

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->orderByDesc('created_at')->paginate(perPage: $perPage, page: $page);

        $items = [];
        foreach ($paginator->items() as $ticket) {
            if ($ticket instanceof Ticket) {
                $items[] = $this->formatTicketListItem($ticket);
            }
        }

        return [
            'data' => $items,
            'meta' => [
                'total' => $paginator->total(),
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listCustomerTickets(User $actor, string $customerId): array
    {
        if ($actor->role !== 'spv') {
            throw new HttpException(403, 'Anda tidak memiliki akses ke halaman ini.');
        }

        /** @var Customer|null $customer */
        $customer = Customer::query()->withTrashed()->find($customerId);
        if (! $customer) {
            throw new HttpException(404, 'Customer tidak ditemukan.');
        }

        /** @var Collection<int, Ticket> $tickets */
        $tickets = Ticket::query()
            ->with(['customer' => fn ($q) => $q->withTrashed(), 'division', 'assignee' => fn ($q) => $q->withTrashed()])
            ->where('customer_id', $customer->id)
            ->orderByDesc('created_at')
            ->get();

        return $tickets->map(fn (Ticket $t) => $this->formatTicketListItem($t))->all();
    }

    public function formatTicketDetail(Ticket $ticket): array
    {
        $ticket->loadMissing([
            'customer' => fn ($q) => $q->withTrashed(),
            'division',
            'assignee' => fn ($q) => $q->withTrashed(),
            'takeoverRequest' => fn ($q) => $q->with(['requester' => fn ($qq) => $qq->withTrashed()]),
        ]);

        $takeover = null;
        if ($ticket->takeoverRequest && in_array((string) $ticket->takeoverRequest->status, ['pending', 'approved'], true)) {
            $req = $ticket->takeoverRequest;
            $takeover = [
                'id' => $req->id,
                'status' => $req->status,
                'reason' => $req->reason,
                'requested_by' => $req->requester ? ['id' => $req->requester->id, 'name' => $req->requester->name] : ['id' => $req->requested_by],
                'approved_at' => optional($req->approved_at)->toISOString(),
                'created_at' => optional($req->created_at)->toISOString(),
            ];
        }

        return [
            'id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'subject' => $ticket->subject,
            'status' => $ticket->status,
            'priority' => $ticket->priority,
            'notes' => $ticket->notes,
            'takeover_request' => $takeover,
            'customer' => $this->formatCustomer($ticket->customer),
            'division' => $ticket->division ? ['id' => $ticket->division->id, 'name' => $ticket->division->name] : null,
            'assigned_to' => $ticket->assignee ? ['id' => $ticket->assignee->id, 'name' => $ticket->assignee->name, 'role' => $ticket->assignee->role] : null,
            'created_by' => $ticket->created_by,
            'ai_confidence' => $ticket->ai_confidence,
            'sla' => [
                'fr_status' => $ticket->sla_fr_status,
                'fr_started_at' => optional($ticket->sla_fr_started_at)->toISOString(),
                'fr_deadline_at' => optional($ticket->sla_fr_deadline_at)->toISOString(),
                'fr_completed_at' => optional($ticket->sla_fr_completed_at)->toISOString(),
                'resolution_status' => $ticket->sla_resolution_status,
                'resolution_started_at' => optional($ticket->sla_resolution_started_at)->toISOString(),
                'resolution_deadline_at' => optional($ticket->sla_resolution_deadline_at)->toISOString(),
            ],
            'created_at' => optional($ticket->created_at)->toISOString(),
        ];
    }

    public function formatTicketListItem(Ticket $ticket): array
    {
        $ticket->loadMissing([
            'customer' => fn ($q) => $q->withTrashed(),
            'division',
            'assignee' => fn ($q) => $q->withTrashed(),
            'takeoverRequest',
        ]);

        $hasTakeover = $ticket->takeoverRequest
            && in_array((string) $ticket->takeoverRequest->status, ['pending', 'approved'], true);

        return [
            'id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'subject' => $ticket->subject,
            'status' => $ticket->status,
            'priority' => $ticket->priority,
            'has_takeover_request' => $hasTakeover,
            'takeover_request_status' => $hasTakeover ? (string) $ticket->takeoverRequest->status : null,
            'customer' => $this->formatCustomer($ticket->customer),
            'division' => $ticket->division ? ['id' => $ticket->division->id, 'name' => $ticket->division->name] : null,
            'assigned_to' => $ticket->assignee ? ['id' => $ticket->assignee->id, 'name' => $ticket->assignee->name] : null,
            'sla_fr_status' => $ticket->sla_fr_status,
            'sla_resolution_status' => $ticket->sla_resolution_status,
            'sla_resolution_deadline_at' => optional($ticket->sla_resolution_deadline_at)->toISOString(),
            'created_at' => optional($ticket->created_at)->toISOString(),
        ];
    }

    public function formatCustomer(?Customer $customer): array
    {
        if (! $customer) {
            return [
                'id' => null,
                'name' => 'Customer Dihapus',
                'phone_number' => null,
                'deleted' => true,
            ];
        }

        if (method_exists($customer, 'trashed') && $customer->trashed()) {
            return [
                'id' => $customer->id,
                'name' => 'Customer Dihapus',
                'phone_number' => null,
                'deleted' => true,
            ];
        }

        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'phone_number' => $customer->phone_number,
            'notes' => $customer->notes,
            'deleted' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function formatMessage(Message $message): array
    {
        $message->loadMissing([
            'attachments',
            'sender' => fn ($q) => $q->withTrashed(),
            'customer' => fn ($q) => $q->withTrashed(),
        ]);

        $mediaService = app(MediaService::class);

        $senderName = null;
        if ($message->sender_type === 'customer') {
            $senderName = $message->customer?->name;
        } elseif ($message->sender_type === 'ai') {
            $senderName = 'AI';
        } elseif ($message->sender_type === 'system') {
            $senderName = 'Sistem';
        } elseif ($message->sender) {
            $senderName = $message->sender->name;
        }

        return [
            'id' => $message->id,
            'ticket_id' => $message->ticket_id,
            'sender_type' => $message->sender_type,
            'sender' => ['name' => $senderName],
            'content' => $message->content,
            'attachments' => $message->attachments->map(fn ($a) => [
                'id' => $a->id,
                'type' => $a->type,
                'file_name' => $a->file_name,
                'url' => $a->r2_key ? $mediaService->getPublicUrl((string) $a->r2_key) : null,
                'mime_type' => $a->mime_type,
                'size_bytes' => $a->size_bytes,
            ])->all(),
            'created_at' => optional($message->created_at)->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getAttachmentUrl(User $user, string $attachmentId): array
    {
        /** @var MessageAttachment|null $attachment */
        $attachment = MessageAttachment::query()
            ->with(['message.ticket'])
            ->find($attachmentId);

        if (! $attachment || ! $attachment->message || ! $attachment->message->ticket_id) {
            // Attachment dari percakapan AI (ticket_id NULL) tetap boleh diakses jika user punya akses
            // ke salah satu ticket milik customer tersebut.
            if (! $attachment || ! $attachment->message) {
                throw new HttpException(404, 'Attachment tidak ditemukan.');
            }

            $messageCustomerId = (string) ($attachment->message->customer_id ?? '');
            if ($messageCustomerId === '') {
                throw new HttpException(404, 'Attachment tidak ditemukan.');
            }

            $ticketQuery = Ticket::query()->where('customer_id', $messageCustomerId);
            $this->applyTicketVisibilityScope($ticketQuery, $user);

            /** @var Ticket|null $ticket */
            $ticket = $ticketQuery->orderByDesc('created_at')->first();
            if (! $ticket) {
                throw new HttpException(403, 'Anda tidak memiliki akses ke halaman ini.');
            }
        }

        if ($attachment->message->ticket_id) {
            $this->getTicketForUserOrFail($user, (string) $attachment->message->ticket_id);
        }

        $url = app(MediaService::class)->getPublicUrl((string) $attachment->r2_key);

        return [
            'url' => $url,
            'type' => $attachment->type,
            'file_name' => $attachment->file_name,
            'mime_type' => $attachment->mime_type,
            'size_bytes' => $attachment->size_bytes,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function saveAiReplyWithoutTicket(array $payload): void
    {
        $customerPhone = PhoneNumber::normalize((string) ($payload['customer_phone_number'] ?? ''));
        if ($customerPhone === '') {
            throw new HttpException(422, 'Payload tidak valid: customer_phone_number wajib diisi.');
        }

        $messageText = '';
        $aiReply = $payload['ai_reply'] ?? null;
        if (is_array($aiReply)) {
            $messageText = (string) ($aiReply['message'] ?? '');
        }
        if ($messageText === '' && isset($payload['message']) && is_array($payload['message'])) {
            $messageText = (string) ($payload['message']['content'] ?? '');
        }
        if ($messageText === '' && is_string($payload['message'] ?? null)) {
            $messageText = (string) $payload['message'];
        }
        if (trim($messageText) === '') {
            throw new HttpException(422, 'Payload tidak valid: ai_reply.message wajib diisi.');
        }

        /** @var Customer|null $customer */
        $customer = Customer::query()->where('phone_number', $customerPhone)->first();
        if (! $customer) {
            throw new HttpException(422, 'Customer tidak ditemukan dan phone_number tidak valid.');
        }

        $message = Message::create([
            'ticket_id' => null,
            'customer_id' => $customer->id,
            'sender_type' => 'ai',
            'sender_id' => null,
            'content' => trim($messageText),
            'wa_message_id' => null,
            'created_at' => now(),
        ]);

        // Jika customer sedang punya ticket aktif, broadcast juga ke channel ticket agar PIC/SPV
        // yang sedang menangani ticket bisa melihat percakapan customer <-> AI secara realtime.
        /** @var Ticket|null $activeTicket */
        $activeTicket = Ticket::query()
            ->where('customer_id', $customer->id)
            ->whereIn('status', ['new', 'open', 'pending', 'on_progress'])
            ->orderByDesc('created_at')
            ->first();

        if ($activeTicket) {
            $payload = $this->formatMessage($message);
            $payload['ticket_id'] = $activeTicket->id;
            event(new MessageSent($payload));
        }

        event(new AiConversationUpdated([
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone_number' => $customer->phone_number,
            ],
            'message' => [
                'sender_type' => 'ai',
                'content' => $message->content,
                'created_at' => optional($message->created_at)->toISOString(),
            ],
        ]));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function sendAiMediaReplyWithoutTicket(array $payload): Message
    {
        $customerPhone = PhoneNumber::normalize((string) ($payload['customer_phone_number'] ?? ''));
        if ($customerPhone === '') {
            throw new HttpException(422, 'Payload tidak valid: customer_phone_number wajib diisi.');
        }

        $aiReply = $payload['ai_reply'] ?? null;
        if (! is_array($aiReply)) {
            throw new HttpException(422, 'Payload tidak valid: ai_reply wajib diisi.');
        }

        $key = trim((string) ($aiReply['key'] ?? ''));
        $this->assertValidMediaKey($key);

        /** @var Customer|null $customer */
        $customer = Customer::query()->where('phone_number', $customerPhone)->first();
        if (! $customer) {
            throw new HttpException(422, 'Customer tidak ditemukan dan phone_number tidak valid.');
        }

        $mediaService = app(MediaService::class);

        $mediaType = strtolower(trim((string) ($aiReply['media_type'] ?? '')));
        if ($mediaType === '') {
            $mediaType = $mediaService->classifyTypeFromKey($key);
        }
        if (! in_array($mediaType, ['image', 'video', 'document'], true)) {
            throw new HttpException(422, 'media_type tidak valid.');
        }

        $mimeType = $mediaService->mimeTypeFromKey($key);
        if ($mimeType !== 'application/octet-stream' && ! in_array($mimeType, $mediaService->allowedMimeTypes(), true)) {
            throw new HttpException(422, 'Tipe file tidak didukung.');
        }

        $caption = trim((string) ($aiReply['caption'] ?? $aiReply['message'] ?? ''));
        if (mb_strlen($caption) > 1024) {
            throw new HttpException(422, 'Caption maksimal 1024 karakter.');
        }

        $fileName = trim((string) ($aiReply['filename'] ?? ''));
        if ($fileName === '') {
            $fileName = basename(str_replace('\\', '/', $key));
        }
        if ($fileName === '' || $fileName === '.' || $fileName === '..') {
            $fileName = 'file';
        }

        $mediaUrl = $mediaService->getPublicUrl($key);
        $this->notificationService->sendMedia(
            $customer->phone_number,
            $mediaUrl,
            $mediaType,
            $caption,
            $fileName,
        );

        $message = DB::transaction(function () use ($customer, $caption, $mediaType, $fileName, $key, $mimeType): Message {
            $message = Message::create([
                'ticket_id' => null,
                'customer_id' => $customer->id,
                'sender_type' => 'ai',
                'sender_id' => null,
                'content' => $caption !== '' ? $caption : null,
                'wa_message_id' => null,
                'created_at' => now(),
            ]);

            MessageAttachment::create([
                'message_id' => $message->id,
                'type' => $mediaType,
                'file_name' => $fileName,
                'r2_key' => $key,
                'mime_type' => $mimeType,
                'size_bytes' => 0,
            ]);

            return $message->fresh(['attachments', 'sender' => fn ($q) => $q->withTrashed(), 'customer' => fn ($q) => $q->withTrashed()]) ?? $message;
        });

        /** @var Ticket|null $activeTicket */
        $activeTicket = Ticket::query()
            ->where('customer_id', $customer->id)
            ->whereIn('status', ['new', 'open', 'pending', 'on_progress'])
            ->orderByDesc('created_at')
            ->first();

        if ($activeTicket) {
            $messagePayload = $this->formatMessage($message);
            $messagePayload['ticket_id'] = $activeTicket->id;
            event(new MessageSent($messagePayload));
        }

        event(new AiConversationUpdated([
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone_number' => $customer->phone_number,
            ],
            'message' => [
                'sender_type' => 'ai',
                'content' => $message->content,
                'created_at' => optional($message->created_at)->toISOString(),
            ],
        ]));

        return $message;
    }

    public function reopenFromOnProgress(string $ticketId): Ticket
    {
        /** @var Ticket|null $ticket */
        $ticket = Ticket::query()->find($ticketId);
        if (! $ticket) {
            throw new HttpException(404, 'Ticket tidak ditemukan.');
        }

        if ($ticket->status !== 'on_progress') {
            throw new HttpException(422, 'Payload tidak valid: status ticket bukan on_progress.');
        }

        $ticket->update(['status' => 'open']);

        if ($ticket->assigned_to) {
            $pic = User::query()->where('role', 'pic')->withTrashed()->find((string) $ticket->assigned_to);
            if ($pic && $pic->phone_number) {
                $ticket->loadMissing(['customer', 'division', 'assignee' => fn ($q) => $q->withTrashed()]);
                $this->notificationService->sendPicTicketReopened($pic, $ticket);
            }
        }

        return $ticket;
    }

    public function sendTextToCustomer(string $toPhone, string $message): void
    {
        $this->notificationService->sendText($toPhone, $message);
    }

    public function sendSystemErrorTemplate(string $toPhone, string $customerName = ''): void
    {
        $this->notificationService->sendSystemError($toPhone, $customerName);
    }

    public function sendTemplateToCustomer(string $toPhone, string $templateName, string $customerName = ''): void
    {
        // Backward-compatible wrapper (old signature). Untuk template yang butuh variabel lain,
        // gunakan method spesifik di NotificationService.
        $this->notificationService->sendTemplate(
            $toPhone,
            $templateName,
            [$customerName !== '' ? $customerName : 'Halo'],
        );
    }

    public function sendCustomerPendingReminderTemplate(string $toPhone, string $customerName = ''): void
    {
        // Backward-compatible wrapper.
        $this->notificationService->sendTemplate(
            $toPhone,
            'customer_pending_reminder',
            [$customerName !== '' ? $customerName : 'Halo'],
        );
    }

    public function closePendingTicketBySystem(Ticket $ticket): Ticket
    {
        $now = CarbonImmutable::now();

        $fromStatus = (string) $ticket->status;
        if ($fromStatus !== 'pending') {
            return $ticket;
        }

        $ticket->status = 'closed';
        $ticket->closed_at = $now;
        if (! $ticket->satisfaction_review_sent_at) {
            $this->notificationService->sendSatisfactionReview($ticket);
            $ticket->satisfaction_review_sent_at = $now;
        }
        $ticket->save();

        event(new TicketStatusChanged([
            'ticket_id' => $ticket->id,
            'from_status' => $fromStatus,
            'to_status' => 'closed',
            'changed_by' => ['id' => null, 'name' => 'Sistem', 'role' => 'system'],
            'changed_at' => $now->toISOString(),
            'pic_id' => (string) ($ticket->assigned_to ?? ''),
        ]));

        $this->activateNextQueueIfAny($ticket->customer_id);

        return $ticket;
    }

    private function getFallbackDivisionOrFail(): Division
    {
        /** @var Division|null $division */
        $division = Division::query()->where('is_fallback', true)->first();
        if (! $division) {
            throw new HttpException(500, 'Terjadi kesalahan pada server.');
        }

        return $division;
    }

    private function applyTicketVisibilityScope(Builder $query, User $user): void
    {
        if ($user->role === 'pic') {
            $query->where('assigned_to', $user->id);
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyTicketFilters(Builder $query, User $user, array $filters): void
    {
        if (! empty($filters['has_request']) && $user->role === 'spv') {
            $query->whereHas('takeoverRequest', function (Builder $q): void {
                $q->whereIn('status', ['pending', 'approved']);
            });
        }

        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        if (! empty($filters['priority'])) {
            $query->where('priority', (string) $filters['priority']);
        }

        if (! empty($filters['division_id']) && $user->role === 'spv') {
            $query->where('division_id', (string) $filters['division_id']);
        }

        if (! empty($filters['assigned_to']) && $user->role === 'spv') {
            $query->where('assigned_to', (string) $filters['assigned_to']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', (string) $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', (string) $filters['date_to']);
        }

        if (! empty($filters['search'])) {
            $search = (string) $filters['search'];
            $query->where(function (Builder $q) use ($search): void {
                $q->where('subject', 'like', "%{$search}%")
                    ->orWhere('ticket_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', function (Builder $cq) use ($search): void {
                        $cq->withTrashed()->where('name', 'like', "%{$search}%");
                    });
            });
        }
    }

    private function getTicketForUserOrFail(User $user, string $ticketId): Ticket
    {
        $query = Ticket::query()->with([
            'customer' => fn ($q) => $q->withTrashed(),
            'division',
            'assignee' => fn ($q) => $q->withTrashed(),
            'takeoverRequest' => fn ($q) => $q->with(['requester' => fn ($qq) => $qq->withTrashed()]),
        ]);

        $this->applyTicketVisibilityScope($query, $user);

        /** @var Ticket|null $ticket */
        $ticket = $query->find($ticketId);
        if (! $ticket) {
            throw new HttpException(404, 'Ticket tidak ditemukan.');
        }

        return $ticket;
    }

    private function assertCanReply(User $actor, Ticket $ticket): void
    {
        $ticket->loadMissing(['takeoverRequest' => fn ($q) => $q->with(['requester' => fn ($qq) => $qq->withTrashed()])]);
        /** @var TicketTakeoverRequest|null $takeover */
        $takeover = $ticket->takeoverRequest;

        if ($actor->role === 'pic') {
            if ((string) $ticket->assigned_to !== (string) $actor->id) {
                throw new HttpException(403, 'Anda tidak memiliki akses ke halaman ini.');
            }

            if ($takeover && (string) $takeover->status === 'approved' && (string) $takeover->requested_by === (string) $actor->id) {
                throw new HttpException(403, 'Ticket sedang diambil alih oleh SPV. Anda tidak bisa melakukan aksi.');
            }

            return;
        }

        if ($actor->role === 'spv') {
            if ($takeover && (string) $takeover->status === 'approved') {
                return;
            }

            if (! $ticket->assigned_to) {
                throw new HttpException(403, 'Anda tidak memiliki akses ke halaman ini.');
            }

            $assignee = $ticket->assignee;
            if (! $assignee || $assignee->role !== 'spv' || (string) $assignee->id !== (string) $actor->id) {
                throw new HttpException(403, 'Anda tidak memiliki akses ke halaman ini.');
            }

            return;
        }

        throw new HttpException(403, 'Anda tidak memiliki akses ke halaman ini.');
    }

    private function assertCanChangeStatus(User $actor, Ticket $ticket): void
    {
        $this->assertCanReply($actor, $ticket);
    }

    /**
     * @return array<int, string>
     */
    private function allowedHumanTransitions(string $fromStatus): array
    {
        return match ($fromStatus) {
            'open' => ['pending', 'on_progress', 'solved'],
            'pending' => ['open'],
            'on_progress' => ['open', 'solved'],
            default => [],
        };
    }

    private function activateNextQueueIfAny(string $customerId): void
    {
        /** @var Ticket|null $next */
        $next = Ticket::query()
            ->where('customer_id', $customerId)
            ->where('status', 'queue')
            ->orderByRaw("CASE priority WHEN 'high' THEN 3 WHEN 'medium' THEN 2 WHEN 'low' THEN 1 ELSE 0 END DESC")
            ->orderBy('created_at')
            ->first();

        if (! $next) {
            return;
        }

        $division = $next->division ?: Division::query()->find($next->division_id);
        $now = CarbonImmutable::now();

        DB::transaction(function () use ($next, $division, $now): void {
            $next->status = 'new';
            $next->activated_at = $now;
            $next->sla_fr_started_at = $now;
            $next->sla_fr_deadline_at = $this->slaService->calculateDeadline($now, $this->getSlaFrDurationMinutes(), (string) $next->division_id);
            $next->queue_position = null;
            $next->queue_priority = null;

            if ($division && ! $division->is_fallback) {
                $assigned = $this->assignService->autoAssign($next);
                if ($assigned) {
                    // AssignService sudah broadcast TicketAssigned.
                }
            }

            $next->save();

            event(new TicketStatusChanged([
                'ticket_id' => $next->id,
                'from_status' => 'queue',
                'to_status' => 'new',
                'changed_by' => ['id' => null, 'name' => 'Sistem', 'role' => 'system'],
                'changed_at' => $now->toISOString(),
                'pic_id' => (string) ($next->assigned_to ?? ''),
            ]));
        });
    }

    private function priorityToQueuePriority(string $priority): int
    {
        return match ($priority) {
            'high' => 3,
            'medium' => 2,
            default => 1,
        };
    }

    private function getSlaFrDurationMinutes(): int
    {
        $setting = AppSetting::query()->find('sla_fr_duration_minutes');

        return max(1, (int) ($setting?->value ?? 5));
    }

    private function assertValidPhone(string $normalized): void
    {
        $len = strlen($normalized);
        if ($len < 10 || $len > 15) {
            throw new HttpException(422, 'Nomor HP tidak valid.');
        }
    }

    private function assertValidMediaKey(string $key): void
    {
        if ($key === '') {
            throw new HttpException(422, 'Payload tidak valid: ai_reply.key wajib diisi.');
        }

        if (mb_strlen($key) > 1024) {
            throw new HttpException(422, 'Payload tidak valid: ai_reply.key terlalu panjang.');
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $key) === 1) {
            throw new HttpException(422, 'Payload tidak valid: ai_reply.key tidak valid.');
        }

        $normalized = str_replace('\\', '/', $key);
        if (str_starts_with($normalized, '/') || str_contains($normalized, '../') || str_contains($normalized, '/..')) {
            throw new HttpException(422, 'Payload tidak valid: ai_reply.key tidak valid.');
        }

        if (preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $key) === 1) {
            throw new HttpException(422, 'Payload tidak valid: ai_reply.key harus berupa storage key.');
        }
    }
}
