<div class="scenario-editor" data-scenario-editor>
    @foreach (['channelsFirstOf' => 'Первый доступный канал', 'channelsEveryOf' => 'Все подходящие каналы'] as $field => $label)
        <fieldset data-rule-list>
            <legend>{{ $label }}</legend>
            @if($loop->first)<p>Проверяются сверху вниз. Сработает первое подходящее правило.</p>@endif
            <textarea name="fields[{{ $field }}]" data-rule-value aria-label="{{ $label }} (JSON)">{{ old('fields.'.$field, json_encode($record[$field] ?? [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) }}</textarea>
            <div data-rules></div>
            <button class="button" type="button" data-rule-add hidden>Добавить правило</button>
        </fieldset>
    @endforeach
    <template>
        <section class="scenario-rule" aria-label="Правило">
            <div class="fields">
                <label>Канал
                    <select data-rule-field="channel" aria-label="Канал" required>
                        <option value="">Выбери канал</option>
                        @foreach ($scenarioChannels as $channel)
                            <option value="{{ $channel->code }}">{{ $channel->name }} ({{ $channel->code }}){{ $channel->isDeleted ? ' — отключён' : '' }}</option>
                        @endforeach
                    </select>
                </label>
                <label>Активно
                    <select data-rule-field="isActive" aria-label="Активно"><option value="true">Да</option><option value="false">Нет</option></select>
                </label>
                <label>Текст уведомления
                    <select data-rule-field="contentField" aria-label="Текст уведомления">
                        <option value="">Содержание по умолчанию</option>
                        <option value="content">Содержание</option><option value="shortContent">Короткий текст</option>
                        <option value="htmlContent">HTML-содержание</option>
                    </select>
                </label>
                <label>Поле получателя для адреса
                    <select data-rule-field="destinationField" aria-label="Поле получателя для адреса">
                        <option value="">По настройкам канала</option><option value="phone">Телефон</option>
                        <option value="email">Электронная почта</option><option value="applicationFirebaseToken">Токен Firebase</option>
                    </select>
                </label>
                <label>Путь к адресу в метаданных
                    <input type="text" data-rule-field="destinationFieldPath" maxlength="500" placeholder="Например, contact.email">
                </label>
            </div>
            <details data-rule-conditions>
                <summary>Условия выбора</summary>
                <div class="fields">
                    @foreach ([
                        'notNone' => ['Требуется значение', ['phone' => 'Телефон', 'email' => 'Почта', 'applicationFirebaseToken' => 'Токен Firebase']],
                        'equalTrue' => ['Признак «Да»', ['isSubscribe' => 'Подписан', 'isConfirmed' => 'Почта подтверждена', 'isPhoneConfirmed' => 'Телефон подтверждён']],
                        'equalFalse' => ['Признак «Нет»', ['isSubscribe' => 'Подписан', 'isConfirmed' => 'Почта подтверждена', 'isPhoneConfirmed' => 'Телефон подтверждён']],
                    ] as $condition => [$caption, $options])
                        <fieldset>
                            <legend>{{ $caption }}</legend>
                            @foreach ($options as $code => $name)
                                <label class="tick"><input type="checkbox" data-rule-condition="{{ $condition }}" value="{{ $code }}"> {{ $name }}</label>
                            @endforeach
                        </fieldset>
                    @endforeach
                </div>
            </details>
            <div class="actions">
                <button type="button" class="button" data-rule-action="up">Вверх</button>
                <button type="button" class="button" data-rule-action="down">Вниз</button>
                <button type="button" class="button danger" data-rule-action="remove">Убрать правило</button>
            </div>
        </section>
    </template>
</div>
<fieldset class="preview-form">
    <legend>Проверить на получателе</legend>
    <label for="preview-phone">Телефон тестового получателя</label>
    <input id="preview-phone" name="preview_phone" value="{{ old('preview_phone', '') }}" maxlength="200">
    <details @if(old('preview_metadata', '') !== '') open @endif>
        <summary>Метаданные для проверки</summary>
        <label for="preview-metadata">Метаданные (JSON)</label>
        <textarea id="preview-metadata" name="preview_metadata" maxlength="8000" rows="3">{{ old('preview_metadata', '') }}</textarea>
    </details>
    <button type="submit" class="button" formaction="{{ route('admin.scenario.preview', ['key'=>$key]) }}">Проверить каналы</button>
    @if (session()->has('scenario_preview'))
        @php
            $preview = session('scenario_preview');
        @endphp
        <div class="preview-result" role="status">
            <strong>Результат проверки</strong>
            @if ($preview === [])
                <p>Подходящих каналов нет.</p>
            @else
                <ul>
                    @foreach ($preview as $item)
                        <li><strong>{{ $item['code'] }}</strong> · {{ $item['hasDestination'] ? 'Будет выбрана отправка' : 'Выбран, но адрес назначения отсутствует' }}</li>
                    @endforeach
                </ul>
            @endif
            <p>Проверка не отправляет уведомление и не сохраняет изменения.</p>
        </div>
    @endif
</fieldset>
