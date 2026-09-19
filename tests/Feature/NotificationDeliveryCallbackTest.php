<?php

namespace Tests\Feature;

use App\Services\NotificationDeliveryService;
use Mockery\MockInterface;
use Tests\TestCase;

class NotificationDeliveryCallbackTest extends TestCase
{
    public function test_database_callback_attempts_only_its_exact_quiz_receipt(): void
    {
        $deliveryId = '660e8400-e29b-41d4-a716-446655440000';
        $dispatchToken = '770e8400-e29b-41d4-a716-446655440000';

        $this->mock(NotificationDeliveryService::class, function (MockInterface $mock) use ($deliveryId, $dispatchToken): void {
            $mock->shouldReceive('deliverQuizReceiptByCapabilityNow')
                ->once()
                ->with($deliveryId, $dispatchToken)
                ->andReturn(['sent' => true, 'queued' => true]);
        });

        $response = $this->postJson('/api/notification-deliveries/quiz-receipt', [
            'delivery_id' => $deliveryId,
            'dispatch_token' => $dispatchToken,
        ]);

        $response->assertStatus(202)
            ->assertExactJson(['accepted' => true]);
        $this->assertStringContainsString(
            'no-store',
            (string) $response->headers->get('Cache-Control'),
        );
    }

    public function test_database_callback_rejects_malformed_capabilities(): void
    {
        $this->mock(NotificationDeliveryService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('deliverQuizReceiptByCapabilityNow');
        });

        $response = $this->postJson('/api/notification-deliveries/quiz-receipt', [
            'delivery_id' => 'not-a-uuid',
            'dispatch_token' => 'not-a-uuid',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['delivery_id', 'dispatch_token']);
    }
}
