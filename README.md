# Homer

Homer принимает уведомления по HTTP и через RabbitMQ, выбирает каналы по сценариям и отслеживает результаты отправки.

Сервис находится в каталоге [`laravel/`](laravel/). Стек включает PHP 8.4, Laravel Octane с FrankenPHP, PostgreSQL и RabbitMQ.

## Разработка

- [Запуск через Docker Compose](laravel/README.md#запуск-через-docker-compose)
- [Настройка фоновых процессов и каналов доставки](laravel/README.md#фоновая-обработка-и-доставка)
- [HTTP API и регрессионная проверка](laravel/README.md#управление-уведомлениями-и-отправками)
- [Контрактная сверка с Django](laravel/README.md#сверка-с-django)

После запуска состояние сервиса можно проверить по `GET /ping`, схему HTTP API — по `GET /openapi/`. Рабочие значения для подключения храните в настройках окружения. [Пример переменных Docker Compose](laravel/.env.compose.example)
