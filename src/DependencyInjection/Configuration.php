<?php
/*
 * This file is part of contao-phpbbBridge
 * 
 * Copyright (c) 2015-2016 Daniel Schwiperich
 * 
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 * 
 */

namespace Ctsmedia\Phpbb\BridgeBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;


/**
 * Default Configuration Settings
 *
 * @package Ctsmedia\Phpbb\BridgeBundle\DependencyInjection
 * @author Daniel Schwiperich <https://github.com/DanielSchwiperich>
 */
class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder()
    {

        $treeBuilder = new TreeBuilder('phpbb_bridge');
        $treeBuilder
            ->getRootNode()
            ->children()
                ->arrayNode('db')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('table_prefix')
                            ->defaultValue('phpbb_')
                        ->end()
                    ->end()
                ->end()
                ->scalarNode('dir')
                    ->defaultValue('phpBB3')
                ->end()
                ->booleanNode('allow_external_ip_access')
                    ->defaultFalse()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}