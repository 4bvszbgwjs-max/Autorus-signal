<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $resource ? config('homer_admin.resources.'.$resource->value.'.title').' · ' : '' }}{{ config('homer_admin.brand') }}</title>
    <link rel="icon" href="data:,">
    <link rel="stylesheet" href="{{ asset('admin.css') }}">
</head>
<body class="{{ $resource ? 'workspace' : 'login' }}">
@php
    $sections = config('homer_admin.resources');
    $statuses = config('homer_admin.statuses');
    $types = [0 => 'Сервисное', 1 => 'Заказы', 2 => 'Акции'];
    $providers = ['notices'=>'Notices', 'builtinEmail'=>'SMTP', 'smsIdgtl'=>'IDGTL', 'emailRetailRocket'=>'Retail Rocket', 'pushFirebase'=>'Firebase', 'telegram'=>'Telegram'];
    $display = fn ($field, $value) => match ($field) {
        'status' => $statuses[$value] ?? '—',
        'type', 'messageType' => $types[$value] ?? '—',
        'sender' => $providers[$value] ?? $value,
        default => $value === null || $value === '' ? '—' : (is_bool($value) ? ($value ? 'Да' : 'Нет') : $value),
    };
@endphp

@if ($resource)
    <header class="topbar">
        <a class="brand" href="{{ route('admin.login') }}">{{ config('homer_admin.brand') }}</a>
        <form method="post" action="{{ route('admin.logout') }}">
            @csrf
            <button class="button outline" type="submit">Выйти</button>
        </form>
    </header>
    <aside class="sidebar">
        <nav aria-label="Разделы">
            @foreach ($sections as $id => $section)
                <a href="{{ route('admin.directory', ['resource'=>$id]) }}" @if($id === $resource->value) aria-current="page" @endif>{{ $section['title'] }}</a>
            @endforeach
        </nav>
    </aside>
@endif

<main class="{{ $resource ? 'content' : 'login-main' }}">
    @if ($errors->any())
        <ul class="alert error" role="alert">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif
    @if (session('status'))
        <p class="alert success" role="status">{{ session('status') }}</p>
    @endif

    @if (!$resource)
        <section class="card login-card">
            <h1>{{ config('homer_admin.brand') }}</h1>
            <p>Управление уведомлениями</p>
            <form method="post" action="{{ route('admin.authenticate') }}">
                @csrf
                <label for="password">Пароль</label>
                <input type="password" name="password" id="password" autocomplete="current-password" required autofocus>
                <button class="button primary" type="submit">Войти</button>
            </form>
        </section>
    @else
        @php
            $section = $sections[$resource->value];
            $fields = $section['fields'] + ['isDeleted' => ['Пометка удаления', 'bool']];
            $params = ['resource' => $resource->value];
            $readonly = $resource === App\Notifications\Resource::EventLog;
        @endphp
        <div class="heading">
            <h1>{{ $section['title'] }}</h1>
            @if (isset($rows) && !$readonly)
                <a class="button primary" href="{{ route('admin.create', $params) }}">Добавить</a>
            @endif
        </div>

        @if (isset($rows))
            <section class="card">
                <div class="toolbar">
                    <form class="search" method="get" action="{{ route('admin.directory', $params) }}">
                        <input type="search" name="search" maxlength="200" aria-label="Поиск по разделу" placeholder="Поиск" value="{{ is_string(request('search')) ? request('search') : '' }}">
                        @if (in_array('status', $section['columns'], true))
                            <select name="status" aria-label="Статус">
                                <option value="">Все статусы</option>
                                @foreach ($statuses as $value => $label)
                                    <option value="{{ $value }}" @selected((string) $value === request('status'))>{{ $label }}</option>
                                @endforeach
                            </select>
                        @endif
                        <button class="button" type="submit">Найти</button>
                        @if(request()->filled('search') || request()->filled('status'))
                            <a href="{{ route('admin.directory', $params) }}">Сбросить</a>
                        @endif
                    </form>
                    <div class="actions">
                        <details class="file-menu">
                            <summary class="button">Экспорт</summary>
                            <nav aria-label="Формат выгрузки">
                                @foreach (['xlsx', 'csv', 'json'] as $format)
                                    <a href="{{ route('admin.export', [...$params, 'format'=>$format]) }}" class="button">Экспорт {{ strtoupper($format) }}</a>
                                @endforeach
                            </nav>
                        </details>
                        @if (!$readonly)
                            <details class="file-menu">
                                <summary class="button">Импорт</summary>
                                <form method="post" action="{{ route('admin.import', $params) }}" enctype="multipart/form-data">
                                    @csrf
                                    <label for="records">Файл XLSX, CSV или JSON</label>
                                    <input type="file" id="records" name="records" accept=".xlsx,.csv,.json" required>
                                    <button class="button primary" type="submit">Импортировать</button>
                                </form>
                            </details>
                        @endif
                    </div>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead><tr>
                            @foreach ($section['columns'] as $field)
                                <th scope="col">{{ $fields[$field][0] }}</th>
                            @endforeach
                            <th scope="col"><span class="visually-hidden">Действия</span></th>
                        </tr></thead>
                        <tbody>
                        @forelse ($rows as $row)
                            <tr>
                                @foreach ($section['columns'] as $field)
                                    <td>
                                        @if ($field === 'status')
                                            <span class="status status-{{ $row[$field] }}">{{ $display($field, $row[$field]) }}</span>
                                        @else
                                            {{ $display($field, $row[$field] ?? null) }}
                                        @endif
                                    </td>
                                @endforeach
                                <td><a href="{{ route('admin.edit', [...$params, 'key'=>$row[$resource->key()]]) }}">Открыть</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="{{ count($section['columns']) + 1 }}" class="empty">Записей нет.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                <footer class="pagination">
                    <span>Страница {{ $rows->currentPage() }} из {{ $rows->lastPage() }}</span>
                    @if ($rows->previousPageUrl()) <a href="{{ $rows->previousPageUrl() }}">Назад</a> @endif
                    @if ($rows->nextPageUrl()) <a href="{{ $rows->nextPageUrl() }}">Далее</a> @endif
                </footer>
            </section>
        @else
            <p><a href="{{ route('admin.directory', $params) }}">← К списку</a></p>
            <section class="card editor">
                <h2>{{ $key === null ? 'Новая запись' : 'Запись · '.$key }}</h2>
                @if ($readonly)
                    <dl>
                        @foreach ($section['fields'] as $field => [$label])
                            <dt>{{ $label }}</dt><dd>{{ $display($field, $record[$field] ?? null) }}</dd>
                        @endforeach
                    </dl>
                @else
                    <form method="post" action="{{ route('admin.save', [...$params, 'key'=>$key]) }}">
                        @csrf
                        <div class="fields">
                            @foreach ($fields as $field => [$label, $type])
                                @php
                                    $value = $record[$field] ?? null;
                                    $choices = match ($type) {
                                        'bool' => [0=>'Нет', 1=>'Да'],
                                        'sender' => $providers,
                                        'status' => $statuses,
                                        'messageType' => $types,
                                        default => [],
                                    };
                                @endphp
                                <div @class(['field', 'wide' => in_array($type, ['json', 'area'], true)])>
                                    <label for="field-{{ $field }}">{{ $label }}</label>
                                    @if ($type === 'readonly' || ($field === 'code' && $key !== null))
                                        <div class="read-value" id="field-{{ $field }}">{{ $display($field, $value) }}</div>
                                    @elseif ($choices !== [])
                                        <select id="field-{{ $field }}" name="fields[{{ $field }}]">
                                            <option value="">—</option>
                                            @foreach ($choices as $option => $caption)
                                                <option value="{{ $option }}" @selected($value !== null && (string) (is_bool($value) ? (int) $value : $value) === (string) $option)>{{ $caption }}</option>
                                            @endforeach
                                        </select>
                                    @elseif ($type === 'json' || $type === 'area')
                                        <textarea id="field-{{ $field }}" name="fields[{{ $field }}]" rows="5">{{ $type === 'json' && $value !== null ? json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : $value }}</textarea>
                                        @if ($field === 'config') <small>Сохранённые секреты скрыты. Пустой объект сохраняет текущие настройки. Для очистки введите null.</small> @endif
                                    @else
                                        <input id="field-{{ $field }}" name="fields[{{ $field }}]" type="{{ match($type) {'secret'=>'password', 'number'=>'number', default=>'text'} }}" value="{{ $type === 'secret' ? '' : $value }}" @if($type === 'secret') autocomplete="new-password" @endif>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                        <button class="button primary" type="submit">Сохранить</button>
                    </form>
                    @if ($key !== null)
                        <form class="delete" method="post" action="{{ route('admin.delete', [...$params, 'key'=>$key]) }}" onsubmit="return confirm('Удалить запись?')">
                            @csrf
                            <button class="button danger" type="submit">Удалить</button>
                        </form>
                    @endif
                @endif
            </section>
        @endif
    @endif
</main>
</body>
</html>
