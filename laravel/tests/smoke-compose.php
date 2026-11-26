<?php

use App\Notifications\MessageIntake;
use App\Notifications\RabbitMq;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PhpAmqpLib\Connection\AMQPStreamConnection;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! $app->environment('testing')) {
    fwrite(STDERR, "Run this check with the isolated Compose smoke script.\n");
    exit(1);
}

try {
    $api = Http::withOptions(['proxy' => ''])->baseUrl('http://127.0.0.1:8000/api/v1')
        ->timeout(10)->withToken(config('homer.api_token'));
    $url = config('homer.rabbitmq_url');
    $outgoingExchange = 'homer.smoke.out';
    $api->post('/recipient', ['phone' => '555'])->throw();
    $api->post('/channel', [
        'code' => 'compose-notices',
        'name' => 'Compose smoke test',
        'sender' => 'notices',
        'config' => ['url' => $url, 'exchange' => $outgoingExchange],
    ])->throw();

    $broker = parse_url($url);
    $connection = new AMQPStreamConnection($broker['host'], $broker['port'], $broker['user'], $broker['pass']);
    $channel = $connection->channel();
    $incomingExchange = config('homer.rabbitmq_exchange');
    foreach ([$incomingExchange, $outgoingExchange] as $exchange) {
        $channel->exchange_declare($exchange, 'direct', false, true, false);
        $channel->queue_declare($exchange, false, true, false, false);
        $channel->queue_bind($exchange, $exchange, '');
    }

    $rabbit = $app->make(RabbitMq::class);
    $intake = $app->make(MessageIntake::class);
    $recoveryExchange = 'homer.smoke.intake-recovery';
    $channel->exchange_declare($recoveryExchange, 'direct', false, true, false);
    $channel->queue_declare($recoveryExchange, false, true, false, false);
    $channel->queue_bind($recoveryExchange, $recoveryExchange, '');

    $rabbit->publish($url, $recoveryExchange, '', ['eventId' => 'invalid-intake', 'status' => 8], 'invalid-intake');
    $rabbit->consume($intake, $url, $recoveryExchange, once: true);
    if ($channel->basic_get($recoveryExchange, true) !== null
        || DB::table('messages')->where('eventId', 'invalid-intake')->exists()) {
        throw new RuntimeException('The invalid intake was queued again or persisted.');
    }

    $rabbit->publish($url, $recoveryExchange, '', ['eventId' => 'retry-intake'], 'retry-intake');
    $connectionName = DB::getDefaultConnection();
    $databaseConfig = 'database.connections.'.$connectionName.'.database';
    $originalDatabase = config($databaseConfig);
    config([$databaseConfig => 'synthetic_unavailable_database']);
    DB::purge($connectionName);
    try {
        $rabbit->consume($intake, $url, $recoveryExchange, once: true);
        throw new RuntimeException('Expected intake to fail with PostgreSQL unavailable.');
    } catch (QueryException|PDOException) {
        // The recovery consumer must see the delivery after the failing consumer exits.
    } finally {
        config([$databaseConfig => $originalDatabase]);
        DB::purge($connectionName);
    }
    $requeued = $channel->basic_get($recoveryExchange);
    if ($requeued === null || ! $requeued->isRedelivered()
        || DB::table('messages')->where('eventId', 'retry-intake')->exists()) {
        throw new RuntimeException('A failed transaction did not leave a redeliverable message.');
    }
    $requeued->reject(true);
    $rabbit->consume($intake, $url, $recoveryExchange, once: true);
    if (DB::table('messages')->where('eventId', 'retry-intake')->count() !== 1
        || $channel->basic_get($recoveryExchange, true) !== null) {
        throw new RuntimeException('The recovery consumer did not persist and acknowledge the message.');
    }

    $eventId = 'compose-smoke';
    $notification = [
        'eventId' => $eventId, 'recipientId' => '555',
        'channels' => ['compose-notices'], 'content' => 'Synthetic notification',
    ];
    $rabbit->publish($url, $incomingExchange, '', $notification, $eventId);
    $rabbit->publish($url, $incomingExchange, '', $notification, $eventId.'-replay');

    // Laravel's scheduler may wait until the next minute before its first tick.
    $deadline = time() + 90;
    do {
        $message = DB::table('messages')->where('eventId', $eventId)->first();
        if ($message?->status === 3) {
            break;
        }
        sleep(1);
    } while (time() < $deadline);

    if ($message?->status !== 3) {
        throw new RuntimeException('Timed out waiting for scheduled delivery.');
    }

    $published = $channel->basic_get($outgoingExchange, true);
    $items = json_decode($published?->getBody() ?? 'null', true, flags: JSON_THROW_ON_ERROR);
    if (($items[0]['content'] ?? null) !== 'Synthetic notification'
        || ($items[0]['uuid'] ?? null) !== $message->uuid
        || $channel->basic_get($outgoingExchange, true) !== null
        || DB::table('messages')->where('eventId', $eventId)->count() !== 1
        || DB::table('sendings')->where('message', $message->id)->count() !== 1) {
        throw new RuntimeException('The broker replay did not yield exactly one delivery.');
    }
    $connection->close();
    echo "Compose delivery smoke test passed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Compose delivery smoke test failed: '.$error->getMessage().PHP_EOL);
    exit(1);
}
