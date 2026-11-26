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
            if ($message?->isDeleted) {
                $this->finish($sending, ['status' => 4, 'deliveryError' => 'message_deleted']);
                continue;
            }

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
                SELECT pending.id FROM sendings AS pending
                WHERE pending.status IN (0, 1) AND NOT pending."isDeleted" AND pending.destination <> ''
                    AND pending.expire > ? AND pending."mustBeSend" <= ?
                    AND (pending."retryAt" IS NULL OR pending."retryAt" <= ?)
                    AND NOT EXISTS (
                        SELECT 1 FROM messages
                        WHERE messages.id = pending.message AND messages."isDeleted"
                    )
                ORDER BY pending."mustBeSend", pending.id
                LIMIT 1 FOR UPDATE OF pending SKIP LOCKED
            )
            RETURNING *
            SQL, [$now, $now, $now, $now, $now], false);
    }

    private function finishStale(int $limit): void
    {
        $now = now();
        $cutoff = $now->copy()->subMinutes(5);
        $stale = Resource::Sending->query()->where(function ($query) use ($now, $cutoff): void {
            $query->where(fn ($ready) => $ready->whereIn('status', [0, 1])->where('expire', '<=', $now))
                ->orWhere(fn ($queued) => $queued->where('status', 2)->where(fn ($claim) => $claim
                    ->where('claimedAt', '<=', $cutoff)
                    ->orWhere(fn ($unclaimed) => $unclaimed->whereNull('claimedAt')->where('updatedAt', '<=', $cutoff))));
        })->orderBy('id')->limit($limit)->get();
        foreach ($stale as $sending) {
            $this->finish($sending, $sending->status === 2
                ? ['status' => 5, 'deliveryError' => 'delivery_outcome_unknown']
                : ['status' => 6], stale: true);
        }
    }

    private function finish(object $sending, array $changes, bool $stale = false): void
    {
        DB::transaction(function () use ($sending, $changes, $stale): void {
            // Serialize aggregate updates for all sendings belonging to one message.
            $parentId = $sending->message === null
                ? null
                : Resource::Message->query()->where('id', $sending->message)->lockForUpdate()->value('id');
            $expired = $changes['status'] === 6;
            $query = Resource::Sending->query()->where('id', $sending->id)->where('attempts', $sending->attempts)
                ->whereIn('status', $expired ? [0, 1] : [2]);
            if ($stale) {
                $query->where($expired ? 'expire' : 'claimedAt', $expired ? $sending->expire : $sending->claimedAt)
                    ->where('updatedAt', $sending->updatedAt);
            }
            $updated = $query->update([...$changes, 'claimedAt' => null, 'updatedAt' => now()]);
            if ($updated && $parentId !== null) {
                self::refreshMessages([$parentId]);
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

    public static function refreshMessages(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        // Callers lock the parent rows before changing or aggregating their sendings.
        $summary = Resource::Sending->query()->select('message')->whereIn('message', $ids)
            ->selectRaw('min(status) as earliest, min(status) filter (where status between 4 and 6) as failure')
            ->groupBy('message');
        Resource::Message->query()->joinSub($summary, 'summary', 'messages.id', '=', 'summary.message')
            ->updateFrom([
                'status' => DB::raw(<<<'SQL'
                    CASE WHEN summary.earliest < 3 THEN greatest(1, summary.earliest)
                         ELSE coalesce(summary.failure, summary.earliest) END
                    SQL),
                'updatedAt' => now(),
            ]);
    }
}
