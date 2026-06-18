<?php

namespace App\Services;

use App\Models\DivisionWorkingHour;
use App\Models\PublicHoliday;
use App\Models\Ticket;
use App\Models\TicketSlaPause;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SlaService
{
    public function calculateDeadline(CarbonImmutable $startAt, int $durationMinutes, string $divisionId): CarbonImmutable
    {
        if ($durationMinutes <= 0) {
            return $startAt;
        }

        if (!$this->hasWorkingHours($divisionId)) {
            return $startAt->addMinutes($durationMinutes);
        }

        $originalTimezone = $startAt->getTimezone();
        $businessStart = $startAt->setTimezone($this->businessTimezone());
        $cursor = $this->alignToWorkingMinute($businessStart, $divisionId);
        $remaining = $durationMinutes;

        while ($remaining > 0) {
            $working = $this->workingWindowForDate($cursor, $divisionId);
            if (!$working) {
                $cursor = $this->nextWorkingStart($cursor->addDay()->startOfDay(), $divisionId);
                continue;
            }

            $start = $working['start'];
            $end = $working['end'];

            if ($cursor->lessThan($start)) {
                $cursor = $start;
            }

            if ($cursor->greaterThanOrEqualTo($end)) {
                $cursor = $this->nextWorkingStart($cursor->addDay()->startOfDay(), $divisionId);
                continue;
            }

            $available = $cursor->diffInMinutes($end);
            $consume = min($available, $remaining);
            $cursor = $cursor->addMinutes($consume);
            $remaining -= $consume;

            if ($remaining > 0 && $cursor->greaterThanOrEqualTo($end)) {
                $cursor = $this->nextWorkingStart($cursor->addDay()->startOfDay(), $divisionId);
            }
        }

        return $cursor->setTimezone($originalTimezone);
    }

    public function calculateElapsedWorkingMinutes(CarbonImmutable $startAt, CarbonImmutable $endAt, string $divisionId): int
    {
        if ($endAt->lessThanOrEqualTo($startAt)) {
            return 0;
        }

        if (!$this->hasWorkingHours($divisionId)) {
            return $startAt->diffInMinutes($endAt);
        }

        $businessTimezone = $this->businessTimezone();
        $startAt = $startAt->setTimezone($businessTimezone);
        $endAt = $endAt->setTimezone($businessTimezone);

        $total = 0;
        $cursor = $startAt;

        while ($cursor->toDateString() <= $endAt->toDateString()) {
            $dayWindow = $this->workingWindowForDate($cursor, $divisionId);
            if (!$dayWindow) {
                $cursor = $cursor->addDay()->startOfDay();
                continue;
            }

            $dayStart = $dayWindow['start'];
            $dayEnd = $dayWindow['end'];

            $rangeStart = $cursor->greaterThan($dayStart) ? $cursor : $dayStart;
            $rangeEnd = $endAt->lessThan($dayEnd) ? $endAt : $dayEnd;

            if ($rangeEnd->greaterThan($rangeStart)) {
                $total += $rangeStart->diffInMinutes($rangeEnd);
            }

            $cursor = $cursor->addDay()->startOfDay();
        }

        return $total;
    }

    public function pauseSla(string $ticketId, string $reason): void
    {
        /** @var Ticket|null $ticket */
        $ticket = Ticket::query()->find($ticketId);
        if (!$ticket) {
            throw new HttpException(404, 'Ticket tidak ditemukan.');
        }

        $now = CarbonImmutable::now();

        DB::transaction(function () use ($ticket, $now, $reason): void {
            $ticket->sla_resolution_paused_at = $now;
            $ticket->sla_resolution_status = 'paused';
            $ticket->save();

            TicketSlaPause::create([
                'ticket_id' => $ticket->id,
                'paused_at' => $now,
                'resumed_at' => null,
                'reason' => $reason,
            ]);
        });
    }

    public function resumeSla(string $ticketId): void
    {
        /** @var Ticket|null $ticket */
        $ticket = Ticket::query()->find($ticketId);
        if (!$ticket) {
            throw new HttpException(404, 'Ticket tidak ditemukan.');
        }

        if (!$ticket->sla_resolution_paused_at) {
            return;
        }

        $now = CarbonImmutable::now();
        $pausedAt = CarbonImmutable::parse($ticket->sla_resolution_paused_at);
        $elapsedWorkingMinutes = $this->calculateElapsedWorkingMinutes($pausedAt, $now, (string) $ticket->division_id);
        $elapsedSeconds = $elapsedWorkingMinutes * 60;

        DB::transaction(function () use ($ticket, $now, $elapsedSeconds): void {
            $ticket->sla_resolution_paused_duration = (int) $ticket->sla_resolution_paused_duration + $elapsedSeconds;

            if ($ticket->sla_resolution_deadline_at) {
                $ticket->sla_resolution_deadline_at = CarbonImmutable::parse($ticket->sla_resolution_deadline_at)->addSeconds($elapsedSeconds);
            }

            $ticket->sla_resolution_paused_at = null;
            $ticket->sla_resolution_status = 'running';
            $ticket->save();

            /** @var TicketSlaPause|null $pause */
            $pause = TicketSlaPause::query()
                ->where('ticket_id', $ticket->id)
                ->whereNull('resumed_at')
                ->orderByDesc('paused_at')
                ->first();

            if ($pause) {
                $pause->resumed_at = $now;
                $pause->save();
            }
        });
    }

    public function resetResolutionSla(string $ticketId): void
    {
        /** @var Ticket|null $ticket */
        $ticket = Ticket::query()->find($ticketId);
        if (!$ticket) {
            throw new HttpException(404, 'Ticket tidak ditemukan.');
        }

        $now = CarbonImmutable::now();
        $durationMinutes = $this->divisionResolutionMinutes((string) $ticket->division_id);

        DB::transaction(function () use ($ticket, $now, $durationMinutes): void {
            $ticket->sla_resolution_started_at = $now;
            $ticket->sla_resolution_deadline_at = $this->calculateDeadline($now, $durationMinutes, (string) $ticket->division_id);
            $ticket->sla_resolution_paused_at = null;
            $ticket->sla_resolution_paused_duration = 0;
            $ticket->sla_resolution_status = 'running';
            $ticket->save();
        });
    }

    public function divisionResolutionMinutes(string $divisionId): int
    {
        $division = DB::table('divisions')->select(['sla_resolution_value', 'sla_resolution_unit'])->where('id', $divisionId)->first();
        if (!$division) {
            return 60 * 24 * 3;
        }

        $value = (int) ($division->sla_resolution_value ?? 3);
        $unit = (string) ($division->sla_resolution_unit ?? 'days');

        return match ($unit) {
            'minutes' => $value,
            'hours' => $value * 60,
            default => $value * 60 * 24,
        };
    }

    private function alignToWorkingMinute(CarbonImmutable $at, string $divisionId): CarbonImmutable
    {
        return $this->nextWorkingStart($at, $divisionId);
    }

    private function nextWorkingStart(CarbonImmutable $at, string $divisionId): CarbonImmutable
    {
        $cursor = $at;

        for ($i = 0; $i < 400; $i++) {
            $window = $this->workingWindowForDate($cursor, $divisionId);
            if ($window) {
                if ($cursor->lessThan($window['start'])) {
                    return $window['start'];
                }
                if ($cursor->greaterThanOrEqualTo($window['start']) && $cursor->lessThan($window['end'])) {
                    return $cursor;
                }
            }

            $cursor = $cursor->addDay()->startOfDay();
        }

        return $at;
    }

    /**
     * @return array{start: CarbonImmutable, end: CarbonImmutable}|null
     */
    private function workingWindowForDate(CarbonImmutable $date, string $divisionId): ?array
    {
        if (PublicHoliday::query()->whereDate('date', $date->toDateString())->exists()) {
            return null;
        }

        $day = strtolower($date->englishDayOfWeek);

        /** @var DivisionWorkingHour|null $wh */
        $wh = DivisionWorkingHour::query()
            ->where('division_id', $divisionId)
            ->where('day_of_week', $day)
            ->first();

        if (!$wh || !$wh->is_active) {
            return null;
        }

        $start = CarbonImmutable::parse($date->toDateString() . ' ' . substr((string) $wh->start_time, 0, 5), $date->getTimezone());
        $end = CarbonImmutable::parse($date->toDateString() . ' ' . substr((string) $wh->end_time, 0, 5), $date->getTimezone());

        if ($end->lessThanOrEqualTo($start)) {
            return null;
        }

        return ['start' => $start, 'end' => $end];
    }

    private function hasWorkingHours(string $divisionId): bool
    {
        return DivisionWorkingHour::query()
            ->where('division_id', $divisionId)
            ->where('is_active', true)
            ->exists();
    }

    private function businessTimezone(): string
    {
        return (string) config('app.business_timezone', 'Asia/Jakarta');
    }
}
