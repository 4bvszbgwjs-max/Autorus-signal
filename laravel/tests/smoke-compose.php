<?php

use App\Notifications\RabbitMq;
use Illuminate\Contracts\Console\Kernel;
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
