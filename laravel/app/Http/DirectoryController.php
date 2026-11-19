<?php

namespace App\Http;

use App\Notifications\Directory;
use App\Notifications\NotificationRecords;
use App\Notifications\Resource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class DirectoryController
{
    public function create(Request $request, Directory $directory, string $resource): JsonResponse
    {
        return $this->respond($directory->save(Resource::from($resource), $this->object($request)), 201);
    }

    public function update(Request $request, Directory $directory, string $resource, string $key): JsonResponse
    {
        return $this->respond($directory->save(Resource::from($resource), $this->object($request), $key));
    }

    public function delete(Directory $directory, NotificationRecords $records, string $resource, string $key): Response
    {
        $directory->remove(Resource::from($resource), $key, $records);

        return response()->noContent();
    }

    public function upsert(Request $request, Directory $directory): JsonResponse
    {
        $input = array_map('get_object_vars', JsonPayload::objects($request->getContent()));

        return $this->respond($directory->upsertRecipients($input));
    }

    public function phones(Request $request, NotificationController $notifications): JsonResponse
    {
        $response = $notifications->index($request, Resource::Recipient->value);
        $payload = $response->getData(true);
        $payload['data'] = array_column($payload['data'], 'phone');

        return response()->json($payload);
    }

    private function object(Request $request): array
    {
        return JsonPayload::object($request->getContent());
    }

    private function respond(array $data, int $status = 200): JsonResponse
    {
        return response()->json(['meta' => (object) [], 'data' => $data], $status);
    }
}
