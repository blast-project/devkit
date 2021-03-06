<?php

/*
 * This file is part of the Blast Project package.
 *
 * Copyright (C) 2015-2017 Libre Informatique
 *
 * This file is licenced under the GNU LGPL v3.
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace Blast\DevKit\Console;

use Blast\DevKit\Console\Command\DispatchCommand;
use Blast\DevKit\Console\Command\PrAutoMergeCommand;
use Blast\DevKit\Console\Command\PrAutoCloseCommand;
use Blast\DevKit\Console\Command\SrcMigrationCommand;
use Symfony\Component\Console\Application as BaseApplication;

/**
 * @author Glenn Cavarle <glenn.cavarle@libre-informatique.fr>
 */
class Application extends BaseApplication
{
    /**
     * {@inheritdoc}
     */
    public function __construct()
    {
        parent::__construct();

        $this->add(new DispatchCommand());
        $this->add(new PrAutoMergeCommand());
        $this->add(new SrcMigrationCommand());
        $this->add(new PrAutoCloseCommand());
    }
}
