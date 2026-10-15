# omnibus/db-schenker

DB Schenker for [glitchr/omnibus](https://github.com/glitchr-studio/omnibus): tracking through
eSchenker's public tracking (no credentials), and land-transport bookings through the Schenker
Open API when an API key is configured. Prices come from configuration (`rates`): Schenker quotes
by contract.

```php
$gateway = (new DbSchenkerGatewayFactory($http))->create($options);   // $http: the application's HTTP client - none given, the factory makes its own; the options below
```

No framework needed: the package requires `glitchr/omnibus` and `symfony/http-client`. In a
Symfony application, the same through the bundle's configuration:

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

The booking action is built on the Open API's published shape and is **unverified**: it needs an
account's key (from the [Schenker partner portal](https://www.dbschenker.com/global/digital-solutions/api))
to be run against the service. Tracking is live.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by DB Schenker (now part of DSV A/S). It reaches their service on behalf of whoever runs
it, and it does not redistribute their data beyond the short excerpts recorded as test fixtures. Its
MIT licence covers this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with DSV and DB Schenker's terms
([DSV API terms and conditions](https://developer.dsv.com/terms-and-conditions)), for obtaining
whatever they require (an account, a key, a licence or subscription, an attribution or a citation, a
rate limit to respect), and for any use made of what it returns. The authors publish it free of
charge, make no commercial use of it, and accept no liability for how it is used (see
[LICENSE](LICENSE)).

License: MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.
