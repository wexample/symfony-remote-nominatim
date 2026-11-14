<?php

namespace Wexample\SymfonyRemoteNominatim\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\RateLimiter\LimiterInterface;
use Wexample\SymfonyGeo\Interface\GeocoderInterface;
use Wexample\SymfonyHelpers\DependencyInjection\AbstractWexampleSymfonyExtension;
use Wexample\SymfonyRemote\Class\SymfonyRateLimiter;
use Wexample\SymfonyRemoteNominatim\Service\NominatimClient;

class WexampleSymfonyRemoteNominatimExtension extends AbstractWexampleSymfonyExtension
{
    public const string LIMITER = 'wexample_remote_nominatim';

    public const string SERVICE_RATE_LIMITER = 'wexample_symfony_remote_nominatim.rate_limiter';

    /**
     * The public instance's policy, one request a second, as a framework rate
     * limiter: counted in the app's storage, so across every process of the
     * app, the way symfony-remote declares its clients' quotas.
     */
    public function prepend(ContainerBuilder $container): void
    {
        parent::prepend($container);

        $container->prependExtensionConfig('framework', [
            'rate_limiter' => [
                self::LIMITER => [
                    'policy' => 'fixed_window',
                    'limit' => 1,
                    'interval' => '1 second',
                    // Without symfony/lock the framework would refuse the
                    // limiter; "auto" uses a lock only where the app has one.
                    'lock_factory' => 'auto',
                ],
            ],
        ]);
    }

    public function load(
        array $configs,
        ContainerBuilder $container
    ): void {
        $config = $this->processConfiguration(new Configuration(), $configs);
        $container->setParameter('wexample_symfony_remote_nominatim.url', $config['url']);
        $container->setParameter('wexample_symfony_remote_nominatim.user_agent', $config['user_agent']);
        $container->setParameter('wexample_symfony_remote_nominatim.email', $config['email']);

        $container->setDefinition(self::SERVICE_RATE_LIMITER, new Definition(SymfonyRateLimiter::class, [
            (new Definition(LimiterInterface::class))
                ->setFactory([new Reference('limiter.'.self::LIMITER), 'create'])
                ->setArguments(['nominatim']),
        ]));

        $this->loadConfig(
            __DIR__,
            $container
        );

        // Without a URL there is no geocoder: GeocodingService then locates nothing
        // and fails nowhere, rather than calling a client that can only throw.
        if ($config['url']) {
            $container->setAlias(GeocoderInterface::class, NominatimClient::class);
        }
    }
}
