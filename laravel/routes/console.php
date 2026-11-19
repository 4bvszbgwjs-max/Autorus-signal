<?php

use App\Notifications\DeliverSendings;
use App\Notifications\MessageIntake;
use App\Notifications\PrepareSendings;
use App\Notifications\PurgeExpired;
use App\Notifications\RabbitMq;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('homer:prepare {--limit=100}', function (PrepareSendings $prepare): int {
    $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
    if ($limit === false) {
        $this->error('The batch limit must be between 1 and 1000.');

        return 1;
    }

    $this->info('Processed messages: '.$prepare->batch($limit));

    return 0;
})->purpose('Prepare sendings for a batch of new messages');

Artisan::command('homer:consume {--once}', function (RabbitMq $rabbit, MessageIntake $intake): int {
    $url = config('homer.rabbitmq_url');
    $exchange = config('homer.rabbitmq_exchange');
    if (! filled($url) || ! filled($exchange)) {
        $this->error('Set RMQ_BROKER_URL and RMQ_CREATE_MESSAGE_EXCHANGE.');

        return 1;
    }

    try {
        $rabbit->consume($intake, $url, $exchange, $this->option('once'));
    } catch (\Throwable $error) {
        $this->error('RabbitMQ consumer stopped: '.$error::class);

        return 1;
    }

    return 0;
})->purpose('Consume incoming notifications from RabbitMQ');

Artisan::command('homer:deliver {--limit=100}', function (DeliverSendings $delivery): int {
    $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
    if ($limit === false) {
        $this->error('The batch limit must be between 1 and 1000.');

        return 1;
    }

    $this->info('Processed sendings: '.$delivery->batch($limit));

    return 0;
})->purpose('Send due notifications through their configured providers');

Schedule::command('homer:prepare')->everySecond()->runInBackground()->withoutOverlapping(5);
Schedule::command('homer:deliver --limit=10')->everySecond()->runInBackground()->withoutOverlapping(5);

Artisan::command('homer:purge-expired {--limit=1000}', function (PurgeExpired $purge): int {
    $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
    if ($limit === false) {
        $this->error('The batch limit must be between 1 and 1000.');

        return 1;
    }

    $this->info('Purged messages: '.$purge->batch($limit));

    return 0;
})->purpose('Delete expired messages and their sendings');

if (config('homer.purge_expired')) {
    Schedule::command('homer:purge-expired')->everyMinute()->withoutOverlapping(5);
}
