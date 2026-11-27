<?php

use App\Notifications\DeliveryFailure;
use App\Notifications\MessageIntake;
use App\Notifications\RabbitMq;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Symfony\Component\Process\Process;

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

    $original = $api->post('/message', ['subject' => 'Original callback parent'])->throw()->json('data.0.id');
    $replacement = $api->post('/message', ['subject' => 'Replacement callback parent'])->throw()->json('data.0.id');
    $providerId = 'compose-reparent';
    $sending = $api->post('/sending', [
        'channel' => 'compose-notices', 'message' => $original, 'content' => 'Callback race', 'uuid' => $providerId,
    ])->throw()->json('data.id');
    $callback = new Process([PHP_BINARY, '-r', <<<'PHP'
        $curl = curl_init('http://127.0.0.1:8000/api/v1/sending/status');
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => 'PATCH',
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json',
                'Authorization: Bearer '.getenv('HOMER_CALLBACK_API_TOKEN')],
            CURLOPT_POSTFIELDS => json_encode(['uuid' => $argv[1], 'status' => 7], JSON_THROW_ON_ERROR),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_PROXY => '',
        ]);
        if (curl_exec($curl) === false) {
            exit(1);
        }
        echo curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        PHP, $providerId], timeout: 12);
    DB::beginTransaction();
    try {
        DB::table('messages')->where('id', $original)->lockForUpdate()->value('id');
        $lockPid = DB::scalar('SELECT pg_backend_pid()');
        $callback->start();
        $deadline = microtime(true) + 6;
        do {
            DB::selectOne('SELECT pg_stat_clear_snapshot()');
            $waiting = DB::scalar(<<<'SQL'
                SELECT EXISTS (
                    SELECT 1 FROM pg_stat_activity
                    WHERE ? = ANY(pg_blocking_pids(pid))
                      AND query LIKE 'select "id" from "messages"%for update'
                )
                SQL, [$lockPid]);
            if ($waiting) {
                break;
            }
            usleep(50000);
        } while ($callback->isRunning() && microtime(true) < $deadline);
        if (! $waiting) {
            throw new RuntimeException('The callback did not wait for the original parent lock.');
        }
        $writer = DB::build(config('database.connections.pgsql'));
        $writer->table('sendings')->where('id', $sending)->update(['message' => $replacement]);
    } finally {
        DB::rollBack();
    }
    if ($callback->wait() !== 0 || trim($callback->getOutput()) !== '409'
        || DB::table('sendings')->where('id', $sending)->value('status') !== 0) {
        throw new RuntimeException('The callback accepted a sending reassigned to an unlocked parent.');
    }
    $api->withToken(config('homer.callback_api_token'))->patch('/sending/status', ['uuid' => $providerId, 'status' => 7])->throw();
    $api->withToken(config('homer.api_token'));
    if (DB::table('messages')->where('id', $replacement)->value('status') !== 7) {
        throw new RuntimeException('The callback retry did not refresh the actual parent.');
    }

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

    $failures = 1;
    $attempts = 0;
    $onFailure = null;
    $sqlstate = '08006';
    DB::connection()->beforeExecuting(function (string $sql) use (&$failures, &$attempts, &$onFailure, &$sqlstate): void {
        if (str_starts_with($sql, 'insert into messages') && $failures > 0) {
            $failures--;
            $attempts++;
            $onFailure?->__invoke();
            $error = new PDOException('Synthetic database interruption.');
            $error->errorInfo = [$sqlstate];

            throw $error;
        }
    });
    $rabbit->publish($url, $recoveryExchange, '', ['eventId' => 'retry-intake'], 'retry-intake');
    $rabbit->consume($intake, $url, $recoveryExchange, once: true);
    if ($attempts !== 1 || DB::table('messages')->where('eventId', 'retry-intake')->count() !== 1
        || $channel->basic_get($recoveryExchange, true) !== null) {
        throw new RuntimeException('The recovery consumer did not persist and acknowledge the message.');
    }

    $failures = 10;
    $attempts = 0;
    $rabbit->publish($url, $recoveryExchange, '', ['eventId' => 'failed-intake'], 'failed-intake');
    $rabbit->consume($intake, $url, $recoveryExchange, once: true);
    $failed = $channel->basic_get($recoveryExchange.'.failed', true);
    if ($attempts !== 4 || $failed === null || $failed->get('message_id') !== 'failed-intake'
        || json_decode($failed->getBody(), true) !== ['eventId' => 'failed-intake']
        || $failed->get('application_headers')->getNativeData()['signal-intake-attempts'] !== 4
        || DB::table('messages')->where('eventId', 'failed-intake')->exists()
        || $channel->basic_get($recoveryExchange, true) !== null) {
        throw new RuntimeException('The exhausted message was not moved to the failed queue after four attempts.');
    }
    $sqlstate = '3D000';
    $failures = 1;
    $attempts = 0;
    $onFailure = fn () => $channel->queue_delete($recoveryExchange.'.failed');
    $rabbit->publish($url, $recoveryExchange, '', ['eventId' => 'unconfirmed-intake'], 'unconfirmed-intake');
    try {
        $rabbit->consume($intake, $url, $recoveryExchange, once: true);
        throw new RuntimeException('Expected the failed queue publish to be unroutable.');
    } catch (DeliveryFailure $error) {
        if ($error->getMessage() !== 'unroutable') {
            throw $error;
        }
    }
    $redelivered = $channel->basic_get($recoveryExchange);
    if ($attempts !== 1 || $redelivered === null || ! $redelivered->isRedelivered()
        || DB::table('messages')->where('eventId', 'unconfirmed-intake')->exists()) {
        throw new RuntimeException('An unconfirmed publish failed to preserve the original delivery.');
    }
    $redelivered->reject(true);
    $rabbit->consume($intake, $url, $recoveryExchange, once: true);
    if (DB::table('messages')->where('eventId', 'unconfirmed-intake')->count() !== 1) {
        throw new RuntimeException('An unconfirmed original could not be redelivered.');
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
