<div class="heading">
    <h1>{{ $panelTitle }}</h1>
    @if ($panel === 'accounts')
        <a class="button primary" href="{{ route('admin.account.create') }}">Добавить</a>
    @endif
</div>

@if ($panel === 'queue')
    <div class="queue-summary">
        <section class="card metric"><span>Ожидают обработки</span><strong>{{ $queue->sum('waiting') }}</strong></section>
        <section class="card metric"><span>Готовы к отправке</span><strong>{{ $queue->sum('ready') }}</strong></section>
        <section class="card metric"><span>Самая старая готовая отправка</span><strong>{{ $queue->max('oldest_seconds') === null ? '—' : \Carbon\CarbonInterval::seconds((int) $queue->max('oldest_seconds'))->cascade()->locale('ru')->forHumans(short: true, parts: 2) }}</strong></section>
    </div>
    <section class="card table-wrap" aria-label="Очередь по каналам">
        <table>
            <thead><tr><th>Канал</th><th>Ожидают</th><th>Готовы</th><th>Без адреса</th><th>В обработке</th><th>Возраст самой старой готовой</th></tr></thead>
            <tbody>
                @forelse($queue as $row)
                    <tr><td>{{ $row->channel }}</td><td>{{ $row->waiting }}</td><td>{{ $row->ready }}</td><td>{{ $row->missing_address }}</td><td>{{ $row->in_progress }}</td>
                    <td>{{ $row->oldest_seconds === null ? '—' : \Carbon\CarbonInterval::seconds((int) $row->oldest_seconds)->cascade()->locale('ru')->forHumans(short: true, parts: 2) }}</td></tr>
                @empty
                    <tr><td colspan="6" class="empty">Очередь пуста.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
    <h2 class="section-heading">Последние ошибки отправок за 24 часа</h2>
    <section class="card table-wrap">
        <table>
            <thead><tr><th>Канал</th><th>Код последней ошибки</th><th>Отправок с этой ошибкой</th></tr></thead>
            <tbody>
                @forelse($failures as $failure)
                    <tr><td>{{ $failure->channel }}</td><td>{{ $failure->deliveryError }}</td><td>{{ $failure->total }}</td></tr>
                @empty
                    <tr><td colspan="3" class="empty">Ошибок нет.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
@elseif ($panel === 'account-form')
    <p><a href="{{ route('admin.accounts') }}">← К списку</a></p>
    <section class="card editor">
        <form method="post" action="{{ route('admin.account.save', ['id'=>$account?->id]) }}">
            @csrf
            <div class="fields">
                <div><label for="login">Логин</label><input id="login" name="login" value="{{ old('login', $account?->login) }}" maxlength="64" pattern="[A-Za-z0-9_\-]+" autocomplete="username" required></div>
                <div><label for="role">Роль</label><select id="role" name="role">
                    @foreach ($roles as $value => $label)
                        <option value="{{ $value }}" @selected(old('role', $account?->role ?? 'viewer') === $value)>{{ $label }}</option>
                    @endforeach
                </select></div>
                <div><label for="active">Доступ</label><select id="active" name="active">
                    <option value="1" @selected((string) old('active', (int) ($account?->active ?? true)) === '1')>Включён</option>
                    <option value="0" @selected((string) old('active', (int) ($account?->active ?? true)) === '0')>Отключён</option>
                </select></div>
                <div><label for="password">{{ $account ? 'Новый пароль' : 'Пароль' }}</label><input type="password" id="password" name="password" minlength="12" autocomplete="new-password" @required(!$account)></div>
                <div><label for="password_confirmation">Повтори пароль</label><input type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password" @required(!$account)></div>
            </div>
            @if ($account)<p>Чтобы сохранить прежний пароль, оставь оба поля пароля пустыми.</p>@endif
            <button class="button primary" type="submit">Сохранить</button>
        </form>
    </section>
@else
    <section class="card table-wrap">
        @if ($panel === 'accounts')
            <table><thead><tr><th>Логин</th><th>Роль</th><th>Доступ</th><th scope="col"><span class="visually-hidden">Действия</span></th></tr></thead>
            <tbody>
                @forelse ($accounts as $account)
                    <tr><td>{{ $account->login }}</td><td>{{ $roles[$account->role] }}</td><td>{{ $account->active ? 'Включён' : 'Отключён' }}</td><td><a href="{{ route('admin.account.edit', ['id'=>$account->id]) }}">Открыть</a></td></tr>
                @empty
                    <tr><td colspan="4" class="empty">Создай учётную запись администратора, чтобы включить вход по логину.</td></tr>
                @endforelse
            </tbody></table>
        @else
            @php
                $actions = ['create'=>'Создание', 'update'=>'Изменение', 'delete'=>'Удаление', 'import'=>'Импорт'];
            @endphp
            <table><thead><tr><th>Время (МСК)</th><th>Пользователь</th><th>Действие</th><th>Раздел</th><th>Запись</th><th>Количество</th></tr></thead>
            <tbody>
                @forelse ($audit as $entry)
                    <tr><td>{{ \Carbon\CarbonImmutable::parse($entry->occurred_at)->setTimezone('Europe/Moscow')->format('d.m.Y H:i:s') }}</td>
                    <td>{{ $entry->actor }} @if($entry->actor_id !== null) <small>ID {{ $entry->actor_id }}</small> @endif</td><td>{{ $actions[$entry->action] ?? $entry->action }}</td>
                    <td>{{ $sections[$entry->resource]['title'] ?? 'Учётные записи' }}</td><td>{{ $entry->reference ?? '—' }}</td><td>{{ $entry->record_count ?? '—' }}</td></tr>
                @empty
                    <tr><td colspan="6" class="empty">Действий пока нет.</td></tr>
                @endforelse
            </tbody></table>
        @endif
        @php
            $pages = $panel === 'accounts' ? $accounts : $audit;
        @endphp
        <footer class="pagination">
            <span>Страница {{ $pages->currentPage() }} из {{ $pages->lastPage() }}</span>
            @if ($pages->previousPageUrl()) <a href="{{ $pages->previousPageUrl() }}">Назад</a> @endif
            @if ($pages->nextPageUrl()) <a href="{{ $pages->nextPageUrl() }}">Далее</a> @endif
        </footer>
    </section>
@endif
