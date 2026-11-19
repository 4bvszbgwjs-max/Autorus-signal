# Homer на Windows без Docker

Распакуйте архив в папку с коротким путём, например `C:\Users\ваше_имя\Homer`. Откройте внутри папку `laravel` и запустите PowerShell из адресной строки Проводника.

Для запуска требуются PHP 8.4 и PostgreSQL. PHP должен быть распакован в `php84` в домашней папке. В PostgreSQL создайте отдельную базу `homer` для просмотра админки. [Установщик PostgreSQL для Windows](https://www.postgresql.org/download/windows/)

Проверьте, что приложение видит файлы Laravel из архива.

```powershell
$php = "$env:USERPROFILE\php84\php.exe"
& $php artisan --version
```

Зависимости уже в `vendor`. Composer и RabbitMQ можно пропустить для локального просмотра админки.

## Первый запуск

```powershell
Copy-Item .env.example .env
$secret = Read-Host 'Пароль для входа в Homer' -AsSecureString
$password = [System.Net.NetworkCredential]::new('', $secret).Password
$password | & $php -r 'echo password_hash(rtrim(fgets(STDIN), chr(13).chr(10)), PASSWORD_DEFAULT), PHP_EOL;'
Remove-Variable password, secret
notepad .env
```

В `.env` задайте `DB_DATABASE=homer`, `DB_USERNAME=postgres`, пароль PostgreSQL в `DB_PASSWORD` и напечатанный хеш в `HOMER_ADMIN_PASSWORD_HASH`. Заключите значения пароля и хеша в одинарные кавычки.

```powershell
& $php artisan key:generate
& $php artisan migrate
& $php artisan serve --host=127.0.0.1 --port=8000
```

Оставьте окно PowerShell открытым и перейдите на http://127.0.0.1:8000/admin. Введите пароль, который выбрали на шаге создания хеша.
