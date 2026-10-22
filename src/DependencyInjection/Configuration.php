<?php

namespace Wexample\SymfonyRemoteNominatim\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('wexample_symfony_remote_nominatim');

        $treeBuilder->getRootNode()
            ->children()
                ->scalarNode('url')->defaultNull()->info('Base URL of a Nominatim service, e.g. http://nominatim:8080 or https://nominatim.openstreetmap.org.')->end()
                ->scalarNode('user_agent')->defaultValue('wexample-symfony-remote-nominatim')->info('Who is asking: the public instance refuses anonymous clients.')->end()
                ->scalarNode('email')->defaultNull()->info('A contact for the public instance, sent with each request.')->end()
            ->end();

        return $treeBuilder;
    }
}
