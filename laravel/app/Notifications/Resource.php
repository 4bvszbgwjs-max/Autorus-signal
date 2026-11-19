<?php

namespace App\Notifications;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

enum Resource: string
{
    case Channel = 'channel';
    case Message = 'message';
    case Recipient = 'recipient';
    case Scenario = 'scenario';
    case Sending = 'sending';
    case EventLog = 'eventlog';

    public function query(): Builder
    {
        return DB::table($this === self::EventLog ? $this->value : $this->value.'s');
    }

    public function key(): string
    {
        return $this === self::Channel ? 'code' : 'id';
    }

    public function filters(): array
    {
        if ($this === self::EventLog) {
            return ['id', 'initiator', 'level', 'event', 'tracing'];
        }

        return ['id', 'isDeleted', ...match ($this) {
            self::Channel => ['code', 'name', 'sender'],
            self::Message => ['type', 'subject', 'recipientId', 'status', 'uuid', 'eventId'],
            self::Recipient => ['phone', 'email', 'name'],
            self::Scenario => ['messageType', 'byDefault'],
            self::Sending => ['uuid', 'status', 'channel', 'message', 'destination', 'subject'],
        }];
    }

    public function dates(): array
    {
        return match ($this) {
            self::Message, self::Sending => ['createdAt', 'updatedAt', 'expire', 'mustBeSend'],
            self::EventLog => ['timestamp'],
            default => ['createdAt', 'updatedAt'],
        };
    }

    public function serialize(object $row): array
    {
        $data = (array) $row;
        // Preserve the legacy response types without returning stored credentials.
        if ($this === self::Channel) {
            $data['config'] = (object) [];
        }
        if ($this === self::Recipient) {
            $data['applicationFirebaseToken'] = '';
        }
        unset($data['attempts'], $data['claimedAt'], $data['retryAt'], $data['deliveryError']);
        if ($this === self::EventLog) {
            unset($data['level']);
        }

        $jsonFields = match ($this) {
            self::Message => ['channels', 'tags', 'metadata'],
            self::Scenario => ['channelsFirstOf', 'channelsEveryOf'],
            default => [],
        };
        foreach ($jsonFields as $field) {
            $data[$field] = json_decode($data[$field], flags: JSON_THROW_ON_ERROR);
        }
        foreach ($this->dates() as $field) {
            $date = CarbonImmutable::parse($data[$field])->setTimezone('Europe/Moscow');
            $data[$field] = $date->format($date->micro === 0 ? 'Y-m-d\TH:i:sP' : 'Y-m-d\TH:i:s.uP');
        }

        return $data;
    }
}
