<?php
/*
 * This file is part of the Blast Project.
 * Copyright (C) 2017 Libre Informatique
 * This file is licenced under the GNU GPL v3.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Blast\DevKit\Console\Command;

use Packagist\Api\Result\Package;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Description of AutoMergeCommand
 *
 * @author Glenn Cavarlé <glenn.cavarle@libre-informatique.fr>
 */
class PrAutoMergeCommand extends AbstractCommand
{

    /**
     * 
     */
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('pr-auto-merge')
            ->setDescription('Merges pull requests if there is no conflict.')
            ->addArgument('bundles', InputArgument::IS_ARRAY, 'To limit the dispatcher on given bundles(s).', array());
    }

    /**
     * 
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        parent::initialize($input, $output);
        $this->configs = $this->computeBundleConfigs($input->getArgument('bundles'));
    }

    /**
     * 
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->forEachRepoDo(function($owner, $repoName, $repoConfig) {
            $this->autoMergeBranches($owner, $repoName, $repoConfig);
        });

        return 0;
    }

    /**
     * 
     * @param Package $package
     * @param array $projectConfig
     * @return type
     * @throws RuntimeException
     */
    private function autoMergeBranches($owner, $repoName, array $repoConfig)
    {
        $this->io->title($owner . '/' . $repoName);

        $base = 'master';
        $head = 'BlastCI:' . static::DEVKIT_BRANCH;
        $sha = null;
        $id = null;

        //find the pull request
        $this->logStep('- Finding pull request...');
        $pulls = $this->githubClient->pullRequests()
            ->all($owner, $repoName, array(
            'state' => 'open',
            'head' => 'master'
        ));

        foreach ($pulls as $pull) {
            if ($pull['head']['label'] == $head &&
                strpos($pull['title'], 'DevKit updates') !== false) {
                $id = $pull['number'];
                $sha = $pull['head']['sha'];
                $this->logStep(sprintf('- Found pull request id[%s] title[%s] sha[%s]', $id, $pull['title'], $sha));
                $this->mergePullRequest($owner, $repoName, $id, $head, $base, $sha);
                return;
            }
        }
        $this->logStep('[x] Pull request does not exist.');
    }

    /**
     * 
     * @throws \Blast\DevKit\Console\Command\RuntimeException
     */
    private function mergePullRequest($owner, $repoName, $id, $head, $base, $sha)
    {
        if (!$this->apply) {
            return;
        }

        try {
            // Merge message should be removed when following PR will be merged and tagged.
            // https://github.com/KnpLabs/php-github-api/pull/379
            $response = $this->githubClient->pullRequests()->merge(
                $owner, $repoName, $id, sprintf('Merge %s into %s', $head, $base), $sha
            );
            if (is_array($response) && array_key_exists('sha', $response)) {
                $this->io->success('Merged ' . $head . ' into ' . $base);
            } else {
                $this->logStep('- Nothing to merge on ' . $base);
            }
        } catch (\RuntimeException $e) {
            if (409 === $e->getCode()) {
                $this->io->warning('Merging of ' . $head . ' into ' . $base . ' contains conflicts. Skipped.');
                return;
            }
            throw $e;
        }
    }
}
