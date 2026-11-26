<?php

namespace App\Notifications;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class NotificationRecords
{
    public function updateMessage(int $id, array $input): array
    {
        MessageIntake::validateMetadata($input);
        $query = Resource::Message->query()->where('id', $id);

        try {
            return DB::transaction(function () use ($query, $input, $id): array {
                $current = (clone $query)->lockForUpdate()->first() ?? abort(404);
                $rules = MessageIntake::rules();
                $rules['eventId'] = 'sometimes|required|string|max:200';
                if (($input['scenario_id'] ?? null) === $current->scenario_id) {
                    $rules['scenario_id'] = 'nullable|uuid';
                }
                $data = Validator::make($input, $rules)->validate();
                foreach (['metadata', 'channels', 'tags'] as $field) {
                    if (array_key_exists($field, $data)) {
                        $data[$field] = json_encode($data[$field], JSON_THROW_ON_ERROR);
                    }
                }
                if (array_key_exists('subject', $data) && $data['subject'] === null) {
                    $data['subject'] = '';
                }
                if ($data['isDeleted'] ?? false) {
                    $this->lockIdleSendings(Resource::Sending->query()->where('message', $id));
                }
                (clone $query)->update([...$data, 'updatedAt' => now()]);

                return Resource::Message->serialize($query->first());
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            $field = str_contains($exception->errorInfo[2] ?? '', 'messages_eventid_unique') ? 'eventId' : 'uuid';
            throw ValidationException::withMessages([$field => 'This value is already in use.']);
        }
    }

    public function saveSending(array $input, ?int $id = null, bool $replace = false): array
    {
        $required = $id === null || $replace ? 'required' : 'sometimes|required';
        $data = Validator::make($input, [
            'uuid' => 'string|max:200',
            'expire' => 'sometimes|required|date',
            'mustBeSend' => 'sometimes|required|date',
            'subject' => 'string|max:200',
            'content' => "$required|string",
            'destination' => 'string|max:200',
            'channel' => "$required|string|max:50|exists:channels,code",
            'message' => 'nullable|integer|exists:messages,id',
            'status' => 'integer|between:0,8',
            'isDeleted' => 'boolean',
        ])->validate();

        return DB::transaction(function () use ($data, $id): array {
            $now = now();
            if ($id === null) {
                $id = Resource::Sending->query()->insertGetId($data + [
                    'uuid' => '',
                    'expire' => $now->copy()->addDay(),
                    'mustBeSend' => $now,
                    'createdAt' => $now,
                    'updatedAt' => $now,
                ]);
            } else {
                $query = Resource::Sending->query()->where('id', $id);
                $row = (clone $query)->lockForUpdate()->first() ?? abort(404);
                abort_if($row->claimedAt !== null, 409);
                $query->update([...$data, 'updatedAt' => $now]);
            }

            return Resource::Sending->serialize(Resource::Sending->query()->where('id', $id)->first());
        }, 3);
    }

    public function delete(Resource $resource, int $id): void
    {
        DB::transaction(function () use ($resource, $id): void {
            $query = $resource->query()->where('id', $id);
            $row = (clone $query)->lockForUpdate()->first() ?? abort(404);
            if ($resource === Resource::Message) {
                $this->lockIdleSendings(Resource::Sending->query()->where('message', $id));
            } else {
                abort_if($row->claimedAt !== null, 409);
            }
            $query->delete();
        }, 3);
    }

    public function lockIdleSendings(Builder $query): Collection
    {
        $sendings = (clone $query)->orderBy('id')->lockForUpdate()->get(['id', 'uuid', 'channel', 'claimedAt']);
        abort_if($sendings->contains(fn (object $sending) => $sending->claimedAt !== null), 409);

        return $sendings;
    }
}
