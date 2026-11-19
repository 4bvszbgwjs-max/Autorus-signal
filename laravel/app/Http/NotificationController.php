<?php

namespace App\Http;

use App\Notifications\DeliverSendings;
use App\Notifications\MessageIntake;
use App\Notifications\NotificationRecords;
use App\Notifications\Resource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class NotificationController
{
    private const DEFAULT_PAGE_SIZE = 100;

    public function index(Request $request, string $resource): JsonResponse
    {
        $resource = Resource::from($resource);
        $query = $resource->query();
        $rules = ['page' => 'integer|min:1', 'limit' => 'integer|min:1|max:1000'];
        foreach ($resource->filters() as $field) {
            $rules[$field] = match ($field) {
                'id', 'status', 'type', 'message', 'messageType', 'level' => 'integer',
                'isDeleted', 'byDefault' => 'in:0,1,true,false,True,False',
                'uuid' => $resource === Resource::Message ? 'uuid' : 'string',
                default => 'string',
            };
        }
        foreach ($resource->dates() as $field) {
            $rules[$field.'_after'] = 'date';
            $rules[$field.'_before'] = 'date';
        }
        $filters = Validator::make($request->query(), $rules)->validate();

        foreach ($resource->filters() as $field) {
            if (isset($filters[$field])) {
                $value = in_array($field, ['isDeleted', 'byDefault'], true) ? filter_var($filters[$field], FILTER_VALIDATE_BOOLEAN) : $filters[$field];
                $query->where($field, $value);
            }
        }
        foreach ($resource->dates() as $field) {
            foreach (['_after' => '>=', '_before' => '<='] as $suffix => $operator) {
                if (isset($filters[$field.$suffix])) {
                    $query->where($field, $operator, $filters[$field.$suffix]);
                }
            }
        }

        $limit = (int) ($filters['limit'] ?? self::DEFAULT_PAGE_SIZE);
        $page = (int) ($filters['page'] ?? 1);
        if ($resource === Resource::EventLog) {
            $query->orderByDesc('timestamp')->orderByDesc('id');
        } else {
            $query->orderBy($resource->key());
        }
        $result = $query->paginate($limit, page: $page);
        abort_if($page > $result->lastPage(), 404);

        return response()->json([
            'meta' => ['total' => $result->total(), 'limit' => self::DEFAULT_PAGE_SIZE, 'page' => $page],
            'data' => array_map($resource->serialize(...), $result->items()),
        ]);
    }

    public function show(string $resource, string $key): JsonResponse
    {
        $resource = Resource::from($resource);
        abort_if($resource->key() === 'id' && filter_var($key, FILTER_VALIDATE_INT) === false, 404);
        $row = $resource->query()->where($resource->key(), $key)->first();
        abort_if($row === null, 404);

        return $this->respond($resource->serialize($row));
    }

    public function createMessage(Request $request, MessageIntake $intake): JsonResponse
    {
        return $this->respond($intake->create($this->payload($request)));
    }

    public function removeMessages(Request $request, NotificationRecords $records): JsonResponse
    {
        $ids = $this->validatedKeys($request, 'uuid', 'uuid');
        DB::transaction(function () use ($ids, $records): void {
            $messages = Resource::Message->query()->whereIn('uuid', $ids);
            $messageIds = (clone $messages)->orderBy('id')->lockForUpdate()->pluck('id');
            $sendings = Resource::Sending->query()->whereIn('message', $messageIds);
            $records->lockIdleSendings($sendings);
            $sendings->delete();
            $messages->delete();
        }, 3);

        return $this->respond(true);
    }

    public function removeSendings(Request $request, NotificationRecords $records): JsonResponse
    {
        $ids = $this->validatedKeys($request, 'id', 'integer');
        DB::transaction(function () use ($ids, $records): void {
            $sendings = Resource::Sending->query()->whereIn('id', $ids);
            $records->lockIdleSendings($sendings);
            $sendings->delete();
        }, 3);

        return $this->respond(true);
    }

    public function updateSendingStatus(Request $request, NotificationRecords $records): JsonResponse
    {
        $statuses = ['sent' => 3, 'sending' => 3, 'unsent' => 4, 'undelivered' => 5, 'delivered' => 7];
        $items = $this->payload($request);
        foreach ($items as $key => $item) {
            $item = array_change_key_case($item, CASE_LOWER);
            $status = $item['status'] ?? 7;
            $items[$key] = [
                'uuid' => $item['uuid'] ?? $item['messageuuid'] ?? null,
                'channel' => $item['channel'] ?? null,
                'status' => is_string($status) ? ($statuses[strtolower($status)] ?? $status) : $status,
            ];
        }
        $validated = Validator::make(['items' => $items], [
            'items' => 'array|max:1000',
            'items.*.uuid' => 'required|string|max:200',
            'items.*.channel' => 'nullable|string|max:50',
            'items.*.status' => 'required|integer|between:0,8',
        ])->validate();

        $result = DB::transaction(function () use ($validated, $records): array {
            $updated = [];
            $sendings = Resource::Sending->query()->where(function ($query) use ($validated): void {
                foreach ($validated['items'] as $item) {
                    $query->orWhere(function ($target) use ($item): void {
                        $target->where('uuid', $item['uuid'])->when(
                            isset($item['channel']), fn ($byChannel) => $byChannel->where('channel', $item['channel']),
                        );
                    });
                }
            });
            if ($validated['items'] === []) {
                return [];
            }
            $parentIds = (clone $sendings)->whereNotNull('message')->distinct()->pluck('message');
            $parents = Resource::Message->query()->whereIn('id', $parentIds)->orderBy('id')->lockForUpdate()->pluck('id');
            // Follow the worker's lock order. A callback must not change a claimed row's status.
            $byUuid = $records->lockIdleSendings($sendings)->groupBy('uuid');

            foreach ($validated['items'] as $item) {
                $matches = $byUuid->get($item['uuid'], collect());
                if (isset($item['channel'])) {
                    $matches = $matches->whereStrict('channel', $item['channel']);
                }
                abort_if($matches->count() > 1, 409);
                if ($matches->isEmpty()) {
                    continue;
                }
                $query = Resource::Sending->query()->where('id', $matches->first()->id);
                // Provider callbacks can arrive out of order. Preserve delivered and read states.
                if ($item['status'] < 8) {
                    $query->where('status', '<=', $item['status'] < 7 ? 6 : 7);
                }
                if ($query->update(['status' => $item['status'], 'updatedAt' => now()]) > 0) {
                    $updated[] = ['uuid' => $item['uuid'], 'status' => (int) $item['status']];
                }
            }

            foreach ($parents as $parent) {
                DeliverSendings::refreshMessage($parent);
            }

            return $updated;
        }, 3);

        return $this->respond($result);
    }

    private function validatedKeys(Request $request, string $key, string $rule): array
    {
        $validated = Validator::make(['items' => $this->payload($request)], [
            'items' => 'array|max:1000',
            "items.*.$key" => "required|$rule",
        ])->validate();

        return array_column($validated['items'], $key);
    }

    private function payload(Request $request): array
    {
        return array_map('get_object_vars', JsonPayload::objects($request->getContent()));
    }

    private function respond(mixed $data): JsonResponse
    {
        return response()->json(['meta' => (object) [], 'data' => $data]);
    }
}
