<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Services\NotificationDeliveryService;
use App\Services\SystemHealthService;
use App\Services\IncidentAlertService;
use App\Services\SupabaseService;
use Symfony\Component\Console\Command\Command;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('notifications:deliver {--limit=50}', function () {
    $stats = app(NotificationDeliveryService::class)->deliverPending((int) $this->option('limit'));

    if ($stats['error'] !== null) {
        $this->error($stats['error']);
        return Command::FAILURE;
    }

    $this->info("Claimed {$stats['claimed']}; sent {$stats['sent']}; failed {$stats['failed']}.");
    return $stats['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
})->purpose('Deliver queued MathVerse emails and Web Push notifications');

Artisan::command('system:heartbeat', function () {
    if (!app(SystemHealthService::class)->recordSchedulerHeartbeat()) {
        $this->error('Scheduler heartbeat failed.');
        return Command::FAILURE;
    }

    $this->info('Scheduler heartbeat recorded.');
    return Command::SUCCESS;
})->purpose('Record the MathVerse scheduler heartbeat');

Schedule::command('system:heartbeat')
    ->everyMinute()
    ->withoutOverlapping(5);

Schedule::command('notifications:deliver --limit=50')
    ->everyMinute()
    ->withoutOverlapping(10);

Artisan::command('incidents:check', function () {
    $stats = app(IncidentAlertService::class)->check();
    $this->info("Checked {$stats['checked']}; active {$stats['active']}; notified {$stats['notified']}; failures {$stats['failed']}.");
    return !$stats['enabled'] || $stats['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
})->purpose('Check operational/security signals and immediately notify administrators');

Artisan::command('incidents:prune', function () {
    $result = app(SupabaseService::class)->adminRpcResult('prune_incident_events');
    if ($result['error'] !== null) {
        $this->error('Incident event retention could not be applied.');
        return Command::FAILURE;
    }
    $this->info('Expired diagnostic events pruned. Security audits and incident records were not deleted.');
    return Command::SUCCESS;
})->purpose('Remove diagnostic events older than 30 days, preserving security audits');

Schedule::command('incidents:check')->everyMinute()->withoutOverlapping(5);
Schedule::command('incidents:prune')->daily()->withoutOverlapping(30);
