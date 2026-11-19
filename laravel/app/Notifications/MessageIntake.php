<?php

namespace App\Notifications;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class MessageIntake
{
    public function create(array $messages): array
    {
        foreach ($messages as $index => $message) {
            self::validateMetadata($message, "messages.$index.metadata");
        }

        $rules = ['messages' => 'array|max:1000', 'messages.*' => 'required|array'];
        foreach (self::rules() as $field => $rule) {
            $rules['messages.*.'.$field] = $rule;
        }
        $validated = Validator::make(['messages' => $messages], $rules)->validate();

        try {
            return DB::transaction(function () use ($validated): array {
                $result = [];
                $now = now();
                foreach ($validated['messages'] as $message) {
                    $eventId = $message['eventId'] ?? '';
                    $row = [
                        ...Arr::except($message, ['eventId', 'channels', 'tags', 'metadata']),
                        'recipientId' => $message['recipientId'] ?? '',
                        'eventId' => $eventId === '' ? (string) Str::uuid() : $eventId,
                        'uuid' => $message['uuid'] ?? (string) Str::uuid(),
                        'expire' => $message['expire'] ?? $now->copy()->addDay(),
                        'mustBeSend' => $message['mustBeSend'] ?? $now,
                        'subject' => $message['subject'] ?? '',
                        'channels' => json_encode($message['channels'] ?? [], JSON_THROW_ON_ERROR),
                        'tags' => json_encode($message['tags'] ?? [], JSON_THROW_ON_ERROR),
                        'metadata' => json_encode($message['metadata'] ?? (object) [], JSON_THROW_ON_ERROR),
                        'createdAt' => $now,
                        'updatedAt' => $now,
                    ];

                    $created = DB::selectOne(
                        $this->insertSql(array_keys($row)),
                        array_values($row),
                        false,
                    );
                    if ($created !== null) {
                        $result[] = Resource::Message->serialize($created);
                    }
                }

                return $result;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['uuid' => 'The message UUID is already in use.']);
        }
    }

    public static function rules(): array
    {
        return [
            'eventId' => 'nullable|string|max:200',
            'uuid' => 'sometimes|required|uuid',
            'recipientId' => 'sometimes|required|string|max:200',
            'subject' => 'nullable|string|max:200',
            'content' => 'nullable|string',
            'shortContent' => 'nullable|string|max:200',
            'htmlContent' => 'nullable|string',
            'expire' => 'sometimes|required|date',
            'mustBeSend' => 'sometimes|required|date',
            'type' => 'nullable|integer|between:0,2',
            'status' => 'integer|between:0,8',
            'scenario_id' => 'nullable|uuid|exists:scenarios,uuid',
            'channels' => 'array|list',
            'channels.*' => 'string|max:50',
            'tags' => 'array|list',
            'tags.*' => 'string',
            'metadata' => 'sometimes|present',
            'isDeleted' => 'boolean',
        ];
    }

    public static function validateMetadata(array $message, string $field = 'metadata'): void
    {
        if (array_key_exists('metadata', $message)
            && ! is_array($message['metadata']) && ! $message['metadata'] instanceof \stdClass) {
            throw ValidationException::withMessages([$field => 'Metadata must be a JSON object or array.']);
        }
    }

    private function insertSql(array $columns): string
    {
        $grammar = DB::connection()->getQueryGrammar();
        $fields = $grammar->columnize($columns);
        $bindings = implode(', ', array_fill(0, count($columns), '?'));

        return "insert into messages ($fields) values ($bindings) on conflict (\"eventId\") do nothing returning *";
    }
}
