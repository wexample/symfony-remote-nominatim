<?php

namespace Wexample\SymfonyRemoteNominatim\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Wexample\PhpRemote\Enum\RemoteState;
use Wexample\PhpRemote\Interface\RateLimiterInterface;
use Wexample\SymfonyGeo\Class\GeoPoint;
use Wexample\SymfonyGeo\Entity\Country;
use Wexample\SymfonyGeo\Interface\GeoLocatedInterface;
use Wexample\SymfonyGeo\Interface\PostalAddressInterface;
use Wexample\SymfonyGeo\Service\GeocodingService;
use Wexample\SymfonyRemote\Class\SymfonyRateLimiter;
use Wexample\SymfonyRemoteNominatim\DependencyInjection\WexampleSymfonyRemoteNominatimExtension;
use Wexample\SymfonyRemoteNominatim\Exception\NominatimException;
use Wexample\SymfonyRemoteNominatim\Service\NominatimClient;

class NominatimClientTest extends TestCase
{
    private function address(
        ?string $street,
        ?string $postCode = null,
        ?string $city = null,
        ?string $country = null
    ): PostalAddressInterface {
        return new class ($street, $postCode, $city, $country ? (new Country())->setIsoAlpha2Code($country) : null) implements PostalAddressInterface {
            public function __construct(
                private readonly ?string $street,
                private readonly ?string $postCode,
                private readonly ?string $city,
                private readonly ?Country $country,
            ) {
            }

            public function getPostalAddress(): ?string
            {
                return $this->street;
            }

            public function getPostCode(): ?string
            {
                return $this->postCode;
            }

            public function getCity(): ?string
            {
                return $this->city;
            }

            public function getCountry(): ?Country
            {
                return $this->country;
            }
        };
    }

    public function testAnAddressIsSearchedFieldByField(): void
    {
        $sent = null;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$sent) {
            $sent = ['url' => $url, 'headers' => $options['headers']];

            return new MockResponse('[{"lat":"48.8698","lon":"2.3311","display_name":"1, Rue de la Paix, Paris"}]');
        });

        $point = (new NominatimClient($http, 'http://nominatim:8080/', 'my-app', 'ops@example.com'))
            ->geocode($this->address("1 rue de la Paix\nBâtiment B", '75002', 'Paris', 'FR'));

        $this->assertSame(48.8698, $point->latitude);
        $this->assertSame(2.3311, $point->longitude);

        parse_str(parse_url($sent['url'], PHP_URL_QUERY), $query);
        $this->assertStringStartsWith('http://nominatim:8080/search?', $sent['url']);
        $this->assertSame(['street' => '1 rue de la Paix', 'postalcode' => '75002', 'city' => 'Paris', 'countrycodes' => 'fr', 'format' => 'jsonv2', 'limit' => '1', 'email' => 'ops@example.com'], $query);
        $this->assertContains('User-Agent: my-app', $sent['headers']);
    }

    public function testAnUnknownAddressIsNoError(): void
    {
        $client = new NominatimClient(new MockHttpClient(new MockResponse('[]')), 'http://nominatim');

        $this->assertNull($client->geocode($this->address('Nowhere street', city: 'Atlantis')));
    }

    public function testACountryAloneIsNotSearched(): void
    {
        $client = new NominatimClient(new MockHttpClient(fn () => $this->fail('Nothing to ask.')), 'http://nominatim');

        $this->assertNull($client->geocode($this->address(null, country: 'BE')));
    }

    public function testARefusalIsAnError(): void
    {
        $client = new NominatimClient(new MockHttpClient(new MockResponse('Too many requests', ['http_code' => 429])), 'http://nominatim');

        $this->expectException(NominatimException::class);
        $client->geocode($this->address('1 rue de la Paix', city: 'Paris'));
    }

    public function testStatusFollowsTheService(): void
    {
        $up = new NominatimClient(new MockHttpClient(new MockResponse('{"status":0,"message":"OK"}')), 'http://nominatim');
        $importing = new NominatimClient(new MockHttpClient(new MockResponse('{"status":700,"message":"Database connection failed"}', ['http_code' => 500])), 'http://nominatim');

        $this->assertSame(RemoteState::Up, $up->checkStatus()->state);
        $this->assertSame(RemoteState::Down, $importing->checkStatus()->state);
        $this->assertSame(RemoteState::Unconfigured, (new NominatimClient(new MockHttpClient(), null))->checkStatus()->state);
    }

    public function testItIsTheGeocoderOfGeo(): void
    {
        $client = new NominatimClient(new MockHttpClient(new MockResponse('[{"lat":"50.85","lon":"4.35"}]')), 'http://nominatim');
        $address = new class () implements PostalAddressInterface, GeoLocatedInterface {
            public ?GeoPoint $point = null;

            public function getPostalAddress(): ?string
            {
                return 'Grand-Place 1';
            }

            public function getPostCode(): ?string
            {
                return '1000';
            }

            public function getCity(): ?string
            {
                return 'Bruxelles';
            }

            public function getCountry(): ?Country
            {
                return null;
            }

            public function getGeoPoint(): ?GeoPoint
            {
                return $this->point;
            }

            public function setGeoPoint(?GeoPoint $point): static
            {
                $this->point = $point;

                return $this;
            }
        };

        $this->assertTrue((new GeocodingService($client))->locate($address));
        $this->assertSame(50.85, $address->point->latitude);
    }

    public function testAnAddressThatCannotBePlacedIsNotAskedAgainForAWhile(): void
    {
        $asked = 0;
        $answers = ['[]', '[{"lat":"48.8698","lon":"2.3311"}]'];
        $http = new MockHttpClient(function () use (&$asked, &$answers) {
            ++$asked;

            return new MockResponse(array_shift($answers));
        });
        $client = new NominatimClient($http, 'http://nominatim', cache: new ArrayAdapter());

        $this->assertNull($client->geocode($this->address('Nowhere street', city: 'Atlantis')));
        $this->assertNull($client->geocode($this->address('Nowhere street', city: 'Atlantis')));
        $this->assertSame(1, $asked);

        // Another spelling is another question.
        $this->assertSame(48.8698, $client->geocode($this->address('1 rue de la Paix', city: 'Paris'))->latitude);
        $this->assertSame(2, $asked);
    }

    public function testAPlacedAddressIsNotCached(): void
    {
        $asked = 0;
        $http = new MockHttpClient(function () use (&$asked) {
            ++$asked;

            return new MockResponse('[{"lat":"48.8698","lon":"2.3311"}]');
        });
        $client = new NominatimClient($http, 'http://nominatim', cache: new ArrayAdapter());

        $client->geocode($this->address('1 rue de la Paix', city: 'Paris'));
        $client->geocode($this->address('1 rue de la Paix', city: 'Paris'));

        $this->assertSame(2, $asked);
    }

    public function testARefusalIsNotCached(): void
    {
        $answers = [new MockResponse('Too many requests', ['http_code' => 429]), new MockResponse('[]')];
        $client = new NominatimClient(new MockHttpClient(function () use (&$answers) {
            return array_shift($answers);
        }), 'http://nominatim', cache: new ArrayAdapter());

        try {
            $client->geocode($this->address('1 rue de la Paix', city: 'Paris'));
            $this->fail('A refusal is an error.');
        } catch (NominatimException) {
        }

        $this->assertNull($client->geocode($this->address('1 rue de la Paix', city: 'Paris')));
    }

    public function testOnlyThePublicInstanceWaitsItsTurn(): void
    {
        $limiter = new class () implements RateLimiterInterface {
            public int $acquired = 0;

            public function acquire(): void
            {
                ++$this->acquired;
            }
        };
        $http = new MockHttpClient(fn () => new MockResponse('[]'));

        (new NominatimClient($http, 'http://nominatim:8080', rateLimiter: $limiter))->geocode($this->address('1 rue de la Paix', city: 'Paris'));
        $this->assertSame(0, $limiter->acquired);

        (new NominatimClient($http, 'https://'.NominatimClient::PUBLIC_HOST, rateLimiter: $limiter))->geocode($this->address('1 rue de la Paix', city: 'Paris'));
        $this->assertSame(1, $limiter->acquired);
    }

    public function testTheLimiterIsTheFrameworksOneASecond(): void
    {
        $container = new ContainerBuilder();
        $extension = new WexampleSymfonyRemoteNominatimExtension();

        $extension->prepend($container);
        $this->assertSame(
            ['policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 second', 'lock_factory' => 'auto'],
            $container->getExtensionConfig('framework')[0]['rate_limiter'][WexampleSymfonyRemoteNominatimExtension::LIMITER]
        );

        $extension->load([['url' => 'https://'.NominatimClient::PUBLIC_HOST]], $container);
        $limiter = $container->getDefinition(WexampleSymfonyRemoteNominatimExtension::SERVICE_RATE_LIMITER);
        $this->assertSame(SymfonyRateLimiter::class, $limiter->getClass());
        $this->assertEquals(
            [new Reference('limiter.'.WexampleSymfonyRemoteNominatimExtension::LIMITER), 'create'],
            $limiter->getArgument(0)->getFactory()
        );
        $this->assertSame(
            WexampleSymfonyRemoteNominatimExtension::SERVICE_RATE_LIMITER,
            (string) $container->getDefinition(NominatimClient::class)->getArgument('$rateLimiter')
        );
    }

    public function testWithoutUrlNothingIsAsked(): void
    {
        $this->expectException(NominatimException::class);

        (new NominatimClient(new MockHttpClient(), null))->geocode($this->address('1 rue de la Paix', city: 'Paris'));
    }
}
