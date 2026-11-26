<?php

namespace App\Notifications;

use App\Http\JsonPayload;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use PDOException;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Connection\AMQPConnectionConfig;
use PhpAmqpLib\Connection\AMQPConnectionFactory;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

final class RabbitMq
{
    private const RETRY_DELAYS = [1, 2, 4];

    public function __construct(private readonly ProviderAmqpEndpoint $endpoint) {}

    public function consume(MessageIntake $intake, string $url, string $exchange, bool $once): void
    {
        $connection = $this->connect($url);
        try {
            $channel = $connection->channel();
            $channel->exchange_declare($exchange, 'direct', false, true, false);
            $channel->queue_declare($exchange, false, true, false, false);
            $channel->queue_bind($exchange, $exchange, '');
            $failedQueue = $exchange.'.failed';
            $channel->queue_declare($failedQueue, false, true, false, false);
            $channel->basic_qos(0, 10, false);
            $channel->basic_consume($exchange, '', false, false, false, false, function (AMQPMessage $message) use ($intake, $connection, $failedQueue, $once): void {
                $this->process($intake, $connection, $failedQueue, $message);
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

    private function process(MessageIntake $intake, AbstractConnection $connection, string $failedQueue, AMQPMessage $message): void
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                $intake->create(array_map('get_object_vars', JsonPayload::objects($message->getBody())));
                break;
            } catch (ValidationException) {
                $message->reject(false);

                return;
            } catch (Throwable $error) {
                $delay = self::RETRY_DELAYS[$attempt] ?? null;
                $sqlstate = $error instanceof PDOException ? ($error->errorInfo[0] ?? $error->getCode()) : null;
                if ($delay === null || ! preg_match('/\A(?:(?:08|40|53)[0-9A-Z]{3}|57P0[123])\z/', (string) $sqlstate)) {
                    logger()->error('RabbitMQ intake failed. Delivery will be moved to the failed queue.', [
                        'error_class' => $error::class, 'sqlstate' => $sqlstate, 'attempt' => $attempt + 1,
                    ]);
                    $properties = array_intersect_key($message->get_properties(), array_flip([
                        'content_type', 'content_encoding', 'message_id', 'correlation_id',
                    ]));
                    $this->publishConfirmed($connection, '', $failedQueue, new AMQPMessage($message->getBody(), $properties + [
                        'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                        'application_headers' => new AMQPTable(['signal-intake-attempts' => $attempt + 1]),
                    ]));
                    break;
                }
                logger()->warning('RabbitMQ intake retry scheduled.', [
                    'sqlstate' => $sqlstate, 'attempt' => $attempt + 1, 'delay_seconds' => $delay,
                ]);
                $connection->checkHeartBeat();
                sleep($delay);
                $connection->checkHeartBeat();
            }
        }

        // A failed publish leaves the original unacknowledged for redelivery.
        $message->ack();
    }

    public function publish(string $url, string $exchange, string $routingKey, array $payload, string $id): void
    {
        $connection = $this->connect($url, outgoing: true);
        try {
            $this->publishConfirmed($connection, $exchange, $routingKey, new AMQPMessage(json_encode($payload, JSON_THROW_ON_ERROR), [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'message_id' => $id,
            ]));
        } finally {
            $this->close($connection);
        }
    }

    private function publishConfirmed(AbstractConnection $connection, string $exchange, string $routingKey, AMQPMessage $message): void
    {
        $channel = $connection->channel();
        try {
            $confirmed = false;
            $returned = false;
            $channel->set_ack_handler(function () use (&$confirmed): void { $confirmed = true; });
            $channel->set_return_listener(function () use (&$returned): void { $returned = true; });
            $channel->confirm_select();
            $channel->basic_publish($message, $exchange, $routingKey, true);
            $channel->wait_for_pending_acks_returns(8);
            if (! $confirmed || $returned) {
                throw new DeliveryFailure(
                    $returned ? 'unroutable' : 'broker_not_confirmed',
                    retry: $returned,
                    uncertain: ! $returned,
                );
            }
        } finally {
            $channel->close();
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

    private function connect(string $url, bool $outgoing = false): AbstractConnection
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower(rtrim($parts['host'] ?? '', '.'));
        $port = $parts['port'] ?? ($scheme === 'amqps' ? 5671 : 5672);
        if (! in_array($scheme, ['amqp', 'amqps'], true) || $host === '' || $port < 1) {
            throw new InvalidArgumentException('Invalid AMQP connection URL.');
        }
        $addresses = $outgoing ? $this->endpoint->addresses($scheme, $host, $port) : [$parts['host']];
        $config = new AMQPConnectionConfig;
        $config->setPort($port);
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

        if ($outgoing && $scheme === 'amqps') {
            $config->setStreamContext(stream_context_create(['ssl' => [
                'peer_name' => trim($host, '[]'),
                'SNI_enabled' => true,
            ]]));
        }

        foreach ($addresses as $index => $address) {
            $config->setHost($address);
            try {
                return AMQPConnectionFactory::create($config);
            } catch (Throwable $error) {
                if ($index === array_key_last($addresses)) {
                    throw $error;
                }
            }
        }
    }
}
