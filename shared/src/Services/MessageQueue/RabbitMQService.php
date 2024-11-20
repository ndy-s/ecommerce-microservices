<?php

namespace Ecommerce\Shared\Services\MessageQueue;

use Exception;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class RabbitMQService
{
    private AMQPStreamConnection $connection;
    private AMQPChannel $channel;
    private array $config;

    /**
     * @throws Exception
     */
    public function __construct(array $config)
    {
        $this->config = array_merge([
            'host' => 'default_host',
            'port' => 5672,
            'user' => 'default_user',
            'password' => 'default_password',
            'exchange' => 'default_exchange',
            'exchange_type' => 'direct',
            'exchange_declare' => true,
            'queue_declare' => true,
            'queue_bind' => true,
            'vhost' => '/',
        ], $config);

        $this->connection = new AMQPStreamConnection(
            $this->config['host'],
            $this->config['port'],
            $this->config['user'],
            $this->config['password'],
            $this->config['vhost']
        );

        $this->channel = $this->connection->channel();

        if ($this->config['exchange_declare']) {
            $this->declareExchange(
                $this->config['exchange'],
                $this->config['exchange_type']
            );
        }
    }

    /**
     * Declare an exchange
     */
    public function declareExchange(
        string $exchange,
        string $type = 'direct',
        bool $durable = true,
        bool $autoDelete = false
    ): void {
        $this->channel->exchange_declare(
            $exchange,
            $type,
            false,
            $durable,
            $autoDelete
        );
    }

    /**
     * Declare a queue
     */
    public function declareQueue(
        string $queue,
        bool $durable = true,
        bool $exclusive = false,
        bool $autoDelete = false,
        array $arguments = []
    ): void {
        $arguments['x-queue-type'] = ['S', 'quorum'];

        $this->channel->queue_declare(
            $queue,
            false,
            $durable,
            $exclusive,
            $autoDelete,
            false,
            $arguments
        );
    }

    /**
     * Bind queue to exchange
     */
    public function bindQueue(
        string $queue,
        string $exchange,
        string $routingKey
    ): void {
        $this->channel->queue_bind($queue, $exchange, $routingKey);
    }

    /**
     * Publish message to queue
     */
    public function publishMessage(
        string $routingKey,
        array $data,
        ?string $queue = null,
        ?string $exchange = null
    ): void {
        $queue = $queue ?? $routingKey;
        $exchange = $exchange ?? $this->config['exchange'];

        if ($this->config['queue_declare']) {
            $this->declareQueue($queue);
        }

        if ($this->config['queue_bind']) {
            $this->bindQueue($queue, $exchange, $routingKey);
        }

        $msg = new AMQPMessage(
            json_encode($data),
            [
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'content_type' => 'application/json'
            ]
        );

        $this->channel->basic_publish($msg, $exchange, $routingKey);
    }

    /**
     * Consume messages from queue
     */
    public function consumeMessages(
        string $routingKey,
        callable $callback,
        ?string $queue = null,
        ?string $exchange = null
    ): void {
        $queue = $queue ?? $routingKey;
        $exchange = $exchange ?? $this->config['exchange'];

        if ($this->config['queue_declare']) {
            $this->declareQueue($queue);
        }

        if ($this->config['queue_bind']) {
            $this->bindQueue($queue, $exchange, $routingKey);
        }

        // Set prefetch count to 1 to ensure fair dispatch
        $this->channel->basic_qos(0, 1, null);

        $this->channel->basic_consume(
            $queue,
            '',
            false,
            false,
            false,
            false,
            $callback
        );

        while ($this->channel->is_consuming()) {
            try {
                $this->channel->wait();
            } catch (\Exception $e) {
                error_log($e->getMessage());
                continue;
            }
        }
    }

    /**
     * Get the underlying AMQPChannel
     */
    public function getChannel(): AMQPChannel
    {
        return $this->channel;
    }

    /**
     * Close connection
     * @throws Exception
     */
    public function __destruct()
    {
        if ($this->channel->is_open()) {
            $this->channel->close();
        }
        if ($this->connection->isConnected()) {
            $this->connection->close();
        }
    }
}
