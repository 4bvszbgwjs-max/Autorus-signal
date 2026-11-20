<?php

namespace App\Http;

use App\Admin\AdminAccounts;
use App\Notifications\AdminSpreadsheet;
use App\Notifications\AdminTransfer;
use App\Notifications\Directory;
use App\Notifications\Resource;
use App\Notifications\ScenarioRouting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use JsonException;

final class AdminController
{
    private const SEARCH = ['recipient' => ['name', 'phone', 'email'], 'channel' => ['code', 'name'], 'scenario' => ['title', 'description'],
        'message' => ['subject', 'eventId', 'recipientId'], 'sending' => ['subject', 'destination', 'uuid'], 'eventlog' => ['event', 'initiator']];

    public function login(Request $request, AdminAccounts $accounts)
    {
        return $accounts->identity($request) !== null
            ? redirect()->route('admin.directory', ['resource' => 'channel'])
            : view('admin', ['resource' => null, 'requiresLogin' => $accounts->requiresLogin()]);
    }

    public function authenticate(Request $request, AdminAccounts $accounts)
    {
        $credentials = $request->validate(['login' => 'nullable|string|max:64', 'password' => 'required|string|max:1024']);
        if (! $accounts->authenticate($request, $credentials['login'] ?? null, $credentials['password'])) {
            return back()->withErrors(['password' => 'Неверные данные для входа.']);
        }

        return redirect()->route('admin.directory', ['resource' => 'channel']);
    }

    public function logout(Request $request)
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }

    public function index(Request $request, string $resource)
    {
        $type = Resource::from($resource);
        $query = $type->query()->orderByDesc($type->key());
        $search = $request->query('search');
        if (is_string($search) && $search !== '') {
            $query->where(function ($filter) use ($resource, $search): void {
                foreach (self::SEARCH[$resource] as $field) {
                    $filter->orWhereLike($field, '%'.mb_substr($search, 0, 200).'%');
                }
            });
        }
        $status = $request->query('status');
        if (in_array($type, [Resource::Message, Resource::Sending], true)
            && is_string($status) && preg_match('/^[0-8]$/D', $status)) {
            $query->where('status', (int) $status);
        }
        $rows = $query->paginate(25)->withQueryString()->through(fn (object $row) => $this->present($type, $row));

        return view('admin', ['resource' => $type, 'rows' => $rows]);
    }

    public function form(Request $request, Directory $directory, string $resource, ?string $key = null)
    {
        $type = Resource::from($resource);

        return view('admin', [
            'resource' => $type,
            'key' => $key,
            'record' => $key === null ? [] : $this->present($type, $directory->find($type, $key)),
            'scenarioChannels' => $type === Resource::Scenario ? $this->scenarioChannels() : [],
        ]);
    }

    public function preview(Request $request, Directory $directory, ScenarioRouting $routing, ?string $key = null)
    {
        $type = Resource::Scenario;
        $record = $key === null ? [] : $this->present($type, $directory->find($type, $key));
        $response = redirect()->route($key === null ? 'admin.create' : 'admin.edit', ['resource' => 'scenario', 'key' => $key])
            ->withInput($this->scenarioInput($request));
        try {
            $data = $request->validate(['preview_phone' => 'required|string|max:200', 'preview_metadata' => 'nullable|string|max:8000']);
            $draft = $directory->scenarioDraft($this->formData($request, $type));
            $metadata = isset($data['preview_metadata']) ? JsonPayload::object($data['preview_metadata']) : [];
            $recipient = Resource::Recipient->query()->where('phone', $data['preview_phone'])->where('isDeleted', false)->first();
            if ($recipient === null) {
                throw ValidationException::withMessages(['preview_phone' => 'Активный получатель с таким телефоном не найден.']);
            }
        } catch (JsonException | ValidationException $error) {
            return $response->withErrors($error instanceof ValidationException ? $error->errors() : ['rules' => 'Проверьте данные сценария.']);
        }

        $record = array_replace($record, $draft);
        $channels = $this->scenarioChannels();
        $available = $channels->where('isDeleted', false)->pluck('sender', 'code');
        $selection = [];
        if (! ($record['isDeleted'] ?? false)) {
            $first = json_decode(json_encode($record['channelsFirstOf'] ?? [], JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
            $every = json_decode(json_encode($record['channelsEveryOf'] ?? [], JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
            $rules = $routing->selectChannels($first, $every, $recipient, $available);
            foreach ($rules as $code => $rule) {
                $selection[] = [
                    'code' => $code,
                    'hasDestination' => $routing->destination($metadata, $recipient, $rule, $available->get($code)) !== null,
                ];
            }
        }

        return $response->with('scenario_preview', $selection);
    }

    private function scenarioChannels()
    {
        return Resource::Channel->query()->orderBy('name')->get(['code', 'name', 'sender', 'isDeleted']);
    }

    public function save(Request $request, AdminTransfer $transfer, string $resource, ?string $key = null)
    {
        $type = Resource::from($resource);
        $this->validateKey($type, $key);
        try {
            $input = $this->formData($request, $type);
            DB::transaction(function () use ($request, $transfer, $type, $input, $key): void {
                $record = $transfer->save($type, $input, $key);
                $actor = $request->attributes->get('admin');
                AdminAccounts::audit($actor->login, $key === null ? 'create' : 'update',
                    $type->value, (string) $record[$type->key()], actorId: $actor->id ?? null);
            }, 3);
        } catch (JsonException) {
            return $this->invalidForm($request, $type, ['payload' => 'Проверьте формат JSON в настройках записи.']);
        } catch (ValidationException $exception) {
            return $this->invalidForm($request, $type, $exception->errors());
        }

        return redirect()->route('admin.directory', ['resource' => $resource])->with('status', 'Запись сохранена.');
    }

    public function export(Request $request, AdminSpreadsheet $sheets, string $resource)
    {
        $type = Resource::from($resource);
        $format = $request->query('format', 'json');
        abort_unless(in_array($format, ['json', 'csv', 'xlsx'], true), 400);
        $filename = "signal-$resource.$format";
        $headers = ['Cache-Control' => 'no-store'];

        if ($format === 'csv') {
            return response()->streamDownload(fn () => $sheets->writeCsv($type), $filename, $headers + ['Content-Type' => 'text/csv; charset=utf-8']);
        }
        if ($format === 'xlsx') {
            try {
                $path = $sheets->writeXlsx($type);
            } catch (ValidationException $exception) {
                return redirect()->route('admin.directory', ['resource' => $resource])->withErrors($exception->errors());
            }

            return response()->download($path, $filename, $headers + ['Content-Type' => AdminSpreadsheet::XLSX_TYPE])->deleteFileAfterSend();
        }

        return response()->streamDownload(function () use ($type): void {
            echo "[\n";
            $separator = '';
            foreach ($type->query()->lazyById(250, $type->key()) as $row) {
                echo $separator.json_encode($type->serialize($row), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $separator = ",\n";
            }
            echo "\n]\n";
        }, $filename, $headers + ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public function import(Request $request, AdminTransfer $transfer, AdminSpreadsheet $sheets, string $resource)
    {
        $request->validate(['records' => 'required|file|max:2048']);
        try {
            $file = $request->file('records');
            $type = Resource::from($resource);
            $rows = match (strtolower($file->getClientOriginalExtension())) {
                'json' => JsonPayload::objects($file->get()),
                'csv', 'xlsx' => $sheets->read($type, $file->getPathname(), strtolower($file->getClientOriginalExtension())),
                default => throw ValidationException::withMessages(['records' => 'Загрузите файл JSON, CSV или XLSX.']),
            };
            if (count($rows) > 1000) {
                throw ValidationException::withMessages(['records' => 'В одном файле может быть до 1000 записей.']);
            }
            $count = DB::transaction(function () use ($request, $transfer, $type, $rows): int {
                $imported = $transfer->import($type, $rows);
                $actor = $request->attributes->get('admin');
                AdminAccounts::audit($actor->login, 'import', $type->value, count: $imported, actorId: $actor->id ?? null);

                return $imported;
            }, 3);
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        return back()->with('status', 'Импортировано записей: '.$count);
    }

    public function delete(Request $request, AdminTransfer $transfer, string $resource, string $key)
    {
        $type = Resource::from($resource);
        $this->validateKey($type, $key);
        DB::transaction(function () use ($request, $transfer, $type, $key): void {
            $transfer->remove($type, $key);
            $actor = $request->attributes->get('admin');
            AdminAccounts::audit($actor->login, 'delete', $type->value, $key, actorId: $actor->id ?? null);
        }, 3);

        return redirect()->route('admin.directory', ['resource' => $resource])->with('status', 'Запись удалена.');
    }

    private function validateKey(Resource $resource, ?string $key): void
    {
        abort_if($key !== null && $resource->key() === 'id' && filter_var($key, FILTER_VALIDATE_INT) === false, 404);
    }

    private function invalidForm(Request $request, Resource $resource, array $errors)
    {
        $response = back()->withErrors($errors);

        return $resource === Resource::Scenario ? $response->withInput($this->scenarioInput($request)) : $response;
    }

    private function scenarioInput(Request $request): array
    {
        $fields = $request->input('fields');
        $input = ['fields' => is_array($fields) ? array_filter($fields, 'is_string') : []];
        foreach (['preview_phone', 'preview_metadata'] as $field) {
            if (is_string($request->input($field))) {
                $input[$field] = $request->input($field);
            }
        }

        return $input;
    }

    private function present(Resource $resource, object $row): array
    {
        $data = $resource->serialize($row);
        if ($resource === Resource::Sending) {
            $data += ['attempts' => $row->attempts, 'deliveryError' => $row->deliveryError, 'retryAt' => $row->retryAt];
        }

        return $data;
    }

    private function formData(Request $request, Resource $resource): array
    {
        if (is_string($request->input('payload'))) {
            abort_if(strlen($request->input('payload')) > 65536, 413);

            return $resource === Resource::Message
                ? get_object_vars(JsonPayload::singleObject($request->input('payload')))
                : JsonPayload::object($request->input('payload'));
        }

        $fields = $request->validate(['fields' => 'required|array'])['fields'];
        $schema = config("homer_admin.resources.{$resource->value}.fields") + ['isDeleted' => ['Пометка удаления', 'bool']];
        $result = [];
        foreach ($schema as $name => [, $type]) {
            $value = $fields[$name] ?? null;
            if (! is_string($value) || $value === '' || $type === 'readonly') {
                continue;
            }
            $result[$name] = match ($type) {
                'bool' => $value === '1',
                'json' => json_decode($value, $resource !== Resource::Message, flags: JSON_THROW_ON_ERROR),
                default => $value,
            };
        }

        JsonPayload::objects(json_encode((object) $result, JSON_THROW_ON_ERROR));

        return $result;
    }
}
