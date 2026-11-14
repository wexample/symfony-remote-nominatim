<?php

namespace Wexample\SymfonyRemoteNominatim\Service;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Wexample\PhpRemote\Class\RemoteStatus;
use Wexample\PhpRemote\Interface\RateLimiterInterface;
use Wexample\PhpRemote\Interface\RemoteInterface;
use Wexample\SymfonyGeo\Class\GeoPoint;
use Wexample\SymfonyGeo\Interface\GeocoderInterface;
use Wexample\SymfonyGeo\Interface\PostalAddressInterface;
use Wexample\SymfonyRemoteNominatim\Exception\NominatimException;

/**
 * symfony-geo's geocoder backed by Nominatim, OpenStreetMap's: a self-hosted
 * instance, or the public one within its usage policy (one request a second, a
 * user agent that says who asks). The address is sent as a structured query,
 * field by field, and the best match is kept. Its health (GET /status) is checked
 * as a remote.
 *
 * Against the public instance, requests wait their turn on the rate limiter,
 * one a second for every process of the app; a self-hosted one is asked as
 * fast as it answers.
 */
class NominatimClient implements GeocoderInterface, RemoteInterface
{
    public const string PUBLIC_HOST = 'nominatim.openstreetmap.org';

    /**
     * How long an address no match was found for is not asked again. Short:
     * the address is likely being corrected, and a new spelling is a new
     * question anyway.
     */
    public const int UNPLACED_TTL = 3600;

    public function __construct(
        private readonly ?HttpClientInterface $httpClient = null,
        private readonly ?string $baseUrl = null,
        private readonly string $userAgent = 'wexample-symfony-remote-nominatim',
        private readonly ?string $email = null,
        private readonly ?CacheInterface $cache = null,
        private readonly ?RateLimiterInterface $rateLimiter = null,
    ) {
    }

    public function getKey(): string
    {
        return 'nominatim';
    }

    public function getLabel(): string
    {
        return 'Nominatim';
    }

    public function checkStatus(): RemoteStatus
    {
        if (! $this->isConfigured()) {
            return RemoteStatus::unconfigured('No Nominatim URL.');
        }

        $response = $this->request('/status', ['format' => 'json']);

        // A Nominatim still importing its data answers, but with a non-zero status.
        return 200 === $response->getStatusCode() && 0 === ($response->toArray(false)['status'] ?? null)
            ? RemoteStatus::up()
            : RemoteStatus::down('HTTP '.$response->getStatusCode().': '.$response->getContent(false));
    }

    public function geocode(PostalAddressInterface $address): ?GeoPoint
    {
        $query = array_filter([
            'street' => trim(strtok((string) $address->getPostalAddress(), "\r\n") ?: ''),
            'postalcode' => $address->getPostCode(),
            'city' => $address->getCity(),
            'countrycodes' => strtolower((string) $address->getCountry()?->getIsoAlpha2Code()),
        ]);

        // A country alone is no address: it would pin the middle of the country.
        if ([] === array_diff_key($query, ['countrycodes' => true])) {
            return null;
        }

        if (null === $this->cache) {
            return $this->search($query);
        }

        // Only what found no match is kept: a point found is written on the
        // address, which is not asked again. An address that cannot be placed
        // would otherwise be asked at each save, a second of the public
        // instance's quota every time.
        return $this->cache->get(
            'wexample_remote_nominatim_'.hash('xxh128', $this->baseUrl."\0".json_encode($query)),
            function (ItemInterface $item, bool &$save) use ($query): ?GeoPoint {
                $point = $this->search($query);

                if (null === $point) {
                    $item->expiresAfter(self::UNPLACED_TTL);
                } else {
                    $save = false;
                }

                return $point;
            }
        );
    }

    /**
     * @param array<string, string> $query
     */
    private function search(array $query): ?GeoPoint
    {
        $response = $this->request('/search', [...$query, 'format' => 'jsonv2', 'limit' => 1]);

        if (200 !== $response->getStatusCode()) {
            throw new NominatimException('Nominatim refused the search (HTTP '.$response->getStatusCode().'): '.$response->getContent(false));
        }

        $match = $response->toArray()[0] ?? null;

        return $match ? new GeoPoint((float) $match['lat'], (float) $match['lon']) : null;
    }

    public function isConfigured(): bool
    {
        return null !== $this->httpClient && null !== $this->baseUrl && '' !== $this->baseUrl;
    }

    private function request(
        string $path,
        array $query
    ): ResponseInterface {
        if (! $this->isConfigured()) {
            throw new NominatimException('Nominatim is not configured (wexample_symfony_remote_nominatim.url).');
        }

        // Told apart when asked rather than when the container is built: the
        // URL is most often an environment variable.
        if (self::PUBLIC_HOST === parse_url((string) $this->baseUrl, PHP_URL_HOST)) {
            $this->rateLimiter?->acquire();
        }

        return $this->httpClient->request('GET', rtrim((string) $this->baseUrl, '/').$path, [
            'query' => array_filter([...$query, 'email' => $this->email]),
            'headers' => ['User-Agent' => $this->userAgent],
        ]);
    }
}
