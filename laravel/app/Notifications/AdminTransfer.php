<?php

namespace App\Notifications;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AdminTransfer
{
    public function __construct(private readonly Directory $directory, private readonly NotificationRecords $records, private readonly MessageIntake $intake) {}

    public function save(Resource $resource, array $input, ?string $key = null): array
    {
        return match ($resource) {
            Resource::Message => $key !== null
                ? $this->records->updateMessage((int) $key, $input)
                : ($this->intake->create([$input], allowStatus: true)[0] ?? throw ValidationException::withMessages(['eventId' => 'Уведомление с таким событием уже существует.'])),
            Resource::Sending => $this->records->saveSending($input, $key === null ? null : (int) $key),
            default => $this->directory->save($resource, $input, $key),
        };
    }

    public function import(Resource $resource, array $rows): int
    {
        return DB::transaction(function () use ($resource, $rows): int {
            foreach ($rows as $index => $row) {
                $input = get_object_vars($row);
                if ($resource === Resource::Message && ($input['recipientId'] ?? null) === '') {
                    unset($input['recipientId']);
                }
                $field = match ($resource) {
                    Resource::Channel => 'code',
                    Resource::Recipient => 'phone',
                    Resource::Message => 'eventId',
                    Resource::Scenario => 'uuid',
                    Resource::Sending => 'id',
                };
                $identity = $input[$field] ?? null;
                if ($identity !== null && ! is_string($identity) && ! is_int($identity)) {
                    throw ValidationException::withMessages(['records' => 'Строка '.($index + 1).': некорректный идентификатор записи.']);
                }
                if ($field === 'uuid' && $identity !== null && ! Str::isUuid((string) $identity)) {
                    throw ValidationException::withMessages(['records' => 'Строка '.($index + 1).': некорректный UUID.']);
                }
                if ($field === 'id' && $identity !== null && filter_var($identity, FILTER_VALIDATE_INT) === false) {
                    throw ValidationException::withMessages(['records' => 'Строка '.($index + 1).': идентификатор должен быть целым числом.']);
                }
                $current = $identity === null || $identity === '' ? null : $resource->query()->where($field, $identity)->value($resource->key());
                try {
                    $this->save(
                        $resource,
                        $resource === Resource::Message ? $input : json_decode(json_encode($row, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR),
                        $current === null ? null : (string) $current,
                    );
                } catch (ValidationException $error) {
                    throw ValidationException::withMessages(['records' => 'Строка '.($index + 1).': '.$error->getMessage()]);
                }
            }

            return count($rows);
        }, 3);
    }

    public function remove(Resource $resource, string $key): void
    {
        if ($resource === Resource::Message || $resource === Resource::Sending) {
            $this->records->delete($resource, (int) $key);
        } else {
            $this->directory->remove($resource, $key, $this->records);
        }
    }
}
