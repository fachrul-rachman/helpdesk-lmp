<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Division;
use App\Models\Message;
use App\Models\Ticket;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DashboardService
{
    public function __construct(private readonly SlaService $slaService) {}

    /**
     * @return array<string, mixed>
     */
    public function spvAnalytics(): array
    {
        $totalTickets = (int) Ticket::query()->count();
        $activeTickets = (int) Ticket::query()
            ->whereIn('status', ['new', 'open', 'pending', 'on_progress'])
            ->count();

        $perDivisionCounts = Ticket::query()
            ->selectRaw('division_id, COUNT(*) as cnt')
            ->groupBy('division_id')
            ->get()
            ->map(function ($row) {
                return [
                    'division_id' => (string) $row->division_id,
                    'count' => (int) $row->cnt,
                ];
            })
            ->all();

        $divisionNames = Division::query()
            ->pluck('name', 'id')
            ->mapWithKeys(fn ($name, $id) => [(string) $id => (string) $name])
            ->all();

        $ticketsPerDivision = array_map(function (array $row) use ($divisionNames) {
            $id = (string) ($row['division_id'] ?? '');

            return [
                'division_id' => $id,
                'division_name' => $divisionNames[$id] ?? '-',
                'count' => (int) ($row['count'] ?? 0),
            ];
        }, $perDivisionCounts);

        $overallMinutes = 0;
        $overallCount = 0;
        $perDivTotals = [];

        Ticket::query()
            ->select(['id', 'division_id', 'sla_fr_started_at', 'sla_fr_completed_at', 'sla_fr_status'])
            ->where('sla_fr_status', 'done')
            ->whereNotNull('sla_fr_started_at')
            ->whereNotNull('sla_fr_completed_at')
            ->chunkById(200, function ($tickets) use (&$overallMinutes, &$overallCount, &$perDivTotals): void {
                foreach ($tickets as $ticket) {
                    $start = $ticket->sla_fr_started_at ? CarbonImmutable::parse($ticket->sla_fr_started_at) : null;
                    $end = $ticket->sla_fr_completed_at ? CarbonImmutable::parse($ticket->sla_fr_completed_at) : null;
                    if (! $start || ! $end) {
                        continue;
                    }

                    $divisionId = (string) $ticket->division_id;
                    $mins = $this->slaService->calculateElapsedWorkingMinutes($start, $end, $divisionId);
                    $overallMinutes += $mins;
                    $overallCount += 1;

                    if (! isset($perDivTotals[$divisionId])) {
                        $perDivTotals[$divisionId] = ['minutes' => 0, 'count' => 0];
                    }
                    $perDivTotals[$divisionId]['minutes'] += $mins;
                    $perDivTotals[$divisionId]['count'] += 1;
                }
            });

        $perDivisionAvg = [];
        foreach ($perDivTotals as $divisionId => $agg) {
            $count = (int) ($agg['count'] ?? 0);
            $minutes = (int) ($agg['minutes'] ?? 0);
            $perDivisionAvg[] = [
                'division_id' => (string) $divisionId,
                'division_name' => $divisionNames[(string) $divisionId] ?? '-',
                'average_minutes' => $count > 0 ? round($minutes / $count, 2) : 0,
            ];
        }

        return [
            'tickets' => [
                'total' => $totalTickets,
                'active' => $activeTickets,
                'per_division' => $ticketsPerDivision,
            ],
            'sla_fr' => [
                'average_minutes_overall' => $overallCount > 0 ? round($overallMinutes / $overallCount, 2) : 0,
                'per_division' => $perDivisionAvg,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function spvConversations(array $filters): array
    {
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 20)));
        $page = max(1, (int) ($filters['page'] ?? 1));

        $query = Customer::query()->withTrashed();

        if (! empty($filters['search'])) {
            $search = (string) $filters['search'];
            $query->where(function (Builder $q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('phone_number', 'like', "%{$search}%");
            });
        }

        if (array_key_exists('has_ticket', $filters) && $filters['has_ticket'] !== null) {
            $hasTicket = filter_var($filters['has_ticket'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if ($hasTicket === true) {
                $query->whereHas('tickets');
            } elseif ($hasTicket === false) {
                $query->whereDoesntHave('tickets');
            }
        }

        // Filter berdasarkan last message date range (dari messages table).
        $dateFrom = ! empty($filters['date_from']) ? (string) $filters['date_from'] : null;
        $dateTo = ! empty($filters['date_to']) ? (string) $filters['date_to'] : null;
        if ($dateFrom || $dateTo) {
            $query->whereHas('messages', function (Builder $mq) use ($dateFrom, $dateTo): void {
                if ($dateFrom) {
                    $mq->whereDate('created_at', '>=', $dateFrom);
                }
                if ($dateTo) {
                    $mq->whereDate('created_at', '<=', $dateTo);
                }
            });
        }

        // Order by last message created_at desc
        $lastMessageSub = Message::query()
            ->selectRaw('MAX(created_at)')
            ->whereColumn('customer_id', 'customers.id');

        $query->orderByDesc($lastMessageSub);

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        /** @var array<int, Customer> $customers */
        $customers = $paginator->items();

        $data = [];
        foreach ($customers as $customer) {
            /** @var Message|null $lm */
            $lm = Message::query()
                ->where('customer_id', $customer->id)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first();

            /** @var Ticket|null $at */
            $at = Ticket::query()
                ->where('customer_id', $customer->id)
                ->whereIn('status', ['new', 'open', 'pending', 'on_progress'])
                ->orderByDesc('created_at')
                ->first();

            $data[] = [
                'customer' => [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'phone_number' => $customer->phone_number,
                ],
                'last_message' => $lm ? [
                    'content' => $lm->content,
                    'sender_type' => $lm->sender_type,
                    'created_at' => optional($lm->created_at)->toISOString(),
                ] : null,
                'active_ticket' => $at ? [
                    'id' => $at->id,
                    'status' => $at->status,
                    'subject' => $at->subject,
                ] : null,
            ];
        }

        return [
            'data' => $data,
            'meta' => [
                'total' => (int) $paginator->total(),
                'page' => (int) $paginator->currentPage(),
                'per_page' => (int) $paginator->perPage(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function spvConversationDetail(string $customerId, array $filters): array
    {
        /** @var Customer|null $customer */
        $customer = Customer::query()->withTrashed()->find($customerId);
        if (! $customer) {
            throw new HttpException(404, 'Customer tidak ditemukan.');
        }

        $limit = max(1, min(500, (int) ($filters['limit'] ?? 200)));

        /** @var Collection<int, Message> $messages */
        $messages = Message::query()
            ->where('customer_id', $customer->id)
            ->with([
                'attachments',
                'sender' => fn ($q) => $q->withTrashed(),
                'customer' => fn ($q) => $q->withTrashed(),
                'ticket.division',
                'ticket.assignee' => fn ($q) => $q->withTrashed(),
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->sort(function (Message $a, Message $b): int {
                $at = optional($a->created_at)->getTimestamp() ?? 0;
                $bt = optional($b->created_at)->getTimestamp() ?? 0;
                if ($at === $bt) {
                    return strcmp((string) $a->id, (string) $b->id);
                }

                return $at <=> $bt;
            })
            ->values();

        /** @var Collection<int, Ticket> $tickets */
        $tickets = Ticket::query()
            ->where('customer_id', $customer->id)
            ->with(['customer' => fn ($q) => $q->withTrashed(), 'division', 'assignee' => fn ($q) => $q->withTrashed(), 'takeoverRequest'])
            ->orderByRaw("CASE WHEN status IN ('new', 'open', 'pending', 'on_progress') THEN 0 WHEN status = 'queue' THEN 1 ELSE 2 END")
            ->orderByDesc('created_at')
            ->get();

        $activeTicket = $tickets->first(
            fn (Ticket $ticket): bool => in_array((string) $ticket->status, ['new', 'open', 'pending', 'on_progress'], true)
        );

        return [
            'customer' => $this->formatConversationCustomer($customer),
            'messages' => $messages->map(fn (Message $message) => $this->formatConversationMessage($message))->all(),
            'tickets' => $tickets->map(fn (Ticket $ticket) => $this->formatConversationTicket($ticket))->all(),
            'active_ticket' => $activeTicket ? $this->formatConversationTicket($activeTicket) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatConversationCustomer(Customer $customer): array
    {
        if (method_exists($customer, 'trashed') && $customer->trashed()) {
            return [
                'id' => $customer->id,
                'name' => 'Customer Dihapus',
                'phone_number' => null,
                'notes' => null,
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
    private function formatConversationMessage(Message $message): array
    {
        $formatted = app(TicketService::class)->formatMessage($message);
        $ticket = $message->ticket;

        $formatted['ticket'] = $ticket ? $this->formatConversationTicket($ticket) : null;

        return $formatted;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatConversationTicket(Ticket $ticket): array
    {
        $ticket->loadMissing(['division', 'assignee' => fn ($q) => $q->withTrashed(), 'takeoverRequest']);

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
            'division' => $ticket->division ? ['id' => $ticket->division->id, 'name' => $ticket->division->name] : null,
            'assigned_to' => $ticket->assignee ? ['id' => $ticket->assignee->id, 'name' => $ticket->assignee->name] : null,
            'sla_fr_status' => $ticket->sla_fr_status,
            'sla_resolution_status' => $ticket->sla_resolution_status,
            'sla_resolution_deadline_at' => optional($ticket->sla_resolution_deadline_at)->toISOString(),
            'created_at' => optional($ticket->created_at)->toISOString(),
        ];
    }
}
