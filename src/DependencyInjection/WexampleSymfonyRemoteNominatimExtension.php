<?php

namespace Wexample\SymfonyRemoteNominatim\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Wexample\SymfonyGeo\Interface\GeocoderInterface;
use Wexample\SymfonyHelpers\DependencyInjection\AbstractWexampleSymfonyExtension;
use Wexample\SymfonyRemoteNominatim\Service\NominatimClient;

class WexampleSymfonyRemoteNominatimExtension extends AbstractWexampleSymfonyExtension
{
    public function load(
        array $configs,
        ContainerBuilder $container
    ): void {
        $config = $this->processConfiguration(new Configuration(), $configs);
        $container->setParameter('wexample_symfony_remote_nominatim.url', $config['url']);
        $container->setParameter('wexample_symfony_remote_nominatim.user_agent', $config['user_agent']);
        $container->setParameter('wexample_symfony_remote_nominatim.email', $config['email']);

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
