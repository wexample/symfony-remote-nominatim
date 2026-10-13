## Installation

```php
// config/bundles.php
Wexample\SymfonyRemote\WexampleSymfonyRemoteBundle::class => ['all' => true],
Wexample\SymfonyGeo\WexampleSymfonyGeoBundle::class => ['all' => true],
Wexample\SymfonyRemoteNominatim\WexampleSymfonyRemoteNominatimBundle::class => ['all' => true],
```

```yaml
# config/packages/wexample_symfony_remote_nominatim.yaml
wexample_symfony_remote_nominatim:
  url: '%env(default::NOMINATIM_URL)%'   # e.g. http://nominatim:8080
  user_agent: 'my-app'                  # required by the public instance
  email: '%env(default::NOMINATIM_EMAIL)%'
```

Without a URL the package installs no geocoder: `GeocodingService::isAvailable()` is false, and addresses keep the coordinates they were given.
