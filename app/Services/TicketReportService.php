<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\Division;
use App\Models\Ticket;
use App\Models\TicketSubcategory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

class TicketReportService
{
    private const ACTIVE = ['new', 'open', 'pending', 'on_progress'];

    private const DIMENSIONS = [
        'category' => 'global_subcategory_id', 'subcategory' => 'division_subcategory_id',
        'site' => 'site', 'zone' => 'zone',
    ];

    public function __construct(private readonly SlaService $slaService) {}

    public function report(array $filters): array
    {
        $divisions = Division::withTrashed()->orderBy('name')->get(['id', 'name', 'sla_resolution_value', 'sla_resolution_unit']);

        return [
            'data' => match ($filters['section'] ?? 'overview') {
                'sla' => $this->sla($filters, $divisions),
                'category' => $this->categories($filters),
                'satisfaction' => $this->satisfaction($filters, $divisions),
                default => $this->overview($filters, $divisions),
            },
            'divisions' => $divisions->map(fn ($division) => ['id' => $division->id, 'name' => $division->name])->all(),
        ];
    }

    public function tickets(array $filters): array
    {
        $page = $this->ticketQuery($filters)->with([
            'customer' => fn ($q) => $q->withTrashed(), 'division' => fn ($q) => $q->withTrashed(),
            'assignee' => fn ($q) => $q->withTrashed(), 'globalSubcategory', 'divisionSubcategory', 'takeoverRequest',
        ])->orderByDesc('tickets.created_at')->orderByDesc('tickets.id')
            ->paginate($filters['per_page'] ?? 20, ['tickets.*'], 'page', $filters['page'] ?? 1);

        $ticketService = app(TicketService::class);

        return [
            'data' => $page->getCollection()->map(fn ($ticket) => $ticketService->formatTicketListItem($ticket))->all(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'per_page' => $page->perPage()],
        ];
    }

    public function reviews(array $filters): array
    {
        $page = $this->reviewQuery($filters)->leftJoin('divisions', 'divisions.id', '=', 'tickets.division_id')
            ->select(['r.id', 'r.ticket_id', 'tickets.ticket_number', 'r.rating', 'r.feedback', 'divisions.name as division_name'])
            ->selectRaw('COALESCE(r.submitted_at, r.created_at) as submitted_at')
            ->orderByRaw('COALESCE(r.submitted_at, r.created_at) DESC')->orderByDesc('r.id')
            ->paginate($filters['per_page'] ?? 10, ['*'], 'page', $filters['page'] ?? 1);

        return [
            'data' => $page->getCollection()->map(fn ($row) => [
                'id' => $row->id, 'ticket_id' => $row->ticket_id, 'ticket_number' => $row->ticket_number,
                'rating' => (int) $row->rating, 'feedback' => $row->feedback,
                'division_name' => $row->division_name, 'submitted_at' => CarbonImmutable::parse($row->submitted_at)->toISOString(),
            ])->all(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'per_page' => $page->perPage()],
        ];
    }

    private function ticketQuery(array $filters): Builder
    {
        $query = Ticket::query();
        $unreviewed = ($filters['filter'] ?? '') === 'unreviewed';
        $column = $unreviewed ? 'COALESCE(tickets.solved_at, tickets.closed_at)' : 'tickets.created_at';
        $this->dateRange($query, $column, $filters);
        if (! empty($filters['division_id'])) {
            $query->where('tickets.division_id', $filters['division_id']);
        }
        if (! empty($filters['status'])) {
            $query->where('tickets.status', $filters['status']);
        }
        if (! empty($filters['priority'])) {
            $query->where('tickets.priority', $filters['priority']);
        }
        if (! empty($filters['has_request'])) {
            $query->whereHas('takeoverRequest', fn ($q) => $q->whereIn('status', ['pending', 'approved']));
        }
        if (($filters['filter'] ?? '') === 'active') {
            $query->whereIn('tickets.status', self::ACTIVE);
        } elseif ($unreviewed) {
            $query->whereIn('tickets.status', ['solved', 'closed'])
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('ticket_satisfaction_reviews as r')->whereColumn('r.ticket_id', 'tickets.id'));
        } elseif (in_array($filters['filter'] ?? '', ['fr_on_time', 'fr_late', 'resolution_on_time', 'resolution_late'], true)) {
            $this->slaFilter($query, $filters['filter']);
        }
        if (isset($filters['dimension'], self::DIMENSIONS[$filters['dimension']])) {
            $column = 'tickets.'.self::DIMENSIONS[$filters['dimension']];
            if (! empty($filters['missing'])) {
                $query->where(function ($q) use ($column, $filters): void {
                    $q->whereNull($column);
                    if (in_array($filters['dimension'], ['site', 'zone'], true)) {
                        $q->orWhere($column, '');
                    }
                });
            } else {
                $query->where($column, $filters['value'] ?? '');
            }
        }
        if (! empty($filters['search'])) {
            $search = '%'.$filters['search'].'%';
            $query->where(fn ($q) => $q->where('tickets.subject', 'like', $search)
                ->orWhere('tickets.ticket_number', 'like', $search)
                ->orWhereHas('customer', fn ($q) => $q->withTrashed()->where('name', 'like', $search)));
        }

        return $query;
    }

    private function dateRange(Builder|QueryBuilder $query, string $column, array $filters): void
    {
        $timezone = config('app.business_timezone', 'Asia/Jakarta');
        $from = CarbonImmutable::parse($filters['start_date'], $timezone)->startOfDay()->utc();
        $until = CarbonImmutable::parse($filters['end_date'], $timezone)->startOfDay()->addDay()->utc();
        $query->whereRaw($column.' >= ?', [$from])->whereRaw($column.' < ?', [$until]);
    }

    private function overview(array $filters, $divisions): array
    {
        $query = $this->ticketQuery($filters);
        $statuses = (clone $query)->select('status')->selectRaw('COUNT(*) as count')->groupBy('status')->pluck('count', 'status');
        $counts = (clone $query)->whereIn('status', self::ACTIVE)->select('division_id')
            ->selectRaw('COUNT(*) as count')->groupBy('division_id')->pluck('count', 'division_id');

        return [
            'summary' => [
                'total' => (int) $statuses->sum(),
                'active' => (int) collect(self::ACTIVE)->sum(fn ($s) => $statuses[$s] ?? 0),
                'completed' => (int) (($statuses['solved'] ?? 0) + ($statuses['closed'] ?? 0)),
                'queued' => (int) ($statuses['queue'] ?? 0),
            ],
            'statuses' => collect(['new', 'open', 'on_progress', 'queue'])->map(fn ($status) => [
                'status' => $status, 'count' => (int) ($statuses[$status] ?? 0),
            ])->all(),
            'active_by_division' => $divisions->filter(fn ($d) => ($counts[$d->id] ?? 0) > 0)->map(fn ($d) => [
                'id' => $d->id, 'name' => $d->name, 'count' => (int) $counts[$d->id],
            ])->sortByDesc('count')->values()->all(),
        ];
    }

    private function slaFilter(Builder $query, string $filter): void
    {
        $isFr = str_starts_with($filter, 'fr_');
        $prefix = $isFr ? 'sla_fr' : 'sla_resolution';
        $completed = $isFr ? 'sla_fr_completed_at' : 'COALESCE(solved_at, closed_at)';
        $query->whereNotNull($prefix.'_started_at')->whereNotNull($prefix.'_deadline_at')
            ->whereRaw($prefix.'_deadline_at >= '.$prefix.'_started_at')
            ->whereRaw($completed.' >= '.$prefix.'_started_at')
            ->whereRaw($completed.(str_ends_with($filter, '_late') ? ' > ' : ' <= ').$prefix.'_deadline_at');
        if (! $isFr) {
            $query->whereIn('status', ['solved', 'closed']);
        }
    }

    private function sla(array $filters, $divisions): array
    {
        $query = $this->ticketQuery($filters);
        $results = [];
        foreach (['fr_on_time', 'fr_late', 'resolution_on_time', 'resolution_late'] as $filter) {
            $filtered = clone $query;
            $this->slaFilter($filtered, $filter);
            $results[$filter] = $filtered->select('division_id')->selectRaw('COUNT(*) as count')->groupBy('division_id')->pluck('count', 'division_id');
        }
        $averages = [];
        $this->slaService->withCachedCalendar(function () use ($query, &$averages): void {
            $eligible = clone $query;
            $eligible->whereNotNull('sla_fr_started_at')->whereNotNull('sla_fr_completed_at')
                ->whereColumn('sla_fr_completed_at', '>=', 'sla_fr_started_at')
                ->select(['id', 'division_id', 'sla_fr_started_at', 'sla_fr_completed_at'])
                ->chunkById(500, function ($tickets) use (&$averages): void {
                    foreach ($tickets as $ticket) {
                        $id = $ticket->division_id;
                        $averages[$id] ??= ['minutes' => 0, 'count' => 0];
                        $averages[$id]['minutes'] += $this->slaService->calculateElapsedWorkingMinutes(
                            CarbonImmutable::parse($ticket->sla_fr_started_at), CarbonImmutable::parse($ticket->sla_fr_completed_at), $id,
                        );
                        $averages[$id]['count']++;
                    }
                });
        });
        $metric = static function (string $prefix, ?string $id = null) use ($results): array {
            $onTime = $id === null ? $results[$prefix.'_on_time']->sum() : ($results[$prefix.'_on_time'][$id] ?? 0);
            $late = $id === null ? $results[$prefix.'_late']->sum() : ($results[$prefix.'_late'][$id] ?? 0);

            return ['eligible' => (int) ($onTime + $late), 'on_time' => (int) $onTime, 'late' => (int) $late];
        };
        $count = array_sum(array_column($averages, 'count'));
        $fr = $metric('fr');
        $fr['average_minutes'] = $count ? round(array_sum(array_column($averages, 'minutes')) / $count, 2) : null;
        $fr['missing'] = (clone $query)->count() - $count;
        $target = max(1, (int) (AppSetting::find('sla_fr_duration_minutes')?->value ?? 5));

        return [
            'first_response' => $fr, 'resolution' => $metric('resolution'),
            'divisions' => $divisions->filter(fn ($d) => empty($filters['division_id']) || $d->id === $filters['division_id'])
                ->map(fn ($d) => [
                    'id' => $d->id, 'name' => $d->name, 'first_response_target_minutes' => $target,
                    'resolution_target_minutes' => (int) $d->sla_resolution_value * ($d->sla_resolution_unit === 'hours' ? 60 : 1440),
                    'average_minutes' => isset($averages[$d->id]) ? round($averages[$d->id]['minutes'] / $averages[$d->id]['count'], 2) : null,
                    'first_response' => $metric('fr', $d->id), 'resolution' => $metric('resolution', $d->id),
                ])->values()->all(),
        ];
    }

    private function categories(array $filters): array
    {
        $query = $this->ticketQuery($filters);
        $names = TicketSubcategory::pluck('name', 'id');
        $groups = [];
        foreach (self::DIMENSIONS as $dimension => $column) {
            $group = in_array($dimension, ['site', 'zone'], true) ? "NULLIF($column, '')" : $column;
            $groups[match ($dimension) {
                'category' => 'categories', 'subcategory' => 'subcategories', 'site' => 'sites', default => 'zones'
            }]
                = (clone $query)->selectRaw("$group as value, COUNT(*) as count")
                    ->groupByRaw($group)->orderByDesc('count')->get()->map(fn ($row) => [
                        'value' => $row->value, 'name' => $row->value === null ? 'Belum diisi' : (in_array($dimension, ['category', 'subcategory'], true) ? ($names[$row->value] ?? $row->value) : $row->value),
                        'count' => (int) $row->count,
                    ])->all();
        }

        return $groups;
    }

    private function reviewQuery(array $filters): QueryBuilder
    {
        $query = DB::table('ticket_satisfaction_reviews as r')->join('tickets', 'tickets.id', '=', 'r.ticket_id')
            ->whereNull('tickets.deleted_at')->whereBetween('r.rating', [1, 5]);
        $this->dateRange($query, 'COALESCE(r.submitted_at, r.created_at)', $filters);
        if (! empty($filters['division_id'])) {
            $query->where('tickets.division_id', $filters['division_id']);
        }
        if (! empty($filters['rating_group'])) {
            $query->whereBetween('r.rating', $filters['rating_group'] === 'good' ? [3, 5] : [1, 2]);
        }
        if (! empty($filters['rating'])) {
            $query->where('r.rating', $filters['rating']);
        }

        return $query;
    }

    private function satisfaction(array $filters, $divisions): array
    {
        $query = $this->reviewQuery($filters);
        $totals = (clone $query)->selectRaw('COUNT(*) as total, AVG(r.rating) as average, SUM(CASE WHEN r.rating >= 3 THEN 1 ELSE 0 END) as good, SUM(CASE WHEN r.rating <= 2 THEN 1 ELSE 0 END) as bad')->first();
        $grouped = (clone $query)->select('tickets.division_id')
            ->selectRaw('COUNT(*) as total, SUM(CASE WHEN r.rating >= 3 THEN 1 ELSE 0 END) as good, SUM(CASE WHEN r.rating <= 2 THEN 1 ELSE 0 END) as bad')
            ->groupBy('tickets.division_id')->get()->keyBy('division_id');
        $ratings = (clone $query)->select('r.rating')->selectRaw('COUNT(*) as count')->groupBy('r.rating')->pluck('count', 'rating');

        return [
            'summary' => [
                'total' => (int) $totals->total, 'average' => $totals->average === null ? null : round((float) $totals->average, 2),
                'good' => (int) $totals->good, 'bad' => (int) $totals->bad,
                'unreviewed' => $this->ticketQuery(array_merge($filters, ['filter' => 'unreviewed']))->count(),
            ],
            'ratings' => collect([5, 4, 3, 2, 1])->map(fn ($rating) => ['rating' => $rating, 'count' => (int) ($ratings[$rating] ?? 0)])->all(),
            'divisions' => $divisions->filter(fn ($d) => empty($filters['division_id']) || $d->id === $filters['division_id'])->map(fn ($d) => [
                'id' => $d->id, 'name' => $d->name, 'good' => (int) ($grouped[$d->id]->good ?? 0),
                'bad' => (int) ($grouped[$d->id]->bad ?? 0), 'total' => (int) ($grouped[$d->id]->total ?? 0),
            ])->values()->all(),
        ];
    }
}
