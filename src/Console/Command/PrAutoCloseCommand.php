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
class PrAutoCloseCommand extends AbstractCommand
{

    /**
     * 
     */
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('pr-auto-close')
            ->setDescription('Closes pull requests.')
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
            $this->closePullRequest($owner, $repoName, $repoConfig);
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
    private function closePullRequest($owner, $repoName, array $repoConfig)
    {
        $this->io->title($owner . '/' . $repoName);
        
        $pulls = $this->githubClient->pullRequests()
            ->all($owner, $repoName, array(
            'state' => 'open',
            'title' => 'DevKit updates for ' . $repoName)
        );

        if (0 === count($pulls)) {
            $this->logStep('- Pull request does not exist.');
            return;
        }
        $pull = $pulls[0];

        $this->logStep(sprintf('- Found pull request id[%s] title[%s] sha[%s]'
                , $pull['number'], $pull['title'], $pull['sha']));

        $this->logStep(sprintf('- Updating pull-request for %s/%s ...'
                , $owner, $repoName));

        if (!$this->apply) {
            return;
        }

        $this->githubClient->pullRequests()
            ->update($owner, $repoName, $pull['number'], array(
                'title' => 'DevKit updates for ' . $repoName,
                'state' => 'closed'
        ));


        // Wait 200ms to be sure GitHub API is up to date with new pushed branch/PR.
        usleep(200000);
        $this->io->success('Pull request for ' . $repoName . ' closed.');
    }
}
