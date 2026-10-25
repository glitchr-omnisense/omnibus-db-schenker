<?php

namespace Omnibus\DbSchenker\Tests;

use Omnibus\DbSchenker\DbSchenkerGatewayFactory;
use Omnibus\GatewayFactoryInterface;
use Omnibus\Request\Shipping;
use Omnibus\Testing\GatewayContractTests;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** The conformance suite on DB Schenker's recorded answers (public tracking; bookings, unverified, with the Open API key). */
final class DbSchenkerContractTest extends TestCase
{
    use GatewayContractTests;

    protected function gatewayFactory(HttpClientInterface $http): GatewayFactoryInterface
    {
        return new DbSchenkerGatewayFactory($http);
    }

    protected function gatewayOptions(): array
    {
        return ['api_key' => 'key', 'account_number' => 'ACC1'];
    }

    protected function operationOptions(): array
    {
        return ['api_key' => [Shipping::class], 'account_number' => [Shipping::class]];
    }

    protected function recordedAnswers(): array
    {
        return [
            'eschenker.dbschenker.com' => ['shipments' => [['shipmentNumber' => '1234567890', 'status' => 'Delivered', 'events' => [
                ['eventDate' => '2026-10-02T09:40:00', 'eventCode' => 'DLV', 'eventDescription' => 'Delivered', 'location' => ['city' => 'Lyon', 'country' => 'FR']],
                ['eventDate' => '2026-09-30T18:00:00', 'eventCode' => 'PUP', 'eventDescription' => 'Picked up', 'location' => ['city' => 'Paris', 'country' => 'FR']],
            ]]]],
            'api.dbschenker.com' => ['bookingNumber' => 'BK-2026-000042', 'labelUrl' => 'https://api.dbschenker.com/booking/v1/bookings/BK-2026-000042/label.pdf'],
        ];
    }
}
