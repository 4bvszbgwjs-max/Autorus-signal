<?php

namespace App\Http;

use App\Notifications\NotificationRecords;
use App\Notifications\Resource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class NotificationRecordsController
{
    public function createSending(Request $request, NotificationRecords $records): JsonResponse
    {
        return $this->respond($records->saveSending(JsonPayload::object($request->getContent())), 201);
    }

    public function update(Request $request, NotificationRecords $records, string $record, string $key): JsonResponse
    {
        $id = $this->id($key);
        $replace = $request->isMethod('PUT');
        $input = get_object_vars(JsonPayload::singleObject($request->getContent()));
        $data = match (Resource::from($record)) {
            Resource::Message => $records->updateMessage($id, $input),
            Resource::Sending => $records->saveSending($input, $id, $replace),
        };

        return $this->respond($data);
    }

    public function delete(NotificationRecords $records, string $record, string $key): Response
    {
        $records->delete(Resource::from($record), $this->id($key));

        return response()->noContent();
    }

    private function id(string $key): int
    {
        $id = filter_var($key, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        abort_if($id === false, 404);

        return $id;
    }

    private function respond(array $data, int $status = 200): JsonResponse
    {
        return response()->json(['meta' => (object) [], 'data' => $data], $status);
    }
}
