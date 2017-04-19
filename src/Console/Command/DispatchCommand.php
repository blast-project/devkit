<?php
/*
 * This file is part of the Blast Project.
 * Copyright (C) 2017 Libre Informatique
 * This file is licenced under the GNU GPL v3.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Blast\DevKit\Console\Command;

use Doctrine\Common\Inflector\Inflector;
use Github\Exception\ExceptionInterface;
use GitWrapper\GitWrapper;
use Packagist\Api\Result\Package;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * @author Glenn Cavarlé <glenn.cavarle@libre-informatique.fr>
 */
class DispatchCommand extends AbstractCommand
{

    

    /**
     * @var GitWrapper
     */
    private $gitWrapper;

    /**
     * @var Filesystem
     */
    private $fileSystem;

    /**
     * @var \Twig_Environment
     */
    private $twig;

    /**
     * @var string[]
     */
    private $projects;

    /**
     * 
     */
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('dispatch')
            ->setDescription('Dispatches configuration and documentation files for all blast bunldes.')
            ->addArgument('bundles', InputArgument::IS_ARRAY, 'To limit the dispatcher on given bundle(s).', array());
    }

    /**
     * 
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        parent::initialize($input, $output);

        $this->gitWrapper = new GitWrapper();
        $this->fileSystem = new Filesystem();
        $this->twig = new \Twig_Environment(
            new \Twig_Loader_Filesystem(__DIR__ . '/../../..'));

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

        foreach ($this->configs as $owner => $ownerConfig) {

            if (!$ownerConfig['options']['active']) {
                $this->io->note($owner . ' disabled in config.');
                continue;
            }
            foreach ($ownerConfig['repositories'] as $repoName => $repoConfig) {

                $repoConfig = array_merge(
                    ['active' => false, 'is_project' => false]
                    , $repoConfig);

                if (!$repoConfig['active']) {
                    $this->io->note($owner . '/' . $repoName . ' disabled in config.');
                    continue;
                }

                $this->executeForRepo($owner, $repoName, $repoConfig);
            }
        }

        return 0;
    }

    /**
     * 
     * @param type $owner
     * @param type $repoName
     */
    protected function executeForRepo($owner, $repoName, array $repoConfig)
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
     * 
     * @param type $repositoryName
     */
    protected function cloneRepository($owner, $repositoryName)
    {

        // Ensure temp dir
        $clonePath = $this->getClonePath($owner, $repositoryName);
        if ($this->fileSystem->exists($clonePath)) {
            $this->fileSystem->remove($clonePath);
        }
        // Clone repository
        $git = $this->gitWrapper->cloneRepository(
            'https://' . static::GITHUB_USER . ':' . $this->githubAuthKey
            . '@github.com/' . $owner . '/' . $repositoryName, $clonePath
        );
        //Git config
        $git
            ->config('user.name', static::GITHUB_USER)
            ->config('user.email', static::GITHUB_EMAIL);

        $git->reset(array('hard' => true));
        $git->checkout('-b', static::DEVKIT_BRANCH);

        return $git;
    }

    /**
     * 
     * @param type $repositoryName
     */
    protected function pushChanges($git, $owner, $repositoryName)
    {
        $git->add('.', array('all' => true))->getOutput();
        $diff = $git->diff('--color', '--cached')->getOutput();

        if (!empty($diff)) {
            $this->io->comment('Diff is not empty.');
            $this->io->comment('Start creating a pull request from fork...');
            if ($this->apply) {

                $this->io->comment('Creating new commit...');
                $git->commit('DevKit updates');

                $this->io->comment('Creating new fork...');
                $this->githubClient->repos()->forks()->create($owner, $repositoryName);

                $this->io->comment('Adding remote based on ' . static::GITHUB_USER . ' fork...');
                $git->addRemote(static::GITHUB_USER, $this->getGithubDevkitRepoUrl($owner, $repositoryName));
                usleep(500000);

                $this->io->comment('Pushing...');
                $git->push('-u', static::GITHUB_USER, static::DEVKIT_BRANCH);

                // If the Pull Request does not exists yet, create it.
                $pulls = $this->githubClient->pullRequests()
                    ->all($owner, $repositoryName, array(
                    'state' => 'open',
                    'head' => static::GITHUB_USER . ':' . static::DEVKIT_BRANCH
                ));

                if (0 === count($pulls)) {
                    $this->io->comment('Pull request does not exist.');
                    $this->io->comment('Creating pull-request for ' . $owner . '/' . $repositoryName);
                    $this->githubClient->pullRequests()
                        ->create($owner, $repositoryName, array(
                            'title' => 'DevKit updates for ' . $repositoryName,
                            'head' => static::GITHUB_USER . ':' . static::DEVKIT_BRANCH,
                            'base' => 'master',
                            'body' => ''
                    ));
                }

                // Wait 200ms to be sure GitHub API is up to date with new pushed branch/PR.
                usleep(200000);
                $this->io->success('Pull request for ' . $repositoryName . ' created.');
                $this->deleteFork($git, $owner, $repositoryName);
            }
        } else {
            $this->io->comment(static::LABEL_NOTHING_CHANGED);
        }
    }

    /**
     * 
     * @param type $git
     * @param type $repositoryName
     */
    protected function deleteFork($git, $owner, $repositoryName)
    {
        $this->io->comment('Deleting remote ' . static::GITHUB_USER . '/' . $repositoryName . ' fork...');
        $this->githubClient->repositories()->remove(static::GITHUB_USER, $repositoryName);
        $this->io->success('Fork ' . static::GITHUB_USER . '/' . $repositoryName . ' deleted.');
    }

    /**
     * 
     * @param type $repositoryName
     */
    protected function applyChanges($owner, $repositoryName)
    {
        $clonePath = $this->getClonePath($owner, $repositoryName);
        $this->fileSystem->mirror('etc/bundle-skeleton', $clonePath, null, ['override' => true]);
        $result = $this->twig->render('etc/bundle-skeleton/.travis.yml', [
            'github_url' => $this->getGithubRepoUrl($owner, $repositoryName)
        ]);

        file_put_contents($clonePath . '/.travis.yml', $result);
    }

    /**
     * 
     * @param type $repositoryName
     */
    protected function moveDocToApp($owner, $repositoryName)
    {
        $clonePath = $this->getClonePath($owner, $repositoryName);
        $this->fileSystem->mirror($clonePath . '/src/Resources', $clonePath . '/app/Resources', null, ['override' => true]);
        $this->fileSystem->remove($clonePath . '/src/Resources');
    }
}
