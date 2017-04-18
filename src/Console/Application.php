<?php
/*
 * This file is part of the Blast Project.
 * Copyright (C) 2017 Libre Informatique
 * This file is licenced under the GNU GPL v3.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Blast\DevKit\Console;

use Blast\DevKit\Console\Command\DispatchCommand;
use Blast\DevKit\Console\Command\AutoMergeCommand;
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
        $this->add(new AutoMergeCommand());
    }
}
