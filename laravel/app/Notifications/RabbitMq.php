<?php

namespace App\Notifications;

use App\Http\JsonPayload;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Connection\AMQPConnectionConfig;
use PhpAmqpLib\Connection\AMQPConnectionFactory;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use Throwable;

final class RabbitMq
{
    public function consume(MessageIntake $intake, string $url, string $exchange, bool $once): void
    {
        $connection = $this->connect($url);
        try {
            $channel = $connection->channel();
            $channel->exchange_declare($exchange, 'direct', false, true, false);
            $channel->queue_declare($exchange, false, true, false, false);
            $channel->queue_bind($exchange, $exchange, '');
            $channel->basic_qos(0, 10, false);
            $channel->basic_consume($exchange, '', false, false, false, false, function (AMQPMessage $message) use ($intake, $once): void {
                $processed = false;
                try {
                    $intake->create(array_map('get_object_vars', JsonPayload::objects($message->getBody())));
                    $processed = true;
                } catch (ValidationException) {
                    $message->reject(false);
                } catch (Throwable $error) {
                    logger()->warning('RabbitMQ intake failed. Delivery will be requeued.', [
                        'error_class' => $error::class,
                        'redelivered' => $message->isRedelivered(),
                    ]);
                    $message->reject(true);

                    // Leave the loop so the broker cannot immediately redeliver to this consumer.
                    throw $error;
                }
                if ($processed) {
                    $message->ack();
                }

                if ($once) {
                    $message->getChannel()->basic_cancel($message->getConsumerTag());
                }
            });

            while ($channel->is_consuming()) {
                try {
                    $channel->wait(null, false, $once ? 10 : 5);
                } catch (AMQPTimeoutException) {
                    if ($once) {
                        break;
                    }
                }
            }
        } finally {
            $this->close($connection);
        }
    }

    public function publish(string $url, string $exchange, string $routingKey, array $payload, string $id): void
    {
        $connection = $this->connect($url);
        try {
            $channel = $connection->channel();
            $confirmed = false;
            $returned = false;
            $channel->set_ack_handler(function () use (&$confirmed): void { $confirmed = true; });
            $channel->set_return_listener(function () use (&$returned): void { $returned = true; });
            $channel->confirm_select();
            $channel->basic_publish(new AMQPMessage(json_encode($payload, JSON_THROW_ON_ERROR), [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'message_id' => $id,
            ]), $exchange, $routingKey, true);
            $channel->wait_for_pending_acks_returns(8);
            if (! $confirmed || $returned) {
                throw new DeliveryFailure(
                    $returned ? 'unroutable' : 'broker_not_confirmed',
                    retry: $returned,
                    uncertain: ! $returned,
                );
            }
        } finally {
            $this->close($connection);
        }
    }

    private function close(AbstractConnection $connection): void
    {
        try {
            $connection->close();
        } catch (Throwable $error) {
            logger()->warning('RabbitMQ connection cleanup failed.', ['error_class' => $error::class]);
        }
    }

    private function connect(string $url): AbstractConnection
    {
        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? '';
        if (! in_array($scheme, ['amqp', 'amqps'], true) || empty($parts['host'])) {
            throw new InvalidArgumentException('Invalid AMQP connection URL.');
        }
        $config = new AMQPConnectionConfig;
        $config->setHost($parts['host']);
        $config->setPort($parts['port'] ?? ($scheme === 'amqps' ? 5671 : 5672));
        $config->setUser(rawurldecode($parts['user'] ?? 'guest'));
        $config->setPassword(rawurldecode($parts['pass'] ?? 'guest'));
        $config->setVhost(rawurldecode(substr($parts['path'] ?? '', 1)) ?: '/');
        $config->setConnectionTimeout(5);
        $config->setReadTimeout(15);
        $config->setWriteTimeout(15);
        $config->setHeartbeat(5);
        $config->setIsSecure($scheme === 'amqps');
        $config->setSslVerify(true);
        $config->setSslVerifyName(true);

        return AMQPConnectionFactory::create($config);
    }
}
