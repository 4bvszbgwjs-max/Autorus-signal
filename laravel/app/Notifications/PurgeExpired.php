<?php

namespace App\Notifications;

use Illuminate\Support\Facades\DB;

final class PurgeExpired
{
    public function batch(int $limit): int
    {
        return DB::transaction(function () use ($limit): int {
            $ids = Resource::Message->query()->where('expire', '<=', now())
                ->whereNotExists(fn ($query) => $query->from('sendings')
                    ->whereColumn('sendings.message', 'messages.id')->whereNotNull('claimedAt'))
                ->orderBy('expire')->orderBy('id')->limit($limit)
                ->lock('for update skip locked')->pluck('id');
            if ($ids->isEmpty()) {
                return 0;
            }

            $sendings = Resource::Sending->query()->whereIn('message', $ids);
            $claimed = (clone $sendings)->orderBy('id')->lockForUpdate()->get(['message', 'claimedAt'])
                ->whereNotNull('claimedAt')->pluck('message');
            $eligible = $ids->diff($claimed)->values();
            Resource::Sending->query()->whereIn('message', $eligible)->delete();

            return Resource::Message->query()->whereIn('id', $eligible)->delete();
        }, 3);
    }
}
