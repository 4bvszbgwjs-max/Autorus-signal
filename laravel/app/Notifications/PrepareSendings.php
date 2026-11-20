<?php

namespace App\Notifications;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class PrepareSendings
{
    private const NEW = 0;
    private const READY = 1;
    private const UNSENT = 4;
    private const EXPIRED = 6;

    public function __construct(private readonly ScenarioRouting $routing) {}

    public function batch(int $limit): int
    {
        return DB::transaction(function () use ($limit): int {
            $now = now();
            $messages = Resource::Message->query()
                ->where('status', self::NEW)->where('isDeleted', false)
                ->orderBy('id')->limit($limit)->lock('for update skip locked')->get();
            if ($messages->isEmpty()) {
                return 0;
            }

            [$expired, $active] = $messages->partition(fn ($message) => Carbon::parse($message->expire)->lte($now));
            if ($expired->isNotEmpty()) {
                Resource::Message->query()->whereIn('id', $expired->pluck('id'))
                    ->update(['status' => self::EXPIRED, 'updatedAt' => $now]);
            }
            if ($active->isEmpty()) {
                return $messages->count();
            }

            $recipients = Resource::Recipient->query()
                ->whereIn('phone', $active->pluck('recipientId')->unique()->values())
                ->where('isDeleted', false)->get()->keyBy('phone');
            $explicitIds = $active->pluck('scenario_id')->filter()->unique()->values();
            $types = $active->pluck('type')->filter(fn ($type) => $type !== null)->unique()->values();
            $scenarios = Resource::Scenario->query()->where('isDeleted', false)
                ->where(fn ($query) => $query->whereIn('uuid', $explicitIds)
                    ->orWhere(fn ($defaults) => $defaults->where('byDefault', true)->whereIn('messageType', $types)))
                ->orderBy('id')->get();
            $byUuid = $scenarios->keyBy('uuid');
            $defaults = $scenarios->where('byDefault', true)->whereNotNull('messageType')
                ->unique('messageType')->keyBy('messageType');

            $scenarioRules = [];
            $codes = [];
            foreach ($scenarios as $scenario) {
                $first = json_decode($scenario->channelsFirstOf, true, flags: JSON_THROW_ON_ERROR);
                $every = json_decode($scenario->channelsEveryOf, true, flags: JSON_THROW_ON_ERROR);
                $scenarioRules[$scenario->uuid] = [$first, $every];
                foreach ([...$first, ...$every] as $rule) {
                    if ($rule['isActive']) {
                        $codes[$rule['channel']] = true;
                    }
                }
            }
            $requestedChannels = [];
            foreach ($active as $message) {
                $channels = json_decode($message->channels, true, flags: JSON_THROW_ON_ERROR);
                $requestedChannels[$message->id] = $channels;
                foreach ($channels as $code) {
                    $codes[$code] = true;
                }
            }
            $available = new Collection;
            foreach (array_chunk(array_keys($codes), 1000) as $chunk) {
                $available = $available->union(Resource::Channel->query()->whereIn('code', $chunk)
                    ->where('isDeleted', false)->pluck('sender', 'code'));
            }

            $sendings = [];
            foreach ($active as $message) {
                $recipient = $recipients->get($message->recipientId);
                $explicitScenario = $message->scenario_id;
                $scenario = $explicitScenario === null ? $defaults->get($message->type) : $byUuid->get($explicitScenario);
                [$first, $every] = $scenarioRules[$scenario?->uuid] ?? [[], []];
                $rules = $this->routing->selectChannels($first, $every, $recipient, $available);
                $channels = $scenario === null && $explicitScenario === null
                    ? $requestedChannels[$message->id]
                    : array_map('strval', array_keys($rules));
                $metadata = json_decode($message->metadata, true, flags: JSON_THROW_ON_ERROR);
                $sendable = false;

                foreach (array_unique($channels) as $code) {
                    $sender = $available->get($code);
                    if ($sender === null) {
                        continue;
                    }
                    $rule = $rules[$code] ?? [];
                    $destination = $this->routing->destination($metadata, $recipient, $rule, $sender);
                    if ($destination === null) {
                        continue;
                    }
                    $sendable = true;
                    $sendings[] = [
                        'uuid' => '',
                        'message' => $message->id,
                        'channel' => $code,
                        'destination' => $destination,
                        'content' => $message->{$rule['contentField'] ?? 'content'},
                        'subject' => $message->subject,
                        'expire' => $message->expire,
                        'mustBeSend' => $message->mustBeSend,
                        'status' => self::NEW,
                        'createdAt' => $now,
                        'updatedAt' => $now,
                    ];
                    if (count($sendings) === 500) {
                        Resource::Sending->query()->insert($sendings);
                        $sendings = [];
                    }
                }

                Resource::Message->query()->where('id', $message->id)->update([
                    'status' => $sendable ? self::READY : self::UNSENT,
                    'channels' => json_encode($channels, JSON_THROW_ON_ERROR),
                    'scenario_id' => $scenario?->uuid ?? $explicitScenario,
                    'type' => $scenario ? $scenario->messageType : $message->type,
                    'updatedAt' => $now,
                ]);
            }

            if ($sendings !== []) {
                Resource::Sending->query()->insert($sendings);
            }

            return $messages->count();
        }, 3);
    }
}
