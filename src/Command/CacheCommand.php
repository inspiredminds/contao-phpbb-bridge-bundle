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

namespace Ctsmedia\Phpbb\BridgeBundle\Command;

use Contao\System;
use Ctsmedia\Phpbb\BridgeBundle\PhpBB\Connector;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;


/**
 * Cache Command
 *
 * @package Ctsmedia\Phpbb\BridgeBundle\Command
 * @author Daniel Schwiperich <https://github.com/DanielSchwiperich>
 */
class CacheCommand extends Command
{
    protected static $defaultName = 'phpbb_bridge:cache';

    private $connector;

    public function __construct(Connector $connector)
    {
        parent::__construct();

        $this->connector = $connector;
    }

    protected function configure()
    {
        $this
            ->setDescription('Clears phpbb caches und layout files')
            ->addOption(
                'cache-only',
                'c',
                InputOption::VALUE_NONE,
                'Clean only the cache, not generate the layout?'
            );
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('Clearing Forum Cache');
        $this->connector->clearForumCache();

        // Generate the layout if not explicitly asked for cache only
        if(!$input->getOption('cache-only')){
            $output->writeln('Generating Layout Files');
            $this->connector->generateForumLayoutFiles();
        }

        return 0;
    }
}
