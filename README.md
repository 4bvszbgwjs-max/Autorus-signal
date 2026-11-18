# homer
____
_Notifications service_

### Адреса ресурсов
* Admin panel: `http://homer.voshod.local`
* API: `http://homer.voshod.local/api/v1/`
* Open API: `http://homer.voshod.local/api/v1/openapi/`
* Rabbit mq: `http://homer.voshod.local:15672/`
   

### Environment (.env):
```dotenv
DEBUG=True
SECRET_KEY=1b1d3a25-a5d7-42dc-bbde-4e71ee541102
ALLOWED_HOSTS="localhost,0.0.0.0,192.168.10.21,sync-dev,127.0.0.1,sync-dev.voshod.local"
POSTGRES_DB=homer
POSTGRES_USER=homer
POSTGRES_PASSWORD=3354010
POSTGRES_HOST=localhost
POSTGRES_PORT=5432
CELERY_BROKER_URL=amqp://arus:3354010@192.168.50.31:5672
CELERY_LOG_LEVEL=WARNING
RMQ_BROKER_URL=amqp://arus:3354010@192.168.50.31:5672
RMQ_CREATE_MESSAGE_EXCHANGE=arus.ut.homer.message
RABBITMQ_USER=homer
RABBITMQ_PASSWORD=3354010
RABBITMQ_DEFAULT_VHOST=/
RABBITMQ_SECURE_PASSWORD="yes"
DJANGO_SUPERUSER_USERNAME=arus
DJANGO_SUPERUSER_PASSWORD=3354010
DJANGO_SUPERUSER_EMAIL=it-office@autorus.ru
DJANGO_LOG_LEVEL=INFO
```

## Console commands:
```bash
# API/Web server: 
gunicorn config.wsgi:application --workers=1 --threads=4 --bind [your_ip]:[your_port]

# Start consumers: 
manage.py startconsume

# Launch migrations: 
manage.py makemigrations && manage.py migrate

# Collect static files: 
manage.py collectstatic

# Run tests: 
manage.py test -v 3

# Celery: main loop
celery -A config worker -B -Q arus.homer.main -n homer.main.%h

# Celery: sending loop
celery -A config worker -Q arus.homer.mail.send,arus.homer.sms.send,arus.homer.push.send,arus.homer.telegram.send -n homer.sending.%h --concurrency 10
```
