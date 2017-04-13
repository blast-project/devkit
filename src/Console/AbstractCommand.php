<?php
/*
 * This file is part of the Blast Project.
 * Copyright (C) 2017 Libre Informatique
 * This file is licenced under the GNU GPL v3.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Blast\DevKit\Console;

use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Github\HttpClient\HttpClient;
use Packagist\Api\Result\Package;
use Blast\DevKit\GithubClient;

/**
 * @author Glenn Cavarlé <glenn.cavarle@libre-informatique.fr>
 */
class AbstractCommand extends Command
{

    const GITHUB_GROUP = 'blast-project';
    const GITHUB_USER = 'BlastCI';
    const GITHUB_EMAIL = 'r.et.d@libre-informatique.fr';
    const PACKAGIST_GROUP = 'libre-informatique';

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
    
    protected $apply = true;

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->io = new SymfonyStyle($input, $output);

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
    }

    /**
     * Returns repository name without vendor prefix.
     *
     * @param Package $package
     *
     * @return string
     */
    final protected function getRepositoryName(Package $package)
    {
        $repositoryArray = explode('/', $package->getRepository());
        return str_replace('.git', '', end($repositoryArray));
    }

    final protected function getGithubRepoUrl($repositoryName)
    {
        return 'https://github.com/' . static::GITHUB_GROUP . '/' . $repositoryName . '.git';
    }
    
    final protected function getGithubDevkitRepoUrl($repositoryName)
    {
        return 'git@github.com:' . static::GITHUB_USER . '/' . $repositoryName . '.git';
    }

    /**
     * 
     * @param type $repositoryName
     * @return type
     */
    final protected function getClonePath($repositoryName)
    {
        //return sys_get_temp_dir() . '/' . static::GITHUB_GROUP . '/' . $repositoryName;
        return __DIR__ . '/../../.tmp/' . static::GITHUB_GROUP . '/' . $repositoryName;
    }
}
