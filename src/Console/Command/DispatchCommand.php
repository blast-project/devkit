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

namespace Blast\DevKit\Console\Command;

use Github\Exception\ExceptionInterface;
use Packagist\Api\Result\Package;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Glenn Cavarlé <glenn.cavarle@libre-informatique.fr>
 */
class DispatchCommand extends AbstractCommand
{
    /**
     * @var \Twig_Environment
     */
    private $twig;

    /**
     * @var string[]
     */
    private $projects;

    protected function configure()
    {
        parent::configure();

        $this
            ->setName('dispatch')
            ->setDescription('Dispatches configuration and documentation files for all blast bunldes.')
            ->addArgument('bundles', InputArgument::IS_ARRAY, 'To limit the dispatcher on given bundle(s). Ex: blast-project::DoctrineSessionBundle', array());
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        parent::initialize($input, $output);

        $this->twig = new \Twig_Environment(
            new \Twig_Loader_Filesystem(__DIR__ . '/../../..')
        );

        $this->configs = $this->computeBundleConfigs($input->getArgument('bundles'));
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->forEachRepoDo(function ($owner, $repoName, $repoConfig) {
            $this->dispatchChanges($owner, $repoName, $repoConfig);
        });

        return 0;
    }

    /**
     * @param type $owner
     * @param type $repoName
     */
    protected function dispatchChanges($owner, $repoName, array $repoConfig)
    {
        try {
            $this->io->title($owner . '/' . $repoName);

            $git = $this->cloneRepository($owner, $repoName);
            $this->applyChanges($owner, $repoName);

            if ($repoConfig['is_project']) {
                $this->moveDocToApp($owner, $repoName);
            }

            $this->pushChanges($git, $owner, $repoName);
        } catch (ExceptionInterface $e) {
            $this->io->error('Failed with message: ' . $e->getMessage());
        }
    }

    /**
     * @param type $repositoryName
     */
    protected function applyChanges($owner, $repositoryName)
    {
        $clonePath = $this->getLocalClonePath($owner, $repositoryName);
        $this->fileSystem->mirror('etc/bundle-skeleton', $clonePath, null, ['override' => true]);
        $result = $this->twig->render('etc/bundle-skeleton/.travis.yml', [
            'github_url' => $this->getGithubRepoUrl($owner, $repositoryName),
        ]);

        file_put_contents($clonePath . '/.travis.yml', $result);
    }

    /**
     * @param type $repositoryName
     */
    protected function moveDocToApp($owner, $repositoryName)
    {
        $clonePath = $this->getLocalClonePath($owner, $repositoryName);
        $this->fileSystem->mirror($clonePath . '/src/Resources', $clonePath . '/app/Resources', null, ['override' => true]);
        $this->fileSystem->remove($clonePath . '/src/Resources');
    }
}
