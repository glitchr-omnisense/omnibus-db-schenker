# omnibus/db-schenker

DB Schenker for [omnisense/omnibus](https://github.com/glitchr-omnisense/omnibus): tracking through
eSchenker's public tracking (no credentials), and land-transport bookings through the Schenker
Open API when an API key and an account are configured. Schenker quotes prices by contract: they
come from the `rates` option. The gateway calls:

| Operation | API | Request |
|---|---|---|
| track | eSchenker public tracking | `GET https://eschenker.dbschenker.com/nges-portal/api/public/tracking?refNumber={number}` |
| ship | Schenker Open API | `POST https://api.dbschenker.com/booking/v1/bookings` |

There is no sandbox: both addresses are the live ones. Every call has a 20-second timeout.

## Installation

```sh
composer require omnibus/db-schenker
```

Requires PHP 8.2, `omnisense/omnibus` (2.x) and `symfony/http-client` (6.4, 7 or 8); given no
HTTP client, the factory makes one with `HttpClient::create()`. In a Symfony application with
`Omnibus\Bridge\Symfony\OmnibusBundle`, the factory is registered as soon as the package is
installed.

## Credentials

- None for tracking.
- For bookings: a Schenker **Open API key**, from the [Schenker partner
  portal](https://www.dbschenker.com/global/digital-solutions/api), where the account's booking
  profile lives, and the **booking account number**. The key is sent as `X-API-Key`.

## Options

No option is required: `(new DbSchenkerGatewayFactory())->create()` builds a gateway that tracks.

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `api_key` | ?string | `null` | no | the Open API key; with `account_number`, makes bookings possible |
| `account_number` | ?string | `null` | no | the booking account, sent as `accountNumber` |
| `rates` | list | - | no | the only way this gateway rates ([the rates option](https://github.com/glitchr-omnisense/omnibus/blob/2.x/docs/configuration.md#the-rates-option)) |

Shipment options read when booking (`Shipment::$options`):

| Option | Default | |
|---|---|---|
| `description` | `Parcel` | each package's description |

## Capabilities

| Request | Supported |
|---|---|
| `Rating` | only with `rates` (configured prices) |
| `Shipping` | only with `api_key` and `account_number` (the Open API) |
| `Tracking` | yes (public tracking) |
| `Pickup` | no |
| `GetSlip` | no |
| `Cancel` | no |
| `Paperless` | no |

## Behaviour

**Ship.** One `POST /bookings`, unverified (built on the Open API's published shape):

- `product` is `Shipment::$service`, else `SYSTEM` (DB Schenker System);
- `reference` the shipment's reference; `pickupDate` `Shipment::$shippingDate`, else tomorrow
  (`Y-m-d`);
- shipper and consignee: name (the company, else the person), contact name, two street lines,
  postcode, city, country, email, phone;
- `goods`: one entry per parcel, package type `CT` (carton), quantity 1, weight in kilograms
  rounded to two decimals (at least 0.1), length, width and height in centimetres when given, the
  `description`.

The tracking number is the answer's `bookingNumber`, else `shipmentNumber`, else `id`. No document
comes inline: `Label::$content` is `null` and `Label::$url` is the answer's `labelUrl` (else the
first document's `url`), with the format `application/pdf` whatever that link serves.
`trackingUrl` is `https://eschenker.dbschenker.com/app/tracking-public/?refNumber=<number>`. A
response without a number is a `CarrierException` (`DB Schenker booked no shipment.`).
`pickupPoint` is not sent.

**Track.** The public tracking with `refNumber`; the locale is not sent. The shipment is the
answer's first `shipments` entry, else its `shipment`, else the answer itself; its events are read
from `events` (else `trackingEvents`): date from `eventDate` (else `date`, else `timestamp`),
`eventCode` (else `code`) kept as `code`, `eventDescription` (else `description`) as text, `city
country` as location. Events are sorted oldest first. The status is the latest event's; without
events, it is read from the shipment's `status` text. A status is read from the code, else from
words of the text, in this order:

| Code | Or the text contains | `TrackingStatus` |
|---|---|---|
| `DLV`, `POD` | `delivered` | `DELIVERED` |
| `OFD` | `out for delivery` | `OUT_FOR_DELIVERY` |
| | `return` | `RETURNED` |
| | `exception`, `damage`, `refused`, `delay` | `EXCEPTION` |
| `BKD`, `ORD` | `booked`, `order received` | `PENDING` |
| any other code, or any other text | | `IN_TRANSIT` |
| neither | | `UNKNOWN` |

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnibus\DbSchenker\DbSchenkerGatewayFactory;
use Omnibus\Model\Address;
use Omnibus\Model\Parcel;
use Omnibus\Model\Shipment;
use Symfony\Component\HttpClient\HttpClient;

$schenker = (new DbSchenkerGatewayFactory(HttpClient::create()))->create([
    'api_key' => getenv('SCHENKER_API_KEY'),            // leave both out: tracking only
    'account_number' => getenv('SCHENKER_ACCOUNT'),
    'rates' => [
        ['service' => 'SYSTEM', 'label' => 'DB Schenker System', 'days' => 3, 'bands' => [30000 => 2900, 100000 => 5900]],
    ],
]);

$shipment = new Shipment(
    new Address('Glitch Art', ['12 rue de la Paix'], '75002', 'Paris', 'FR', company: 'Glitch Art', phone: '+33102030405'),
    new Address('Ada Lovelace', ['3 place Bellecour'], '69002', 'Lyon', 'FR', email: 'ada@example.org'),
    [new Parcel(12000, 60, 40, 40)],
    reference: 'CMD-1042',
    options: ['description' => 'Framed prints'],
);

foreach ($schenker->rate($shipment) as $rate) {
    printf("%s %s %6.2f %s\n", $rate->service, $rate->label, $rate->amount / 100, $rate->currency);
}

$label = $schenker->ship($shipment);
echo $label->trackingNumber, ' ', $label->url, "\n";

$tracking = $schenker->track($label->trackingNumber);
echo $tracking->status->value, ' ', $tracking->latest()?->location, "\n";
```

In Symfony:

```yaml
omnibus:
    gateways:
        db_schenker:
            factory: db_schenker
            options:
                api_key: '%env(SCHENKER_API_KEY)%'        # optional: bookings
                account_number: '%env(SCHENKER_ACCOUNT)%'
                rates:
                    - { service: SYSTEM, label: 'DB Schenker System', bands: { 30000: 2900 } }
```

## Errors

Every failure is an `Omnibus\Exception\CarrierException` whose `carrier` is `db_schenker`:

- tracking: an HTTP status of 400 or more, or a body that is not JSON: `DB Schenker tracking
  answered HTTP <status>.`; a network error: `DB Schenker tracking failed: ...`;
- booking: an HTTP status of 400 or more: the answer's `message`, else `error`, else its first
  error's `message`, else `HTTP <status>`, its `code` in `carrierCode`; a body that is not JSON:
  `DB Schenker answered HTTP <status> with a body that is not JSON.`; a network error: `DB
  Schenker request failed: ...`; no number: `DB Schenker booked no shipment.`

`Shipping` without `api_key` and `account_number`, `Rating` without `rates`, `Pickup`, `GetSlip`,
`Cancel` and `Paperless` raise `RequestNotSupportedException` before any call.

No failure carries the Open API key: a network error's message has them masked as `***` (the key
parameters of a URL too), its previous is a copy of the HTTP client's exception (the same class and
code), and no trace of the chain keeps its arguments, where the request's options stood ([No secret
in a
failure](https://github.com/glitchr-omnisense/omnibus/blob/2.x/docs/errors.md#no-secret-in-a-failure)).

## Verified, and not

Tested offline: `Tests/DbSchenkerGatewayTest.php` (public tracking without credentials, its events
oldest first with their location, the `refNumber` query; configured rates; bookings supported only
with the key, the `X-API-Key` header, product `SYSTEM`, the booking number and the label's link)
and `Tests/DbSchenkerContractTest.php`, the family's conformance suite on recorded answers. The
example above was run in the core's harness on those recorded answers.

Not verified: the booking action is built on the Open API's published shape and is marked
unverified in the code and the README: it needs an account's key to be run against the service.
The README calls tracking live (the public tracking needs no account); no recorded run against it
is given.

## Responsibility

An independent, unofficial client, not affiliated with DB Schenker (now part of DSV A/S). Whoever
runs it complies with DSV and DB Schenker's terms ([DSV API terms and
conditions](https://developer.dsv.com/terms-and-conditions)): see the README's Responsibility
section.
