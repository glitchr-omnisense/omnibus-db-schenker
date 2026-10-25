<?php

namespace Omnibus\DbSchenker\Tests;

use Omnibus\DbSchenker\DbSchenkerGatewayFactory;
use Omnibus\Model\TrackingStatus;
use Omnibus\Request\Shipping;
use Omnibus\Testing\Fixtures;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class DbSchenkerGatewayTest extends TestCase
{
    private array $calls = [];

    private function gateway(bool $booking = false): \Omnibus\GatewayInterface
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->calls[] = [$method, $url, \is_string($options['body'] ?? null) && str_starts_with($options['body'], '{') ? json_decode($options['body'], true) : [], $options['headers']];

            return match (true) {
                str_contains($url, 'eschenker.dbschenker.com') => new MockResponse(json_encode(['shipments' => [['shipmentNumber' => '1234567890', 'status' => 'Delivered', 'events' => [
                    ['eventDate' => '2026-10-02T09:40:00', 'eventCode' => 'DLV', 'eventDescription' => 'Delivered', 'location' => ['city' => 'Lyon', 'country' => 'FR']],
                    ['eventDate' => '2026-09-30T18:00:00', 'eventCode' => 'PUP', 'eventDescription' => 'Picked up', 'location' => ['city' => 'Paris', 'country' => 'FR']],
                ]]]])),
                str_contains($url, 'api.dbschenker.com') => new MockResponse(json_encode(['bookingNumber' => 'BK-2026-000042', 'labelUrl' => 'https://api.dbschenker.com/booking/v1/bookings/BK-2026-000042/label.pdf'])),
                default => new MockResponse('{}', ['http_code' => 404]),
            };
        });

        return (new DbSchenkerGatewayFactory($http))->create(['rates' => [['service' => 'SYSTEM', 'label' => 'DB Schenker System', 'bands' => [30000 => 2900]]]] + ($booking ? ['api_key' => 'key', 'account_number' => 'ACC1'] : []));
    }

    public function testPublicTrackingNeedsNoCredentials(): void
    {
        $tracking = $this->gateway()->track('1234567890');
        self::assertSame(TrackingStatus::DELIVERED, $tracking->status);
        self::assertSame('Picked up', $tracking->events[0]->description);
        self::assertSame('Lyon FR', $tracking->latest()->location);
        self::assertStringContainsString('refNumber=1234567890', $this->calls[0][1]);
        self::assertSame(2900, $this->gateway()->rate(Fixtures::shipment())[0]->amount);
    }

    public function testBookingsOnlyWithTheOpenApiKey(): void
    {
        self::assertFalse($this->gateway()->supports(Shipping::class));
        $label = $this->gateway(true)->ship(Fixtures::shipment());
        self::assertSame('BK-2026-000042', $label->trackingNumber);
        self::assertStringEndsWith('label.pdf', $label->url);
        self::assertContains('X-API-Key: key', $this->calls[0][3]);
        self::assertSame('SYSTEM', $this->calls[0][2]['product']);
    }
}
