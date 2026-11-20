<?php

namespace App\Http;

use Illuminate\Http\JsonResponse;

final class OpenApiController
{
    public function __invoke(): JsonResponse
    {
        $object = ['type' => 'object'];
        $items = ['type' => 'array', 'items' => $object];
        $singleOrBatch = ['oneOf' => [$object, $items]];
        $pagination = [
            ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1]],
            ['name' => 'limit', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000],
                'description' => 'Limits the returned records. For Django compatibility meta.limit reports the default size (100).'],
        ];

        $ping = $this->operation('getPing', 200);
        $ping['security'] = [];
        $paths = [
            '/ping/' => ['get' => $ping],
            '/ready/' => ['get' => [
                'operationId' => 'getReadiness',
                'security' => [],
                'responses' => [
                    200 => ['description' => 'PostgreSQL is reachable'],
                    503 => ['description' => 'PostgreSQL is unavailable'],
                ],
            ]],
            '/metrics' => ['get' => [
                'operationId' => 'getMetrics',
                'security' => [],
                'responses' => [200 => [
                    'description' => 'Homer metrics in Prometheus text exposition format',
                    'content' => ['text/plain' => ['schema' => ['type' => 'string']]],
                ]],
            ]],
        ];
        foreach (['channel', 'message', 'recipient', 'scenario', 'sending', 'eventlog'] as $resource) {
            $base = '/api/v1/'.$resource.'/';
            $id = $resource === 'channel' ? 'code' : 'id';
            $paths[$base]['get'] = [...$this->operation('list_'.$resource, 200), 'parameters' => $pagination];
            $detail = $base.'{'.$id.'}/';
            $paths[$detail] = [
                'parameters' => [[
                    'name' => $id, 'in' => 'path', 'required' => true,
                    'schema' => ['type' => $id === 'id' ? 'integer' : 'string'],
                ]],
                'get' => $this->operation('get_'.$resource, 200),
            ];
            if ($resource === 'eventlog') {
                continue;
            }

            $input = $this->input($resource);
            $create = $resource === 'message'
                ? ['oneOf' => [$input, ['type' => 'array', 'items' => $input]]]
                : $input;
            $paths[$base]['post'] = $this->operation('create_'.$resource, $resource === 'message' ? 200 : 201, $create);
            unset($input['required']);
            $paths[$detail]['patch'] = $this->operation('patch_'.$resource, 200, $input, [404, 409]);
            $paths[$detail]['put'] = $this->operation('replace_'.$resource, 200, $resource === 'sending' ? $this->input($resource) : $input, [404, 409]);
            $paths[$detail]['delete'] = $this->operation('delete_'.$resource, 204, errors: [404, 409]);
        }

        foreach ([
            'message/bulk/remove' => 'remove_messages',
            'sending/bulk/remove' => 'remove_sendings',
            'sending/status' => 'update_sending_status',
            'recipient/bulk/upsert' => 'upsert_recipients',
            'recipient/list/phone' => 'list_recipient_phones',
        ] as $path => $operation) {
            $paths['/api/v1/'.$path.'/']['patch'] = $this->operation(
                $operation, 200, $operation === 'list_recipient_phones' ? null : $singleOrBatch, [409],
            );
        }
        $paths['/api/v1/sending/status/']['patch']['description'] =
            'Accepts provider statuses as numbers from 3 through 8 or supported status names. Queue statuses 0 through 2 are rejected.';

        return response()->json([
            'openapi' => '3.0.3',
            'info' => ['title' => config('homer_admin.brand').' API', 'version' => '1.0.0'],
            'security' => [['bearerAuth' => []]],
            'paths' => $paths,
            'components' => [
                'securitySchemes' => ['bearerAuth' => ['type' => 'http', 'scheme' => 'bearer']],
                'schemas' => [
                    'Envelope' => [
                        'type' => 'object', 'required' => ['meta', 'data'],
                        'properties' => ['meta' => $object, 'data' => new \stdClass],
                    ],
                    'Errors' => [
                        'type' => 'object', 'required' => ['errors'],
                        'properties' => ['errors' => ['type' => 'array', 'items' => [
                            'type' => 'object', 'required' => ['code', 'source', 'message'],
                            'properties' => array_fill_keys(['code', 'source', 'message'], ['type' => 'string']),
                        ]]],
                    ],
                ],
            ],
        ]);
    }

    private function input(string $resource): array
    {
        $text = ['type' => 'string'];
        $object = ['type' => 'object'];
        $required = match ($resource) {
            'channel' => ['code', 'name', 'sender'],
            'recipient' => ['phone'],
            'sending' => ['channel', 'content'],
            default => [],
        };
        $properties = array_fill_keys($required, $text);
        $properties += match ($resource) {
            'channel' => ['config' => [...$object, 'nullable' => true, 'description' => 'Read responses return an empty object. On update use null to clear stored settings.']],
            'recipient' => ['applicationFirebaseToken' => [...$text, 'nullable' => true, 'description' => 'Read responses return an empty string. On update use null to clear the stored token.']],
            'message' => [
                'recipientId' => [...$text, 'description' => 'Optional when the delivery address is resolved from metadata.'],
                'eventId' => $text,
                'metadata' => ['oneOf' => [$object, ['type' => 'array', 'items' => new \stdClass]]],
            ],
            default => [],
        };

        return ['type' => 'object']
            + ($required === [] ? [] : ['required' => $required])
            + ($properties === [] ? [] : ['properties' => $properties]);
    }

    private function operation(string $id, int $status, ?array $input = null, array $errors = []): array
    {
        $content = static fn (string $schema): array => [
            'application/json' => ['schema' => ['$ref' => '#/components/schemas/'.$schema]],
        ];
        $responses = [
            $status => ['description' => 'Success'] + ($status === 204 ? [] : ['content' => $content('Envelope')]),
        ];
        foreach (array_unique([400, 401, ...$errors]) as $error) {
            $responses[$error] = ['description' => 'Request rejected', 'content' => $content('Errors')];
        }

        return ['operationId' => $id, 'responses' => $responses]
            + ($input === null ? [] : ['requestBody' => [
                'required' => true, 'content' => ['application/json' => ['schema' => $input]],
            ]]);
    }
}
