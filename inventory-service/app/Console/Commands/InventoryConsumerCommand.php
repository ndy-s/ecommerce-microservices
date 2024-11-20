<?php

namespace App\Console\Commands;

use Ecommerce\Shared\Services\MessageQueue\RabbitMQService;
use Exception;
use Illuminate\Console\Command;

class InventoryConsumerCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'inventory:consume';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Consume order created events';

    private RabbitMQService $rabbitMQService;

    /**
     * @throws Exception
     */
    public function __construct()
    {
        parent::__construct();
        $config = config('queue.connections.rabbitmq');

        if (!$config) {
            throw new Exception('RabbitMQ configuration is missing.');
        }

        // Instantiate RabbitMQService with the required configuration
        $this->rabbitMQService = new RabbitMQService($config);
    }

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->rabbitMQService->consumeMessages('order_created', function ($message) {
            try {
                // Decode the message body
                $orderData = json_decode($message->body, true);

                // Log order data directly to the console
                $this->line('Order data received for processing: ' . json_encode($orderData, JSON_PRETTY_PRINT));

                // Check if the payload and order_id are present in the decoded data
                if (!isset($orderData['payload']['order_id'])) {
                    throw new \Exception('Order ID is missing in the payload');
                }

                // Extract order_id from the payload
                $orderId = $orderData['payload']['order_id'];

                // Publish to the next queue
                $this->rabbitMQService->publishMessage('inventory_processed', [
                    'order_id' => $orderId,
                ]);

                // Acknowledge the message
                $message->delivery_info['channel']->basic_ack($message->delivery_info['delivery_tag']);

                // Log success message to the console
                $this->info('Order processed successfully for Order ID: ' . $orderId);
            } catch (\Exception $e) {
                // Log error to the console instead of a file
                $this->error('Inventory processing error: ' . $e->getMessage());
                $this->error('Message body: ' . $message->body ?? 'N/A');

                // Optionally reject the message and requeue it
                if (isset($message->delivery_info)) {
                    $message->delivery_info['channel']->basic_reject($message->delivery_info['delivery_tag'], true);
                }
            }
        });
    }
}
