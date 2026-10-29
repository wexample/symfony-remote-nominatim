# symfony-remote-nominatim

Version: 1.0.6

Geocoder for symfony-geo backed by a Nominatim service (OpenStreetMap), self-hosted or public, declared as a symfony-remote

## Table of Contents

- [Installation](#installation)
- [Introduction](#introduction)

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

`symfony-remote-nominatim` is the geocoder of `symfony-geo` backed by Nominatim, OpenStreetMap's geocoder. `NominatimClient` implements geo's `GeocoderInterface`, so `GeocodingService` places addresses through it, and it is declared as a `symfony-remote`, so `remote:status` checks the service.

Any Nominatim answers it: a self-hosted instance (the `mediagis/nominatim` image, fed one region's OpenStreetMap extract), or the public one at `https://nominatim.openstreetmap.org` within its usage policy: one request a second, a user agent that says who asks, no bulk geocoding.
