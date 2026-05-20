<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Jobs\CheckPendingTicketsJob;
use App\Jobs\CheckSlaFrOverdueJob;
use App\Jobs\CheckSlaFrReminderJob;
use App\Jobs\CheckSlaResolutionOverdueJob;
use App\Jobs\CheckSlaResolutionReminderJob;
use App\Jobs\FetchPublicHolidaysJob;
use App\Jobs\SendAiIntroductionJob;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new CheckSlaFrReminderJob)->everyMinute();
Schedule::job(new CheckSlaFrOverdueJob)->everyMinute();
Schedule::job(new CheckSlaResolutionReminderJob)->everyFiveMinutes();
Schedule::job(new CheckSlaResolutionOverdueJob)->everyFiveMinutes();
Schedule::job(new CheckPendingTicketsJob)->everyMinute();
Schedule::job(new SendAiIntroductionJob)->dailyAt('09:00');
Schedule::job(new FetchPublicHolidaysJob)->yearlyOn(1, 1, '00:05');
