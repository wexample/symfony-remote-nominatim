```php
$geocodingService->locate($address);         // through NominatimClient, once installed
$point = $nominatimClient->geocode($address); // GeoPoint, or null when no place matches
```

The address is sent as a structured query: the first street line, the post code, the city, and the country as a filter. A country alone is not searched, since it would pin the middle of the country. An address no place matches gives null; a service that refuses or cannot be reached throws `NominatimException`.

The public instance answers one request a second: geocode on saving an address, never a whole table in a loop. For batches, host your own.
