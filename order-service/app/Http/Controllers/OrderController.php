<?php

namespace App\Http\Controllers;

use Ecommerce\Shared\DTOs\OrderDTO;
use Ecommerce\Shared\Events\OrderCreated;
use Ecommerce\Shared\Services\MessageQueue\RabbitMQService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class OrderController extends Controller
{
    private RabbitMQService $rabbitMQService;

    /**
     * @throws Exception
     */
    public function __construct()
    {
        $config = config('queue.connections.rabbitmq');

        if (!$config) {
            throw new Exception('RabbitMQ configuration is missing.');
        }

        // Instantiate RabbitMQService with the required configuration
        $this->rabbitMQService = new RabbitMQService($config);
    }

    /**
     * Create a new order.
     *
     * @throws Exception
     */
    public function createOrder(Request $request): JsonResponse
    {
        // Validate request
        $validated = $request->validate([
            'user_id' => 'required|integer',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer',
            'items.*.quantity' => 'required|integer|min:1',
            'total' => 'required|numeric|min:0',
        ]);

        try {
            // Create DTO from validated order data
            $orderDTO = new OrderDTO([
                'order_id' => uniqid('order_'),
                'user_id' => $validated['user_id'],
                'total' => $validated['total'],
                'items' => $validated['items'],
            ]);

            // Log the order data (good practice for debugging)
            Log::info('Order placed successfully', ['order' => $orderDTO]);

            // Create and publish the event using DTO
            $event = new OrderCreated($orderDTO);
            $this->rabbitMQService->publishMessage('order_created', $event->toArray());

            return response()->json(['message' => 'Order placed successfully!'], 201);
        } catch (Exception $e) {
            Log::error('Failed to place order', [
                'error' => $e->getMessage(),
                'stack' => $e->getTraceAsString(),
            ]);

            return response()->json(['error' => 'Failed to place order.'], 500);
        }
    }
}
