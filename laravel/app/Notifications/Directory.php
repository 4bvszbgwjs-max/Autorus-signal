<?php

namespace App\Notifications;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class Directory
{
    public const SENDERS = ['notices', 'builtinEmail', 'smsIdgtl', 'emailRetailRocket', 'pushFirebase', 'telegram'];

    public function save(Resource $resource, array $input, ?string $key = null): array
    {
        $data = $this->validate($resource, $input, $key === null);
        try {
            return DB::transaction(fn () => $this->write($resource, $data, $key));
        } catch (UniqueConstraintViolationException) {
            $field = match ($resource) {
                Resource::Channel => 'code',
                Resource::Recipient => 'phone',
                Resource::Scenario => 'uuid',
            };
            throw ValidationException::withMessages([$field => 'This value is already in use.']);
        }
    }

    public function upsertRecipients(array $input): array
    {
        $resource = Resource::Recipient;
        Validator::make(['items' => $input], [
            'items' => 'array|max:1000',
            'items.*' => 'required|array',
            'items.*.phone' => 'required|string|max:200|distinct',
        ])->validate();

        // Keep the input order when rows with different insert defaults or update fields are interleaved.
        $batches = [];
        $previousColumns = [];
        foreach ($input as $item) {
            $recipient = $this->validate($resource, $item, true);
            $now = now();
            $row = [...$recipient, 'createdAt' => $now, 'updatedAt' => $now];
            ksort($row);
            $columns = array_keys($row);
            if ($columns !== $previousColumns) {
                $batches[] = [];
                $previousColumns = $columns;
            }
            $batches[array_key_last($batches)][] = $row;
        }
        if ($batches === []) {
            return [];
        }

        return DB::transaction(function () use ($resource, $batches): array {
            $result = [];
            $query = $resource->query();
            $grammar = $query->getGrammar();
            foreach ($batches as $rows) {
                $update = array_values(array_diff(array_keys($rows[0]), ['phone', 'createdAt']));
                $sql = $grammar->compileUpsert($query, $rows, ['phone'], $update).' returning *';
                $returned = array_column(DB::selectFromWriteConnection($sql, Arr::flatten($rows, 1)), null, 'phone');
                foreach ($rows as $row) {
                    $result[] = $resource->serialize($returned[$row['phone']]);
                }
            }

            return $result;
        }, 3);
    }

    public function scenarioDraft(array $input): array
    {
        return $this->validate(Resource::Scenario, $input, false);
    }

    public function find(Resource $resource, string $key): object
    {
        abort_if($resource->key() === 'id' && filter_var($key, FILTER_VALIDATE_INT) === false, 404);

        return $resource->query()->where($resource->key(), $key)->first() ?? abort(404);
    }

    public function remove(Resource $resource, string $key, NotificationRecords $records): void
    {
        DB::transaction(function () use ($resource, $key, $records): void {
            abort_if($resource->key() === 'id' && filter_var($key, FILTER_VALIDATE_INT) === false, 404);
            $target = $resource->query()->where($resource->key(), $key);
            abort_if((clone $target)->lockForUpdate()->first() === null, 404);
            if ($resource === Resource::Channel) {
                $records->lockIdleSendings(Resource::Sending->query()->where('channel', $key));
            }
            $target->delete();
        }, 3);
    }

    private function write(Resource $resource, array $data, ?string $key): array
    {
        $current = null;
        if ($resource === Resource::Scenario) {
            // Serialize changes to default scenarios, including when the table is empty.
            DB::statement('lock table scenarios in share row exclusive mode');
        }
        if ($key !== null) {
            abort_if($resource->key() === 'id' && filter_var($key, FILTER_VALIDATE_INT) === false, 404);
            $current = $resource->query()->where($resource->key(), $key)->lockForUpdate()->first() ?? abort(404);
        }

        if ($resource === Resource::Scenario) {
            $type = array_key_exists('messageType', $data) ? $data['messageType'] : ($current === null ? 0 : $current->messageType);
            $data['messageType'] = $type;
            foreach (['channelsFirstOf', 'channelsEveryOf'] as $field) {
                $data[$field] ??= isset($current->$field) ? json_decode($current->$field, true, flags: JSON_THROW_ON_ERROR) : [];
                foreach ($data[$field] as &$rule) {
                    $rule['fieldsConditions'] = (object) ($rule['fieldsConditions'] ?? []);
                }
                unset($rule);
            }
            $first = $this->activeChannels($data['channelsFirstOf']);
            $every = $this->activeChannels($data['channelsEveryOf']);
            $labels = [0 => 'Сервисное', 1 => 'Заказы', 2 => 'Акции'];
            $data['title'] = Str::limit(($labels[$type] ?? '').': ('.implode('/', $first).', '.implode(', ', $every).')', 150, '');
            $data['channelsFirstOf'] = json_encode($data['channelsFirstOf'], JSON_THROW_ON_ERROR);
            $data['channelsEveryOf'] = json_encode($data['channelsEveryOf'], JSON_THROW_ON_ERROR);
            if ($data['byDefault'] ?? $current->byDefault ?? false) {
                $query = $resource->query()->where('messageType', $type)->where('byDefault', true);
                if ($key !== null) {
                    $query->where('id', '!=', $key);
                }
                $query->update(['byDefault' => false, 'updatedAt' => now()]);
            }
        }

        if ($resource === Resource::Channel) {
            unset($data['id']);
            if ($key === null || array_key_exists('config', $data)) {
                $data['config'] = Crypt::encryptString(json_encode((object) ($data['config'] ?? []), JSON_THROW_ON_ERROR));
            }
        }

        $data['updatedAt'] = now();
        $primary = $resource->key();
        if ($key === null) {
            $data['createdAt'] = $data['updatedAt'];
            if ($resource === Resource::Scenario) {
                $data['uuid'] ??= (string) Str::uuid();
            }
            if ($resource === Resource::Channel) {
                $resource->query()->insert($data);
                $key = $data['code'];
            } else {
                $key = (string) $resource->query()->insertGetId($data);
            }
        } else {
            unset($data[$primary]);
            $resource->query()->where($primary, $key)->update($data);
        }

        return $resource->serialize($this->find($resource, $key));
    }

    private function activeChannels(array $rules): array
    {
        return array_column(array_filter($rules, fn (array $rule) => $rule['isActive']), 'channel');
    }

    private function validate(Resource $resource, array $input, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes|required';
        $rules = ['isDeleted' => 'boolean', ...match ($resource) {
            Resource::Channel => [
                'name' => "$required|string|max:200",
                'sender' => [...explode('|', $required), Rule::in(self::SENDERS)],
                'config' => 'nullable|array',
                ...($creating ? ['code' => 'required|alpha_dash:ascii|max:50'] : []),
            ],
            Resource::Recipient => [
                'phone' => "$required|string|max:200",
                'name' => 'nullable|string|max:200',
                'email' => 'nullable|string|max:200',
                'applicationFirebaseToken' => 'nullable|string|max:200',
                'isSubscribe' => 'nullable|boolean',
                'isConfirmed' => 'nullable|boolean',
                'isPhoneConfirmed' => 'nullable|boolean',
            ],
            Resource::Scenario => [
                ...($creating ? ['uuid' => 'uuid'] : []),
                'description' => 'nullable|string',
                'messageType' => 'nullable|integer|between:0,2',
                'byDefault' => 'boolean',
            ],
        }];

        if ($resource === Resource::Scenario) {
            foreach (['channelsFirstOf', 'channelsEveryOf'] as $field) {
                $rules += [
                    $field => 'array|list|max:100',
                    "$field.*" => 'required|array:channel,isActive,destinationField,contentField,destinationFieldPath,fieldsConditions',
                    "$field.*.channel" => 'required|string|exists:channels,code',
                    "$field.*.isActive" => 'required|boolean',
                    "$field.*.destinationField" => 'nullable|in:phone,email,applicationFirebaseToken',
                    "$field.*.contentField" => 'nullable|in:shortContent,content,htmlContent',
                    "$field.*.destinationFieldPath" => 'nullable|string|max:500',
                    "$field.*.fieldsConditions" => 'array:notNone,equalTrue,equalFalse',
                    "$field.*.fieldsConditions.*" => 'array|list',
                    "$field.*.fieldsConditions.notNone.*" => 'in:phone,email,applicationFirebaseToken',
                    "$field.*.fieldsConditions.equalTrue.*" => 'in:isSubscribe,isConfirmed,isPhoneConfirmed',
                    "$field.*.fieldsConditions.equalFalse.*" => 'in:isSubscribe,isConfirmed,isPhoneConfirmed',
                ];
            }
        }

        $data = Validator::make($input, $rules)->validate();
        // The read representation uses empty placeholders, so saving it must keep stored values.
        if ($resource === Resource::Channel && ($data['config'] ?? null) === []) {
            unset($data['config']);
        }
        if ($resource === Resource::Recipient && ($data['applicationFirebaseToken'] ?? null) === '') {
            unset($data['applicationFirebaseToken']);
        }

        return $data;
    }
}
