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

use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Filesystem\Filesystem;
use Github\HttpClient\HttpClient;
use GitWrapper\GitWrapper;
use Packagist\Api\Result\Package;
use Blast\DevKit\GithubClient;
use Symfony\Component\Yaml\Yaml;

/**
 * @author Glenn Cavarlé <glenn.cavarle@libre-informatique.fr>
 */
class AbstractCommand extends Command
{
    const GITHUB_USER = 'BlastCI';
    const GITHUB_EMAIL = 'r.et.d@libre-informatique.fr';
    const LI_GROUP = 'libre-informatique';
    const BLAST_GROUP = 'blast-project';
    const LABEL_NOTHING_CHANGED = 'Nothing to be changed.';
    const DEVKIT_BRANCH = 'update-branch';

    /**
     * @var SymfonyStyle
     */
    protected $io;

    /**
     * @var array
     */
    protected $configs;

    /**
     * @var string|null
     */
    protected $githubAuthKey = null;

    /**
     * @var \Packagist\Api\Client
     */
    protected $packagistClient;

    /**
     * @var GithubClient
     */
    protected $githubClient = false;

    /**
     * @var \Github\ResultPager
     */
    protected $githubPaginator;
    protected $apply = false;

    /**
     * @var Filesystem
     */
    protected $fileSystem;

    /**
     * @var GitWrapper
     */
    protected $gitWrapper;

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->configs = Yaml::parse(file_get_contents(__DIR__ . '/../../config/projects.yml'));

        $this->io = new SymfonyStyle($input, $output);

        $this->gitWrapper = new GitWrapper();
        $this->fileSystem = new Filesystem();

        if (getenv('GITHUB_OAUTH_TOKEN')) {
            $this->githubAuthKey = getenv('GITHUB_OAUTH_TOKEN');
        }

        $this->packagistClient = new \Packagist\Api\Client();

        $this->githubClient = new GithubClient(new HttpClient(array(
            // This version is needed for squash. https://developer.github.com/v3/pulls/#input-2
            'api_version' => 'polaris-preview',
        )));

        $this->githubPaginator = new \Github\ResultPager($this->githubClient);
        if ($this->githubAuthKey) {
            $this->githubClient->authenticate($this->githubAuthKey, null, \Github\Client::AUTH_HTTP_TOKEN);
        }

        $this->apply = $input->getOption('apply');
        if (!$this->apply) {
            $this->io->warning('This is a dry run execution. No change will be applied here.');
        }

        $this->doNotDeleteFork = $input->getOption('do-not-delete-fork');
    }

    protected function configure()
    {
        parent::configure();

        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Applies wanted requests');
        $this->addOption('do-not-delete-fork', null, InputOption::VALUE_NONE, 'Do not delete the fork after process');
    }

    final protected function forEachRepoDo($callback)
    {
        foreach ($this->configs as $owner => $ownerConfig) {
            if (!$ownerConfig['options']['active']) {
                $this->io->note($owner . ' disabled in config.');
                continue;
            }
            foreach ($ownerConfig['repositories'] as $repoName => $repoConfig) {
                $repoConfig = array_merge(
                    ['active' => false, 'is_project' => false],
                    $repoConfig
                );

                if (!$repoConfig['active']) {
                    $this->io->note($owner . '/' . $repoName . ' disabled in config.');
                    continue;
                }

                $callback($owner, $repoName, $repoConfig);
            }
        }
    }

    /**
     * @param type $owner
     * @param type $repositoryName
     *
     * @return \GitWrapper\GitWorkingCopy
     */
    final protected function cloneRepository($owner, $repositoryName)
    {
        // Ensure temp dir
        $clonePath = $this->getLocalClonePath($owner, $repositoryName);
        if ($this->fileSystem->exists($clonePath)) {
            $this->fileSystem->remove($clonePath);
        }
        // Clone repository
        $git = $this->gitWrapper->cloneRepository(
            'https://' . static::GITHUB_USER . ':' . $this->githubAuthKey
            . '@github.com/' . $owner . '/' . $repositoryName,
            $clonePath
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
     * @param type $repositoryName
     */
    final protected function pushChanges($git, $owner, $repositoryName)
    {
        $git->add('.', array('all' => true))->getOutput();
        $diff = $git->diff('--color', '--cached')->getOutput();

        if (!empty($diff)) {
            $this->logStep('Diff is not empty.');

            if ($this->apply) {
                $this->logStep('Start creating a pull request from fork...');

                $this->logStep('Creating new commit...');
                $git->commit('DevKit updates');

                $this->logStep('Creating new fork... ' . $owner . '/' . $repositoryName);
                $this->githubClient->repos()->forks()->create($owner, $repositoryName);
                // $this->githubClient->api('repo')->forks()->create($owner, $repositoryName);

                $this->logStep('Adding remote based on ' . static::GITHUB_USER . ' fork...');
                $git->addRemote(static::GITHUB_USER, $this->getGithubDevkitRepoUrl($owner, $repositoryName));
                usleep(500000);

                $this->logStep('Pushing...');
                $git->push('-u', static::GITHUB_USER, static::DEVKIT_BRANCH);

                // If the Pull Request does not exists yet, create it.
                $pulls = $this->githubClient->pullRequests()
                    ->all($owner, $repositoryName, array(
                    'state' => 'open',
                    'head' => static::GITHUB_USER . ':' . static::DEVKIT_BRANCH,
                    ));

                if (0 === count($pulls)) {
                    $this->logStep('Pull request does not exist.');
                    $this->logStep('Creating pull-request for ' . $owner . '/' . $repositoryName);
                    $this->githubClient->pullRequests()
                        ->create($owner, $repositoryName, array(
                            'title' => 'DevKit updates for ' . $repositoryName,
                            'head' => static::GITHUB_USER . ':' . static::DEVKIT_BRANCH,
                            'base' => 'master',
                            'body' => '',
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
     * @param type $git
     * @param type $repositoryName
     */
    final protected function deleteFork($git, $owner, $repositoryName)
    {
        $this->io->comment('Deleting remote ' . static::GITHUB_USER . '/' . $repositoryName . ' fork...');
        if ($this->doNotDeleteFork) {
            $this->io->warning('Fork will not be deleted after process due to option');
        } else {
            $this->githubClient->repositories()->remove(static::GITHUB_USER, $repositoryName);
            $this->io->success('Fork ' . static::GITHUB_USER . '/' . $repositoryName . ' deleted.');
        }
    }

    /**
     * @param type $owner
     * @param type $repositoryName
     *
     * @return type
     */
    final protected function getGithubRepoUrl($owner, $repositoryName)
    {
        return 'https://github.com/' . $owner . '/' . $repositoryName . '.git';
    }

    /**
     * @param type $owner
     * @param type $repositoryName
     *
     * @return type
     */
    final protected function getGithubDevkitRepoUrl($owner, $repositoryName)
    {
        return 'https://' . static::GITHUB_USER . ':' . $this->githubAuthKey
            . '@github.com/' . static::GITHUB_USER . '/' . $repositoryName;

        //'git@github.com:' . static::GITHUB_USER . '/' . $repositoryName . '.git';
    }

    /**
     * @param type $repositoryName
     *
     * @return type
     */
    final protected function getClonePath($owner, $repositoryName)
    {
        return sys_get_temp_dir() . '/' . $owner . '/' . $repositoryName;
    }

    /**
     * @param type $repositoryName
     *
     * @return type
     */
    final protected function getLocalClonePath($owner, $repositoryName)
    {
        return __DIR__ . '/../../../.tmp/' . $owner . '/' . $repositoryName;
    }

    /**
     * @param array $userBunldeCfg
     *
     * @return bool
     */
    final public function computeBundleConfigs(array $userBunldeCfg)
    {
        if (empty($userBunldeCfg)) {
            return $this->configs;
        }
        $newConfigs = [];
        foreach ($userBunldeCfg as $bundleName) {
            $bundleParts = explode('::', $bundleName);
            $orgName = $bundleParts[0];
            $repoName = $bundleParts[1];
            //create the default project structure
            if (!isset($newConfigs[$orgName])) {
                $newConfigs[$orgName] = [
                    'options' => ['active' => true],
                    'repositories' => [],
                ];
            }

            $newConfigs[$orgName]['repositories'][$repoName] = [
                'active' => true,
                'is_project' => false,
            ];
        }

        return $newConfigs;
    }

    public function logStep($message)
    {
        $messages = is_array($message) ? array_values($message) : array($message);

        $this->io->writeln($messages);
        $this->io->newLine();
    }
}
