<?php

namespace App\Notifications;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

final class DeliverSendings
{
    private const MAX_ATTEMPTS = 4;

    public function __construct(private readonly Providers $providers) {}

    public function batch(int $limit): int
    {
        $this->finishStale($limit);
        for ($processed = 0; $processed < $limit; $processed++) {
            $sending = $this->claim();
            if ($sending === null) {
                break;
            }

            $channel = Resource::Channel->query()->where('code', $sending->channel)->where('isDeleted', false)->first();
            if ($channel === null) {
                $this->finish($sending, ['status' => 4, 'deliveryError' => 'channel_disabled']);
                continue;
            }

            try {
                $config = json_decode(Crypt::decryptString($channel->config), true, flags: JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                $this->finish($sending, ['status' => 4, 'deliveryError' => 'channel_configuration_invalid']);
                continue;
            }
            $message = Resource::Message->query()->where('id', $sending->message)->first();
            try {
                $uuid = $this->providers->send($channel->sender, $config, $sending, $message);
                $result = ['uuid' => $uuid, 'status' => 3, 'deliveryError' => null];
            } catch (DeliveryFailure $error) {
                $retry = $error->retry && $sending->attempts < self::MAX_ATTEMPTS;
                $result = [
                    'status' => $retry ? 1 : ($error->uncertain ? 5 : 4),
                    'retryAt' => $retry ? now()->addSeconds(15 * (2 ** ($sending->attempts - 1))) : null,
                    'deliveryError' => $error->getMessage(),
                ];
            } catch (Throwable $error) {
                logger()->warning('Delivery outcome unknown', ['sending_id' => $sending->id, 'error_class' => $error::class]);
                // The provider may have accepted the request before the connection failed.
                $result = ['status' => 5, 'deliveryError' => 'delivery_outcome_unknown'];
            }

            $this->finish($sending, $result);
        }

        return $processed;
    }

    private function claim(): ?object
    {
        $now = now();

        return DB::selectOne(<<<'SQL'
            UPDATE sendings
            SET status = 2, attempts = attempts + 1, "claimedAt" = ?, "retryAt" = NULL, "updatedAt" = ?
            WHERE id = (
                SELECT id FROM sendings
                WHERE status IN (0, 1) AND NOT "isDeleted" AND destination <> ''
                    AND expire > ? AND "mustBeSend" <= ?
                    AND ("retryAt" IS NULL OR "retryAt" <= ?)
                ORDER BY "mustBeSend", id
                LIMIT 1 FOR UPDATE SKIP LOCKED
            )
            RETURNING *
            SQL, [$now, $now, $now, $now, $now], false);
    }

    private function finishStale(int $limit): void
    {
        $now = now();
        $stale = Resource::Sending->query()->where(function ($query) use ($now): void {
            $query->where(fn ($ready) => $ready->whereIn('status', [0, 1])->where('expire', '<=', $now))
                ->orWhere(fn ($queued) => $queued->where('status', 2)->where('claimedAt', '<=', $now->copy()->subMinutes(5)));
        })->orderBy('id')->limit($limit)->get();
        foreach ($stale as $sending) {
            $this->finish($sending, $sending->status === 2
                ? ['status' => 5, 'deliveryError' => 'delivery_outcome_unknown']
                : ['status' => 6]);
        }
    }

    private function finish(object $sending, array $changes): void
    {
        DB::transaction(function () use ($sending, $changes): void {
            // Serialize aggregate updates for all sendings belonging to one message.
            $parent = Resource::Message->query()->where('id', $sending->message)->lockForUpdate()->first();
            $updated = Resource::Sending->query()->where('id', $sending->id)->where('attempts', $sending->attempts)
                ->whereIn('status', $changes['status'] === 6 ? [0, 1] : [2])
                ->update([...$changes, 'claimedAt' => null, 'updatedAt' => now()]);
            if ($updated && $parent !== null) {
                self::refreshMessage($parent->id);
            }
            if ($updated && filled($changes['deliveryError'] ?? null)) {
                Resource::EventLog->query()->insert([
                    'initiator' => 'homer:deliver',
                    'timestamp' => now(),
                    'level' => $changes['status'] === 1 ? 30 : 40,
                    'event' => $changes['deliveryError'],
                    'tracing' => 'sending_id='.$sending->id,
                ]);
            }
        }, 3);
    }

    public static function refreshMessage(int $id): void
    {
        $status = Resource::Sending->query()->where('message', $id)->min('status');
        if ($status !== null) {
            // A prepared message remains READY until all its sendings reach the queue.
            Resource::Message->query()->where('id', $id)->update(['status' => max(1, $status), 'updatedAt' => now()]);
        }
    }
}
