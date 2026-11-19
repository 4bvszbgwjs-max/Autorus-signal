<?php

return [
    'brand' => 'Авторусь Сигнал',
    'statuses' => ['Новое', 'Готово', 'Отправляется', 'Отправлено', 'Не отправлено', 'Не доставлено', 'Просрочено', 'Доставлено', 'Прочитано'],
    'resources' => [
        'recipient' => ['title' => 'Получатели', 'columns' => ['phone', 'name', 'email', 'isSubscribe'], 'fields' => [
            'phone' => ['Телефон', 'text'], 'name' => ['Имя', 'text'], 'email' => ['Электронная почта', 'text'],
            'applicationFirebaseToken' => ['Токен Firebase', 'secret'], 'isSubscribe' => ['Подписан', 'bool'],
            'isConfirmed' => ['Почта подтверждена', 'bool'], 'isPhoneConfirmed' => ['Телефон подтверждён', 'bool'],
        ]],
        'channel' => ['title' => 'Каналы', 'columns' => ['code', 'name', 'sender', 'isDeleted'], 'fields' => [
            'code' => ['Код', 'text'], 'name' => ['Название', 'text'], 'sender' => ['Провайдер', 'sender'],
            'config' => ['Настройки подключения (JSON)', 'json'],
        ]],
        'scenario' => ['title' => 'Сценарии', 'columns' => ['title', 'messageType', 'byDefault'], 'fields' => [
            'title' => ['Название', 'readonly'], 'uuid' => ['UUID', 'readonly'], 'messageType' => ['Тип уведомления', 'messageType'],
            'description' => ['Описание', 'area'], 'byDefault' => ['По умолчанию', 'bool'],
            'channelsFirstOf' => ['Первый доступный канал (JSON)', 'json'], 'channelsEveryOf' => ['Все подходящие каналы (JSON)', 'json'],
        ]],
        'message' => ['title' => 'Уведомления', 'columns' => ['subject', 'recipientId', 'status', 'mustBeSend'], 'fields' => [
            'subject' => ['Тема', 'text'], 'recipientId' => ['Получатель', 'text'], 'eventId' => ['ID события', 'text'],
            'scenario_id' => ['UUID сценария', 'text'], 'type' => ['Тип уведомления', 'messageType'], 'status' => ['Статус', 'status'],
            'content' => ['Содержание', 'area'], 'shortContent' => ['Короткий текст', 'text'], 'htmlContent' => ['HTML-содержание', 'area'],
            'channels' => ['Каналы (JSON)', 'json'], 'tags' => ['Теги (JSON)', 'json'], 'metadata' => ['Метаданные (JSON)', 'json'],
            'mustBeSend' => ['Отправить не ранее', 'text'], 'expire' => ['Истекает', 'text'],
        ]],
        'sending' => ['title' => 'Отправки', 'columns' => ['subject', 'destination', 'channel', 'status', 'deliveryError'], 'fields' => [
            'subject' => ['Тема', 'text'], 'channel' => ['Код канала', 'text'], 'destination' => ['Адрес назначения', 'text'],
            'message' => ['ID уведомления', 'number'], 'status' => ['Статус', 'status'], 'uuid' => ['ID провайдера', 'text'],
            'content' => ['Содержание', 'area'], 'mustBeSend' => ['Отправить не ранее', 'text'], 'expire' => ['Истекает', 'text'],
            'deliveryError' => ['Код ошибки', 'readonly'], 'attempts' => ['Попытки', 'readonly'], 'retryAt' => ['Следующая попытка', 'readonly'],
        ]],
        'eventlog' => ['title' => 'Журнал событий', 'columns' => ['timestamp', 'event', 'initiator', 'tracing'], 'fields' => [
            'timestamp' => ['Дата', 'readonly'], 'event' => ['Событие', 'readonly'], 'initiator' => ['Источник', 'readonly'],
            'tracing' => ['Сведения', 'readonly'],
        ]],
    ],
];
