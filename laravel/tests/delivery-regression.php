<?php

use App\Notifications\DeliveryFailure;
use App\Notifications\PrepareSendings;
use App\Notifications\ProviderAmqpEndpoint;
use App\Notifications\ProviderDns;
use App\Notifications\ProviderHttpEndpoint;
use App\Notifications\Providers;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Client\Request as ProviderRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

if (! $app->environment('testing')) {
    fwrite(STDERR, "Use APP_ENV=testing with a migrated test PostgreSQL database.\n");
    exit(1);
}

$token = Str::random(32);
$callbackToken = Str::random(32);
config(['homer.api_token' => $token, 'homer.callback_api_token' => $callbackToken, 'app.key' => 'base64:'.base64_encode(random_bytes(32))]);

$request = function (string $method, string $path, array $payload, int $expected, ?string $credential = null) use ($kernel, $token, $callbackToken): array {
    $request = Request::create('/api/v1/'.$path, $method, server: [
        'HTTP_AUTHORIZATION' => 'Bearer '.($credential ?? ($path === 'sending/status' ? $callbackToken : $token)),
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/json',
    ], content: json_encode($payload, JSON_THROW_ON_ERROR));
    $response = $kernel->handle($request);
    if ($response->getStatusCode() !== $expected) {
        throw new RuntimeException("$method $path expected HTTP $expected, got ".$response->getStatusCode());
    }

    return $response->getContent() === '' ? [] : json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
};

$createChannel = function () use ($request): string {
    $code = 'review-'.Str::lower(Str::random(10));
    $request('POST', 'channel', ['code' => $code, 'name' => 'Regression channel', 'sender' => 'smsIdgtl'], 201);

    return $code;
};

$cases = [
    'channel and recipient credentials' => function () use ($request): void {
        $channel = 'review-'.Str::lower(Str::random(10));
        $createdChannel = $request('POST', 'channel', [
            'code' => $channel, 'name' => 'Regression test', 'sender' => 'smsIdgtl',
            'config' => ['token' => 'synthetic-channel-credential'],
        ], 201)['data'];
        $storedConfig = fn () => DB::table('channels')->where('code', $channel)->value('config');
        $ciphertext = $storedConfig();
        if (! array_key_exists('config', $createdChannel) || $createdChannel['config'] !== []
            || str_contains($ciphertext, 'synthetic-channel-credential')) {
            throw new RuntimeException('Channel credentials were not masked and encrypted.');
        }
        $request('PATCH', 'channel/'.$channel, ['config' => (object) []], 200);
        if ($storedConfig() !== $ciphertext) {
            throw new RuntimeException('Saving a masked channel cleared its stored configuration.');
        }
        $request('PATCH', 'channel/'.$channel, ['config' => null], 200);
        if (Crypt::decryptString($storedConfig()) !== '{}') {
            throw new RuntimeException('An explicit clear did not remove the channel configuration.');
        }

        $phone = Str::random(16);
        $recipient = $request('POST', 'recipient', [
            'phone' => $phone, 'applicationFirebaseToken' => 'synthetic-push-token',
        ], 201)['data'];
        $recipientPath = 'recipient/'.$recipient['id'];
        $storedToken = fn () => DB::table('recipients')->where('id', $recipient['id'])->value('applicationFirebaseToken');
        if (($recipient['applicationFirebaseToken'] ?? null) !== '') {
            throw new RuntimeException('The response did not mask the Firebase token.');
        }
        $request('PATCH', $recipientPath, $recipient, 200);
        $request('PATCH', 'recipient/bulk/upsert', [['phone' => $phone, 'applicationFirebaseToken' => '']], 200);
        if ($storedToken() !== 'synthetic-push-token') {
            throw new RuntimeException('Saving a masked recipient cleared its stored token.');
        }
        $request('PATCH', $recipientPath, ['applicationFirebaseToken' => null], 200);
        if ($storedToken() !== null) {
            throw new RuntimeException('An explicit clear did not remove the Firebase token.');
        }
    },
    'bulk recipients preserve sparse fields and request order' => function () use ($request): void {
        $existing = $request('POST', 'recipient', [
            'phone' => 'batch-existing', 'name' => 'Retain', 'email' => 'old@example.invalid',
            'isConfirmed' => true, 'applicationFirebaseToken' => 'synthetic-push-token',
        ], 201)['data'];
        $createdAt = now()->subDay();
        DB::table('recipients')->where('id', $existing['id'])->update(['createdAt' => $createdAt]);

        $rows = [
            ['phone' => 'batch-first', 'name' => 'First'],
            ['phone' => 'batch-existing', 'email' => null, 'applicationFirebaseToken' => ''],
            ['phone' => 'batch-second', 'name' => 'Second'],
            ['phone' => 'batch-third', 'name' => 'Third'],
        ];
        [$first, $updated, $second, $third] = $request('PATCH', 'recipient/bulk/upsert', $rows, 200)['data'];
        $stored = DB::table('recipients')->where('id', $existing['id'])->first();
        if ([$first['phone'], $updated['phone'], $second['phone'], $third['phone']] !== array_column($rows, 'phone')
            || ! ($first['id'] < $second['id'] && $second['id'] < $third['id'])
            || $first['isSubscribe'] !== false || $updated['id'] !== $existing['id']
            || $updated['name'] !== 'Retain' || $updated['email'] !== null || $updated['isConfirmed'] !== true
            || $updated['applicationFirebaseToken'] !== '' || $stored->applicationFirebaseToken !== 'synthetic-push-token'
            || ! Carbon::parse($stored->createdAt)->equalTo($createdAt->copy()->startOfSecond())) {
            throw new RuntimeException('Bulk upsert altered a default, an omitted field, or the request order.');
        }
        $request('PATCH', 'recipient/bulk/upsert', [['phone' => 'batch-existing', 'applicationFirebaseToken' => null]], 200);
        if (DB::table('recipients')->where('id', $existing['id'])->value('applicationFirebaseToken') !== null) {
            throw new RuntimeException('Bulk upsert did not clear an explicitly null token.');
        }
        $request('PATCH', 'recipient/bulk/upsert', [
            ['phone' => 'batch-rollback'], ['phone' => 'batch-invalid', 'name' => []],
        ], 400);
        if (DB::table('recipients')->where('phone', 'batch-rollback')->exists()) {
            throw new RuntimeException('Invalid recipient batch wrote a partial result.');
        }
    },
    'homogeneous recipient batch uses one write query' => function () use ($request): void {
        $rows = array_map(
            fn (int $index): array => ['phone' => 'recipient-'.(1000 - $index), 'name' => 'Synthetic '.$index],
            range(1, 1000),
        );
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $response = $request('PATCH', 'recipient/bulk/upsert', $rows, 200);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        if (count($queries) !== 1 || ! str_ends_with(strtolower($queries[0]['query']), ' returning *')
            || array_column($response['data'], 'phone') !== array_column($rows, 'phone')) {
            throw new RuntimeException('Homogeneous recipient batch did not return all rows in one query.');
        }
    },
    'status write permissions and pagination' => function () use ($request, $createChannel, $token, $callbackToken): void {
        $channel = $createChannel();
        $message = $request('POST', 'message', ['recipientId' => 'synthetic-recipient'], 200)['data'][0];
        $request('POST', 'message', ['status' => 8], 400);
        $request('POST', 'message', [['status' => null], ['subject' => 'Synthetic batch']], 400);
        $page = $request('GET', 'message?limit=1', [], 200);
        if ($message['status'] !== 0 || $page['meta']['limit'] !== 1 || count($page['data']) !== 1) {
            throw new RuntimeException('New notifications or explicit page size violate the API contract.');
        }
        $request('PATCH', 'message/'.$message['id'], ['status' => 3], 400);
        $request('PUT', 'message/'.$message['id'], ['status' => null], 400);
        $request('POST', 'sending', ['channel' => $channel, 'content' => 'Synthetic', 'status' => 3], 400);
        $sending = $request('POST', 'sending', ['channel' => $channel, 'content' => 'Synthetic'], 201)['data'];
        $request('PATCH', 'sending/'.$sending['id'], ['status' => 3], 400);
        $request('PUT', 'sending/'.$sending['id'], ['channel' => $channel, 'content' => 'Synthetic', 'status' => 3], 400);
        $request('PATCH', 'sending/status', ['uuid' => 'synthetic', 'status' => 'delivered'], 401, credential: $token);
        $request('GET', 'message?limit=1', [], 401, credential: $callbackToken);
        config(['homer.callback_api_token' => $token]);
        try {
            $request('PATCH', 'sending/status', ['uuid' => 'synthetic', 'status' => 'delivered'], 401, credential: $token);
        } finally {
            config(['homer.callback_api_token' => $callbackToken]);
        }
        if ($request('GET', 'sending/'.$sending['id'], [], 200)['data']['status'] !== 0
            || $request('GET', 'message/'.$message['id'], [], 200)['data']['status'] !== 0) {
            throw new RuntimeException('A rejected status mutation changed persisted data.');
        }
    },
    'provider callbacks, aggregate statuses and cascade' => function () use ($request, $createChannel): void {
        $channel = $createChannel();
        $message = $request('POST', 'message', ['recipientId' => 'synthetic-recipient'], 200)['data'][0];
        $providerId = (string) Str::uuid();
        $sending = $request('POST', 'sending', [
            'channel' => $channel,
            'message' => $message['id'],
            'uuid' => $providerId,
            'content' => 'Synthetic message',
            'destination' => 'synthetic-recipient',
        ], 201)['data'];
        $row = DB::table('sendings')->where('id', $sending['id']);
        (clone $row)->update(['status' => 2, 'claimedAt' => now()]);

        $request('PATCH', 'sending/status', ['uuid' => $providerId, 'status' => 'delivered'], 409);
        $request('DELETE', 'channel/'.$channel, [], 409);
        $request('PATCH', 'message/'.$message['id'], ['isDeleted' => true], 409);
        if ((int) (clone $row)->value('status') !== 2 || (clone $row)->value('claimedAt') === null) {
            throw new RuntimeException('A provider callback changed a sending held by the worker.');
        }

        (clone $row)->update(['status' => 3, 'claimedAt' => null]);
        foreach ([0, 1, 2] as $queueStatus) {
            $request('PATCH', 'sending/status', ['uuid' => $providerId, 'status' => $queueStatus], 400);
        }
        $request('PATCH', 'sending/status', [
            ['uuid' => $providerId, 'status' => 'delivered'],
            ['uuid' => $providerId, 'status' => 1],
        ], 400);
        if ((int) (clone $row)->value('status') !== 3) {
            throw new RuntimeException('A rejected callback changed a sent record.');
        }
        $request('PATCH', 'sending/status', ['uuid' => $providerId, 'status' => 'delivered'], 200);
        $request('PATCH', 'sending/status', ['uuid' => $providerId, 'status' => 'sent'], 200);
        if ((int) (clone $row)->value('status') !== 7 || (int) DB::table('messages')->where('id', $message['id'])->value('status') !== 7) {
            throw new RuntimeException('The late callback regressed the sending or its parent.');
        }

        $otherChannel = $channel.'-other';
        $request('POST', 'channel', ['code' => $otherChannel, 'name' => 'Other channel', 'sender' => 'smsIdgtl'], 201);
        $other = $request('POST', 'sending', [
            'channel' => $otherChannel, 'uuid' => $providerId, 'content' => 'Synthetic collision',
        ], 201)['data'];
        DB::table('sendings')->where('id', $other['id'])->update(['status' => 3]);
        $request('PATCH', 'sending/status', ['uuid' => $providerId, 'status' => 'delivered'], 409);
        $request('PATCH', 'sending/status', ['uuid' => $providerId, 'channel' => $otherChannel, 'status' => 'delivered'], 200);
        if ((int) DB::table('sendings')->where('id', $other['id'])->value('status') !== 7) {
            throw new RuntimeException('A channel-scoped callback did not update its sending.');
        }
        $scoped = ['uuid' => $providerId, 'channel' => $otherChannel];
        $request('PATCH', 'sending/status', [$scoped + ['status' => 8], ['uuid' => $providerId, 'status' => 7]], 409);
        if (DB::table('sendings')->where('id', $other['id'])->value('status') !== 7) {
            throw new RuntimeException('An ambiguous callback did not reject the entire batch.');
        }
        $duplicateBatch = $request('PATCH', 'sending/status', [
            $scoped + ['status' => 7],
            $scoped + ['status' => 3],
            $scoped + ['status' => 8],
            $scoped + ['status' => 7],
            $scoped + ['status' => 8],
        ], 200)['data'];
        if (array_column($duplicateBatch, 'status') !== [7, 8, 8]
            || DB::table('sendings')->where('id', $other['id'])->value('status') !== 8) {
            throw new RuntimeException('Repeated callbacks were not applied in input order.');
        }

        foreach ([4, 5, 6] as $failure) {
            $parent = $request('POST', 'message', ['subject' => 'Status rollup'], 200)['data'][0];
            DB::table('messages')->where('id', $parent['id'])->update(['status' => 1]);
            $child = ['message' => $parent['id'], 'content' => 'Multi-channel notification'];
            $first = $request('POST', 'sending', $child + ['channel' => $channel], 201)['data'];
            $failureUuid = (string) Str::uuid();
            $second = $request('POST', 'sending', $child + ['channel' => $otherChannel, 'uuid' => $failureUuid], 201)['data'];
            DB::table('sendings')->whereIn('id', [$first['id'], $second['id']])->update(['status' => 3]);
            $request('PATCH', 'sending/status', ['uuid' => $failureUuid, 'status' => $failure], 200);
            if ((int) DB::table('messages')->where('id', $parent['id'])->value('status') !== $failure) {
                throw new RuntimeException('A successful channel masked a failed or expired channel.');
            }
        }
        $request('DELETE', 'channel/'.$channel, [], 204);
        $request('GET', 'sending/'.$sending['id'], [], 404);
    },
    'batched callbacks write sendings and messages in two updates' => function () use ($request, $createChannel): void {
        $channel = $createChannel();
        DB::insert(<<<'SQL'
            INSERT INTO messages (uuid, "eventId", expire, "mustBeSend", "recipientId", status, channels, tags, metadata, "createdAt", "updatedAt")
            SELECT gen_random_uuid(), 'callback-batch-' || n, now() + interval '1 day', now(),
                   '', 1, '[]', '[]', '{}', now(), now()
            FROM generate_series(1, 500) AS n
            SQL);
        DB::insert(<<<'SQL'
            INSERT INTO sendings (uuid, message, channel, expire, "mustBeSend", status, "createdAt", "updatedAt")
            SELECT CASE WHEN x.target THEN split_part(m."eventId", '-', 3)
                        ELSE '0' || split_part(m."eventId", '-', 3) END,
                   m.id, ?, m.expire, m."mustBeSend",
                   CASE WHEN x.target THEN 3 ELSE (split_part(m."eventId", '-', 3)::int % 3) * 2 END,
                   now(), now()
            FROM messages AS m CROSS JOIN (VALUES (true), (false)) AS x(target)
            WHERE m."eventId" LIKE 'callback-batch-%'
            SQL, [$channel]);
        DB::insert(<<<'SQL'
            INSERT INTO sendings (uuid, message, channel, expire, "mustBeSend", status, "createdAt", "updatedAt")
            SELECT 'still-pending', id, ?, expire, "mustBeSend", 0, now(), now()
            FROM messages WHERE "eventId" = 'callback-batch-3'
            SQL, [$channel]);
        $payload = [];
        foreach (range(1, 500) as $index) {
            $payload[] = ['uuid' => (string) $index, 'status' => 7];
            // A leading zero is part of the provider ID, not a numeric normalization.
            $payload[] = ['uuid' => '0'.$index, 'status' => $index % 3 === 1 ? 3 : 4]
                + ($index % 2 === 0 ? ['channel' => $channel] : []);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $response = $request('PATCH', 'sending/status', $payload, 200);
            $writes = collect(DB::getQueryLog())->pluck('query')->filter(
                fn (string $sql) => str_starts_with(strtolower($sql), 'update '),
            )->values();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $statuses = DB::table('messages')->where('eventId', 'like', 'callback-batch-%')->pluck('status', 'eventId');
        if ($response['data'] !== array_map(fn (array $item) => ['uuid' => $item['uuid'], 'status' => $item['status']], $payload)
            || $writes->count() !== 2 || ! str_starts_with($writes[0], 'UPDATE sendings AS s')
            || ! str_starts_with($writes[1], 'update "messages"') || $statuses->count() !== 500) {
            throw new RuntimeException('The callback must update all affected messages together.');
        }
        $request('PATCH', 'sending/status', [...$payload, $payload[0]], 400);
        foreach (range(1, 500) as $index) {
            $expected = match (true) {
                $index === 3 => 1,
                $index % 3 === 1 => 3,
                default => 4,
            };
            if ($statuses['callback-batch-'.$index] !== $expected) {
                throw new RuntimeException('A message did not reflect the final status of its channels.');
            }
        }
    },
    'explicit, default and deleted scenario routing' => function () use ($app, $request, $createChannel): void {
        $channel = $createChannel();
        $otherChannel = $createChannel();
        $phone = Str::random(16);
        $request('POST', 'recipient', ['phone' => $phone], 201);
        $scenario = $request('POST', 'scenario', [
            'messageType' => 2,
            'byDefault' => true,
            'channelsFirstOf' => [[
                'channel' => $otherChannel, 'isActive' => true, 'fieldsConditions' => ['equalTrue' => ['isSubscribe']],
            ]],
        ], 201)['data'];
        $direct = ['recipientId' => $phone, 'channels' => [$channel], 'mustBeSend' => '2099-01-01T00:00:00Z'];
        $explicit = $request('POST', 'message', $direct + ['scenario_id' => $scenario['uuid']], 200)['data'][0];
        $byType = $request('POST', 'message', $direct + ['type' => 2], 200)['data'][0];
        $noScenario = $request('POST', 'message', $direct + ['type' => 0], 200)['data'][0];
        $disabled = $request('POST', 'scenario', ['messageType' => 0, 'isDeleted' => true], 201)['data'];
        $explicitDisabled = $request('POST', 'message', $direct + ['scenario_id' => $disabled['uuid']], 200)['data'][0];
        $removed = $request('POST', 'scenario', ['messageType' => 0], 201)['data'];
        $explicitRemoved = $request('POST', 'message', $direct + ['scenario_id' => $removed['uuid']], 200)['data'][0];
        $request('DELETE', 'scenario/'.$removed['id'], [], 204);
        $app->make(PrepareSendings::class)->batch(100);
        foreach ([$explicit['id'], $byType['id'], $explicitDisabled['id'], $explicitRemoved['id']] as $blocked) {
            $prepared = $request('GET', 'message/'.$blocked, [], 200)['data'];
            if ((int) $prepared['status'] !== 4 || $prepared['channels'] !== [] || DB::table('sendings')->where('message', $blocked)->exists()) {
                throw new RuntimeException('The worker used a direct channel despite a selected scenario.');
            }
        }
        if (DB::table('messages')->where('id', $explicitDisabled['id'])->value('scenario_id') !== $disabled['uuid']) {
            throw new RuntimeException('The worker lost the explicitly selected disabled scenario.');
        }
        if (DB::table('messages')->where('id', $explicitRemoved['id'])->value('scenario_id') !== $removed['uuid']) {
            throw new RuntimeException('Physical deletion removed the explicit scenario UUID from a pending notification.');
        }
        $request('PATCH', 'message/'.$explicitRemoved['id'], ['scenario_id' => $removed['uuid'], 'subject' => 'Still editable'], 200);
        $request('PATCH', 'message/'.$noScenario['id'], ['scenario_id' => $removed['uuid']], 400);
        if (! DB::table('sendings')->where('message', $noScenario['id'])->where('channel', $channel)->exists()) {
            throw new RuntimeException('The worker ignored direct channels on a message without a scenario.');
        }
    },
    'batch preparation update count and routing' => function () use ($app, $request, $createChannel): void {
        $channel = $createChannel();
        $phone = Str::random(16);
        $request('POST', 'recipient', ['phone' => $phone], 201);
        DB::insert(<<<'SQL'
            INSERT INTO messages (uuid, "eventId", expire, "mustBeSend", "recipientId", type,
                                  channels, tags, metadata, "createdAt", "updatedAt")
            SELECT gen_random_uuid(), 'batch-regression-' || n, now() + interval '1 day', now(), ?, NULL,
                   CAST(? AS jsonb), '[]', '{}', now(), now()
            FROM generate_series(1, 501) AS n
            SQL, [$phone, json_encode([$channel], JSON_THROW_ON_ERROR)]);
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $processed = $app->make(PrepareSendings::class)->batch(501);
            $updates = collect(DB::getQueryLog())->filter(
                fn (array $query) => str_starts_with(strtolower($query['query']), 'update messages'),
            )->count();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        if ($processed !== 501 || $updates !== 2
            || DB::table('messages')->where('recipientId', $phone)->where('status', 1)->whereNull('type')->count() !== 501
            || DB::table('sendings')->where('channel', $channel)->where('destination', $phone)->count() !== 501) {
            throw new RuntimeException('Batch preparation did not route 501 messages in two updates.');
        }
    },
    'metrics include empty channels and every status' => function () use ($kernel, $request, $createChannel): void {
        $active = $createChannel();
        $empty = $createChannel();
        $message = $request('POST', 'message', ['subject' => 'Metrics fixture'], 200)['data'][0];
        $sending = $request('POST', 'sending', ['channel' => $active, 'content' => 'Metrics fixture'], 201)['data'];
        DB::table('messages')->where('id', $message['id'])->update(['status' => 7]);
        DB::table('sendings')->where('id', $sending['id'])->update(['status' => 3]);
        $response = $kernel->handle(Request::create('/metrics'));
        $body = $response->getContent();
        foreach ([
            'homer_sendings_statistics_channel{Channel="'.$active.'"} 1',
            'homer_sendings_statistics_channel{Channel="'.$empty.'"} 0',
            'homer_sendings_statistics_status{Status="SENT"} 1',
            'homer_messages_statistics{Status="DELIVERED"} 1',
            'homer_messages_statistics{Status="ARCHIVED"} 0',
        ] as $sample) {
            if ($response->getStatusCode() !== 200 || ! str_contains($body, $sample."\n")) {
                throw new RuntimeException('Unexpected Prometheus metric: '.$sample);
            }
        }
    },
    'worker cancellation and stale claims' => function () use ($app, $request, $createChannel): void {
        $channel = $createChannel();
        $message = $request('POST', 'message', ['recipientId' => 'synthetic-recipient'], 200)['data'][0];
        $request('POST', 'sending', [
            'channel' => $channel, 'message' => $message['id'],
            'destination' => 'synthetic-recipient', 'content' => 'Pending cancellation',
        ], 201);
        $request('PATCH', 'message/'.$message['id'], ['isDeleted' => true], 200);
        if ($app->make(App\Notifications\DeliverSendings::class)->batch(1) !== 0) {
            throw new RuntimeException('The worker claimed a child of a soft-deleted message.');
        }

        $queued = ['channel' => $channel, 'destination' => 'synthetic-recipient', 'content' => 'Queued without a claim'];
        $stale = $request('POST', 'sending', $queued, 201)['data'];
        $recent = $request('POST', 'sending', $queued, 201)['data'];
        DB::table('sendings')->whereIn('id', [$stale['id'], $recent['id']])->update(['status' => 2]);
        DB::table('sendings')->where('id', $stale['id'])->update(['updatedAt' => now()->subMinutes(10)]);
        $app->make(App\Notifications\DeliverSendings::class)->batch(10);
        $settled = DB::table('sendings')->where('id', $stale['id'])->first();
        if ((int) $settled->status !== 5 || $settled->deliveryError !== 'delivery_outcome_unknown' || (int) $settled->attempts !== 0
            || (int) DB::table('sendings')->where('id', $recent['id'])->value('status') !== 2) {
            throw new RuntimeException('The worker did not settle only the stale sending without repeating delivery.');
        }
    },
    'HTTP provider request format and address checks' => function () use ($app): void {
        $sms = 'https://public.provider.invalid/sms';
        $push = 'https://public.provider.invalid/push';
        $retail = 'https://public.provider.invalid/retail';
        config(['homer.provider_http_hosts' => [
            'public.provider.invalid', 'private.provider.invalid', 'mixed.provider.invalid', 'metadata.provider.invalid',
            'ipv6.provider.invalid', 'multicast.provider.invalid',
        ]]);
        Http::preventStrayRequests();
        Http::fake([
            $sms => Http::response(['items' => [['messageUuid' => 'synthetic-sms']]]),
            $push => Http::response(['results' => [['message_id' => 'synthetic-push']]]),
            $retail.'/*' => Http::response([], 200),
        ]);
        $providers = $app->make(Providers::class);
        $sample = (object) ['destination' => 'test-address', 'subject' => 'Subject', 'content' => 'Synthetic body'];
        foreach (['smsIdgtl' => $sms, 'pushFirebase' => $push] as $sender => $url) {
            $providers->send($sender, ['url' => $url], $sample, null);
            $providers->send($sender, ['url' => $url, 'method' => 'PUT'], $sample, null);
            if (Http::recorded(fn (ProviderRequest $call) => $call->url() === $url && $call->method() === 'POST'
                && str_contains($call->body(), 'Synthetic body'))->count() !== 1
                || Http::recorded(fn (ProviderRequest $call) => $call->url() === $url && $call->method() === 'PUT')->count() !== 1) {
                throw new RuntimeException('A provider did not send JSON with POST by default or ignored an explicit method.');
            }
        }
        $mixedCaseId = $providers->send('smsIdgtl', ['url' => 'https://PUBLIC.Provider.INVALID./sms'], $sample, null);
        if ($mixedCaseId !== 'synthetic-sms'
            || Http::recorded(fn (ProviderRequest $call) => $call->url() === $sms && $call->method() === 'POST')->count() !== 2) {
            throw new RuntimeException('A mixed-case provider URL did not use the canonical host.');
        }
        $providers->send('emailRetailRocket', ['url' => $retail.'?mode=synthetic', 'apikey' => 'synthetic-key'], $sample,
            (object) ['metadata' => '{"campaignId":42,"synthetic":true}']);
        $retailCalls = Http::recorded(function (ProviderRequest $call) use ($retail): bool {
            parse_str((string) parse_url($call->url(), PHP_URL_QUERY), $query);

            return strtok($call->url(), '?') === $retail.'/42' && $call->method() === 'GET'
                && $query == ['mode' => 'synthetic', 'apikey' => 'synthetic-key', 'email' => 'test-address']
                && json_decode($call->body(), true) === ['campaignId' => 42, 'synthetic' => true];
        });
        if ($retailCalls->count() !== 1) {
            throw new RuntimeException('Retail Rocket did not receive the expected path, query and JSON body.');
        }

        $options = $app->make(ProviderHttpEndpoint::class)->options('public.provider.invalid', 443);
        $mixedCaseOptions = $app->make(ProviderHttpEndpoint::class)->options('PUBLIC.Provider.INVALID.', 443);
        if ($options['curl'][CURLOPT_RESOLVE] !== ['public.provider.invalid:443:93.184.216.34']
            || $mixedCaseOptions !== $options
            || ! $options['curl'][CURLOPT_FRESH_CONNECT] || ! $options['curl'][CURLOPT_FORBID_REUSE]
            || $options['curl'][CURLOPT_PROXY] !== '') {
            throw new RuntimeException('Provider connection was not pinned to the validated address.');
        }
        foreach ([
            [['url' => 'http://127.0.0.1/'], 'provider_host_not_allowed'],
            [['url' => 'http://different.provider.invalid/'], 'provider_host_not_allowed'],
            [['url' => 'http://private.provider.invalid/'], 'provider_address_not_allowed'],
            [['url' => 'http://mixed.provider.invalid/'], 'provider_address_not_allowed'],
            [['url' => 'http://metadata.provider.invalid/'], 'provider_address_not_allowed'],
            [['url' => 'http://ipv6.provider.invalid/'], 'provider_address_not_allowed'],
            [['url' => 'http://multicast.provider.invalid/'], 'provider_address_not_allowed'],
            [['url' => $sms, 'headers' => ['hOsT' => 'different.provider.invalid']], 'invalid_http_configuration'],
        ] as [$config, $code]) {
            try {
                $providers->send('smsIdgtl', $config, $sample, null);
                throw new RuntimeException('An unsafe provider request reached the HTTP client.');
            } catch (DeliveryFailure $failure) {
                if ($failure->getMessage() !== $code) {
                    throw $failure;
                }
            }
        }
    },
    'SMTP relay ports and pinned DNS addresses' => function () use ($app): void {
        config(['homer.provider_smtp_relays' => [
            'private.provider.invalid:587' => null,
            'mixed.provider.invalid:587' => '10.0.0.1',
            'metadata.provider.invalid:587' => '169.254.169.254',
            'ipv6.provider.invalid:587' => 'fd00:ec2::254',
        ]]);
        $providers = $app->make(Providers::class);
        $sample = (object) ['destination' => 'recipient@example.invalid', 'subject' => 'Test', 'content' => 'Test'];
        foreach ([
            ['other.provider.invalid', 587, 'smtp_relay_not_allowed'],
            ['private.provider.invalid', 465, 'smtp_relay_not_allowed'],
            ['private.provider.invalid', 587, 'provider_address_not_allowed'],
            ['mixed.provider.invalid', 587, 'provider_address_not_allowed'],
            ['metadata.provider.invalid', 587, 'provider_address_not_allowed'],
            ['ipv6.provider.invalid', 587, 'provider_address_not_allowed'],
        ] as [$host, $port, $expected]) {
            try {
                $providers->send('builtinEmail', ['server' => $host, 'port' => $port, 'email' => 'sender@example.invalid'], $sample, null);
                throw new RuntimeException('Unexpected SMTP connection.');
            } catch (DeliveryFailure $failure) {
                if ($failure->getMessage() !== $expected) {
                    throw $failure;
                }
            }
        }
        if ($app->make(ProviderDns::class)->addresses('private.provider.invalid', '10.0.0.1') !== ['10.0.0.1']) {
            throw new RuntimeException('The trusted private relay address was not pinned.');
        }
        foreach (['', '10.0.0.typo'] as $pin) {
            try {
                $app->make(ProviderDns::class)->addresses('public.provider.invalid', $pin);
                throw new RuntimeException('An invalid private address pin fell back to public DNS.');
            } catch (DeliveryFailure $failure) {
                if ($failure->getMessage() !== 'provider_address_not_allowed') {
                    throw $failure;
                }
            }
        }
    },
    'outgoing Notices brokers are restricted to approved endpoints' => function () use ($app): void {
        $previousUrl = config('homer.rabbitmq_url');
        config([
            'homer.rabbitmq_url' => 'amqps://private.provider.invalid',
            'homer.provider_amqp_brokers' => [
                'amqp://public.provider.invalid:5672' => null,
                'amqps://mixed.provider.invalid:5671' => '10.0.0.1',
                'amqps://metadata.provider.invalid:5671' => null,
            ],
        ]);
        try {
            $endpoints = $app->make(ProviderAmqpEndpoint::class);
            if ($endpoints->addresses('amqps', 'private.provider.invalid', 5671) !== ['10.0.0.1']
                || $endpoints->addresses('amqp', 'public.provider.invalid', 5672) !== ['93.184.216.34']) {
                throw new RuntimeException('An approved AMQP endpoint was not pinned to its checked DNS address.');
            }
            $providers = $app->make(Providers::class);
            $sample = (object) ['destination' => 'synthetic', 'subject' => 'Test', 'content' => 'Test'];
            foreach ([
                ['amqp://private.provider.invalid:5671', 'amqp_broker_not_allowed'],
                ['amqps://private.provider.invalid:5672', 'amqp_broker_not_allowed'],
                ['amqps://127.0.0.1:5671', 'amqp_broker_not_allowed'],
                ['amqps://mixed.provider.invalid', 'provider_address_not_allowed'],
                ['amqps://metadata.provider.invalid', 'provider_address_not_allowed'],
            ] as [$url, $expected]) {
                try {
                    $providers->send('notices', ['url' => $url, 'exchange' => 'synthetic'], $sample, null);
                    throw new RuntimeException('Unexpected AMQP connection.');
                } catch (DeliveryFailure $failure) {
                    if ($failure->getMessage() !== $expected) {
                        throw $failure;
                    }
                }
            }
        } finally {
            config(['homer.rabbitmq_url' => $previousUrl, 'homer.provider_amqp_brokers' => []]);
        }
    },
    'expiry preview and cleanup' => function () use ($request, $createChannel): void {
        $channel = $createChannel();
        $subjects = [
            'expired-oldest' => now()->subDays(3),
            'expired-next' => now()->subDays(2),
            'expired-claimed' => now()->subDays(4),
            'still-current' => now()->addDay(),
        ];
        $purgeRows = [];
        foreach ($subjects as $subject => $expire) {
            $parent = $request('POST', 'message', ['subject' => $subject, 'expire' => $expire->toIso8601String()], 200)['data'][0];
            $child = $request('POST', 'sending', [
                'channel' => $channel, 'message' => $parent['id'], 'content' => 'Cleanup regression',
                'expire' => $expire->toIso8601String(),
            ], 201)['data'];
            $purgeRows[$subject] = ['message' => $parent['id'], 'sending' => $child['id']];
        }
        DB::table('sendings')->where('id', $purgeRows['expired-claimed']['sending'])->update(['status' => 2, 'claimedAt' => now()]);

        if (Artisan::call('homer:purge-expired', ['--dry-run' => true, '--limit' => 0]) !== 1
            || Artisan::call('homer:purge-expired', ['--dry-run' => true, '--limit' => 10]) !== 0
            || trim(Artisan::output()) !== 'Eligible messages in this batch: 2'
            || Artisan::call('homer:purge-expired', ['--dry-run' => true, '--limit' => 1]) !== 0
            || trim(Artisan::output()) !== 'Eligible messages in this batch: 1') {
            throw new RuntimeException('The cleanup preview ignored eligibility or the batch limit.');
        }
        foreach ($purgeRows as $ids) {
            foreach ($ids as $resource => $id) {
                $request('GET', $resource.'/'.$id, [], 200);
            }
        }

        if (Artisan::call('homer:purge-expired', ['--limit' => 1]) !== 0 || trim(Artisan::output()) !== 'Purged messages: 1') {
            throw new RuntimeException('Cleanup did not delete exactly one expired message.');
        }
        foreach ($purgeRows as $subject => $ids) {
            foreach ($ids as $resource => $id) {
                $request('GET', $resource.'/'.$id, [], $subject === 'expired-oldest' ? 404 : 200);
            }
        }
        DB::table('sendings')->where('id', $purgeRows['expired-claimed']['sending'])->update(['status' => 6, 'claimedAt' => null]);
    },
];

$failed = false;
foreach ($cases as $name => $test) {
    DB::beginTransaction();
    try {
        $test();
        echo "PASS $name".PHP_EOL;
    } catch (Throwable $error) {
        $failed = true;
        fwrite(STDERR, "FAIL $name - ".$error->getMessage().PHP_EOL);
    } finally {
        DB::rollBack();
    }
}

exit($failed ? 1 : 0);
