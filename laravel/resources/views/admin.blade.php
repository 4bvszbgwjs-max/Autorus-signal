<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Homer · Администрирование</title>
    <style>
        body{margin:0;background:#f1f5f9;color:#172033;font:1rem system-ui,sans-serif}
        main{max-width:70rem;margin:2.5rem auto;padding:0 1rem}
        header,nav{display:flex;gap:1rem;align-items:center;flex-wrap:wrap}
        header{justify-content:space-between} nav{margin-block:1.25rem}
        a{color:#1445a3} a[aria-current]{font-weight:700}
        section,details{background:white;border:1px solid #cbd5e1;border-radius:.5rem;padding:1rem;margin-block:.75rem}
        summary{cursor:pointer} pre,textarea{max-width:100%;box-sizing:border-box;overflow:auto}
        textarea{display:block;width:100%;min-height:8rem;margin:.5rem 0 1rem;font:1rem monospace}
        button,input{font:inherit;padding:.45rem .7rem} .errors{color:#991b1b}
        footer{display:flex;gap:1rem;margin-top:1rem}
    </style>
</head>
<body>
<main>
    <header>
        <h1>Homer</h1>
        @if($resource)
            <form method="post" action="{{ route('admin.logout') }}">
                @csrf
                <button>Выйти</button>
            </form>
        @endif
    </header>

    @if($errors->any())
        <ul class="errors" role="alert">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif
    @if(session('status'))
        <p role="status">{{ session('status') }}</p>
    @endif

    @if(!$resource)
        <section>
            <h2>Вход в администрирование</h2>
            <form method="post" action="{{ route('admin.authenticate') }}">
                @csrf
                <label for="password">Пароль</label>
                <input type="password" name="password" id="password" autocomplete="current-password" required>
                <button>Войти</button>
            </form>
        </section>
    @else
        @php($labels = ['channel' => 'Каналы', 'scenario' => 'Сценарии', 'recipient' => 'Получатели', 'message' => 'Уведомления', 'sending' => 'Отправки'])
        <nav aria-label="Разделы">
            @foreach($labels as $key => $label)
                <a href="{{ route('admin.directory', ['resource' => $key]) }}" @if($key === $resource->value) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </nav>
        <h2>{{ $labels[$resource->value] }}</h2>
        @if($editable)
            <details @if($rows->isEmpty()) open @endif>
                <summary>Добавить запись</summary>
                <form method="post" action="{{ route('admin.save', ['resource' => $resource->value]) }}">
                    @csrf
                    <label for="new-payload">Поля записи в JSON</label>
                    <textarea id="new-payload" name="payload" required>{{ json_encode($templates[$resource->value], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) }}</textarea>
                    <button>Создать</button>
                </form>
            </details>
        @endif

        @forelse($rows as $row)
            @php($rowKey = $row[$resource->key()])
            <details>
                <summary>{{ $rowKey }} · {{ ($row['name'] ?? null) ?: ($row['title'] ?? $row['subject'] ?? $row['phone'] ?? '') }}</summary>
                @if($editable)
                    <form method="post" action="{{ route('admin.save', ['resource' => $resource->value, 'key' => $rowKey]) }}">
                        @csrf
                        <label for="payload-{{ $rowKey }}">Поля записи в JSON</label>
                        <textarea id="payload-{{ $rowKey }}" name="payload" required>{{ json_encode($row, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) }}</textarea>
                        <button>Сохранить</button>
                    </form>
                @else
                    <pre>{{ json_encode($row, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) }}</pre>
                @endif
            </details>
        @empty
            <p>Записей нет.</p>
        @endforelse

        <footer>
            @if($rows->previousPageUrl()) <a href="{{ $rows->previousPageUrl() }}">Назад</a> @endif
            <span>Страница {{ $rows->currentPage() }} из {{ $rows->lastPage() }}</span>
            @if($rows->nextPageUrl()) <a href="{{ $rows->nextPageUrl() }}">Далее</a> @endif
        </footer>
    @endif
</main>
</body>
</html>
