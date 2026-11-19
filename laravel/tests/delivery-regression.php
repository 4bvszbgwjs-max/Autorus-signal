<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
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
config(['homer.api_token' => $token, 'app.key' => 'base64:'.base64_encode(random_bytes(32))]);

$request = function (string $method, string $path, array $payload, int $expected) use ($kernel, $token): array {
    $request = Request::create('/api/v1/'.$path, $method, server: [
        'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/json',
    ], content: json_encode($payload, JSON_THROW_ON_ERROR));
    $response = $kernel->handle($request);
    if ($response->getStatusCode() !== $expected) {
        throw new RuntimeException("$method $path expected HTTP $expected, got ".$response->getStatusCode());
    }

    return $response->getContent() === '' ? [] : json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
};

$passed = false;
DB::beginTransaction();
try {
    $channel = 'review-'.Str::lower(Str::random(10));
    $providerId = (string) Str::uuid();
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
    $message = $request('POST', 'message', ['recipientId' => 'synthetic-recipient'], 200)['data'][0];
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
    $request('PATCH', 'sending/status', ['uuid' => $providerId, 'status' => 'delivered'], 200);
    $request('PATCH', 'sending/status', ['uuid' => $providerId, 'status' => 'sent'], 200);
    if ((int) (clone $row)->value('status') !== 7 || (int) DB::table('messages')->where('id', $message['id'])->value('status') !== 7) {
        throw new RuntimeException('The late callback regressed the sending or its parent.');
    }

    $otherChannel = $channel.'-other';
    $request('POST', 'channel', ['code' => $otherChannel, 'name' => 'Other channel', 'sender' => 'smsIdgtl'], 201);
    $other = $request('POST', 'sending', [
        'channel' => $otherChannel, 'uuid' => $providerId, 'content' => 'Synthetic collision', 'status' => 3,
    ], 201)['data'];
    $request('PATCH', 'sending/status', ['uuid' => $providerId, 'status' => 'delivered'], 409);
    $request('PATCH', 'sending/status', ['uuid' => $providerId, 'channel' => $otherChannel, 'status' => 'delivered'], 200);
    if ((int) DB::table('sendings')->where('id', $other['id'])->value('status') !== 7) {
        throw new RuntimeException('A channel-scoped callback did not update its sending.');
    }

    $request('POST', 'sending', [
        'channel' => $channel, 'message' => $message['id'],
        'destination' => 'synthetic-recipient', 'content' => 'Pending cancellation',
    ], 201);
    $request('PATCH', 'message/'.$message['id'], ['isDeleted' => true], 200);
    if ($app->make(App\Notifications\DeliverSendings::class)->batch(1) !== 0) {
        throw new RuntimeException('The worker claimed a child of a soft-deleted message.');
    }

    $request('DELETE', 'channel/'.$channel, [], 204);
    $request('GET', 'sending/'.$sending['id'], [], 404);
    $passed = true;
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage().PHP_EOL);
} finally {
    DB::rollBack();
}

if (! $passed) {
    exit(1);
}

echo "Delivery callbacks, channel routes and cascade checks passed.\n";
