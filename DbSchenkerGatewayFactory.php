<?php

namespace Omnibus\DbSchenker;

use Omnibus\Config;
use Omnibus\DbSchenker\Action\ShippingAction;
use Omnibus\DbSchenker\Action\TrackingAction;
use Omnibus\GatewayFactory;
use Symfony\Component\HttpClient\HttpClient;

/**
 *   options:
 *     api_key: null            # the Schenker Open API key, for bookings
 *     account_number: null     # the booking account
 *     rates: [...]             # prices from configuration: Schenker quotes by contract
 *
 * Tracking needs no credentials. Bookings go through the Open API (land
 * transport), unverified until an account's key is at hand: the booking
 * action is built on its published shape and marked so.
 */
final class DbSchenkerGatewayFactory extends GatewayFactory
{
    protected function populateConfig(Config $config): void
    {
        $config->defaults([
            'omnibus.factory_name' => 'db_schenker',
            'omnibus.factory_title' => 'DB Schenker',
            'omnibus.required_options' => [],
            'api_key' => null,
            'account_number' => null,
            'omnibus.api' => function (Config $c) {
                $http = $this->http ?? HttpClient::create();

                return new Api($http, $c['api_key'] ?: null, $c['account_number'] ?: null);
            },
            'omnibus.action.tracking' => new TrackingAction(),
            'omnibus.action.shipping' => static fn (Config $c) => $c['api_key'] && $c['account_number'] ? new ShippingAction() : null,
        ]);
    }
}
