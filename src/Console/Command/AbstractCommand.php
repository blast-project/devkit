<?php
/*
 * This file is part of the Blast Project.
 * Copyright (C) 2017 Libre Informatique
 * This file is licenced under the GNU GPL v3.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Blast\DevKit\Console\Command;

use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Github\HttpClient\HttpClient;
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

    protected function initialize(InputInterface $input, OutputInterface $output)
    {

        $this->configs = Yaml::parse(file_get_contents(__DIR__ . '/../../config/projects.yml'));


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

        $this->apply = $input->getOption('apply');
        if (!$this->apply) {
            $this->io->warning('This is a dry run execution. No change will be applied here.');
        }
    }

    /**
     * 
     */
    protected function configure()
    {
        parent::configure();

        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Applies wanted requests');
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

    final protected function getGithubRepoUrl($owner, $repositoryName)
    {
        return 'https://github.com/' . $owner . '/' . $repositoryName . '.git';
    }

    final protected function getGithubDevkitRepoUrl($owner, $repositoryName)
    {
        return 'https://' . static::GITHUB_USER . ':' . $this->githubAuthKey
            . '@github.com/' . static::GITHUB_USER . '/' . $repositoryName;

        //'git@github.com:' . static::GITHUB_USER . '/' . $repositoryName . '.git';
    }

    /**
     * 
     * @param type $repositoryName
     * @return type
     */
    final protected function getClonePath($owner, $repositoryName)
    {
        return sys_get_temp_dir() . '/' . $owner . '/' . $repositoryName;
        //return __DIR__ . '/../../.tmp/' . $owner . '/' . $repositoryName;
    }

    /**
     * 
     * @param array $userBunldeCfg
     * @return boolean
     */
    public function computeBundleConfigs(array $userBunldeCfg)
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
                    'repositories' => []
                ];
            }

            $newConfigs[$orgName]['repositories'][$repoName] = [
                'active' => true,
                'is_project' => false
            ];
        }

        return $newConfigs;
    }
}
