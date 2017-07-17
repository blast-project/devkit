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
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Glenn Cavarlé <glenn.cavarle@libre-informatique.fr>
 */
class SrcMigrationCommand extends AbstractCommand
{
    /**
     * @var \Twig_Environment
     */
    private $twig;

    protected function configure()
    {
        parent::configure();

        $this
            ->setName('src-migrate')
            ->setDescription('Migrates sources within /src folder')
            ->addArgument('bundles', InputArgument::IS_ARRAY, 'To limit the dispatcher on given bundle(s).', array());
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
            $this->migrateSources($owner, $repoName, $repoConfig);
        });

        return 0;
    }

    /**
     * @param type $owner
     * @param type $repoName
     */
    protected function migrateSources($owner, $repoName, array $repoConfig)
    {
        try {
            $this->io->title($owner . '/' . $repoName);

            $git = $this->cloneRepository($owner, $repoName);
            $this->applyChanges($owner, $repoName);

            if ($repoConfig['is_project']) {
                // $this->moveDocToApp($owner, $repoName);
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
        $oldEtcFolder = $clonePath . '/etc';
        $newSrcFolder = $clonePath . '/src';
        $newTestFolder = $clonePath . '/tests';

        if ($this->fileSystem->exists($oldEtcFolder)) {
            $this->fileSystem->remove($oldEtcFolder);
        }

        if (!$this->fileSystem->exists($newSrcFolder)) {
            $this->fileSystem->mkdir($clonePath . '/src');
        }

        if (!$this->fileSystem->exists($newTestFolder)) {
            $this->fileSystem->mkdir($newTestFolder);
            $this->fileSystem->touch($newTestFolder . '/.gitkeep');
        }

        //retrieve all files/folders to be moved
        $nodes = glob($clonePath . '/[A-Z][a-z]*', GLOB_ONLYDIR);
        $nodes = array_merge($nodes, glob($clonePath . '/[A-Z][a-z]*.php'));

        //actually move sources in /src
        foreach ($nodes as $nodeName) {
            //split path, add 'src' before package name and rename
            $pathParts = explode('/', $nodeName);
            $packageNameIndex = count($pathParts) - 1;

            $packageName = $pathParts[$packageNameIndex];
            $pathParts[$packageNameIndex] = 'src';
            $pathParts[] = $packageName;
            $this->io->comment("move $packageName in /src");
            $this->fileSystem->rename($nodeName, implode('/', $pathParts));
        }

        //complete travis.yml
        $this->fileSystem->mirror('etc/bundle-skeleton', $clonePath, null, ['override' => true]);
        $result = $this->twig->render('etc/bundle-skeleton/.travis.yml', [
            'github_url' => $this->getGithubRepoUrl($owner, $repositoryName),
        ]);
        file_put_contents($clonePath . '/.travis.yml', $result);

        //override composer
        $composerFile = json_decode(file_get_contents($clonePath . '/composer.json'), true);
        $psr4 = $composerFile['autoload']['psr-4'];
        $psr4Keys = array_keys($psr4);
        $composerFile['autoload']['psr-4'][$psr4Keys[0]] = 'src/';
        $composerFile['autoload']['psr-4'][$psr4Keys[0] . 'Tests\\'] = 'tests/';
        file_put_contents($clonePath . '/composer.json', json_encode($composerFile, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
