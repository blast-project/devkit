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

    const LABEL_NOTHING_CHANGED = 'Nothing to be changed.';
    const DEVKIT_BRANCH = 'update-branch';

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
            ->setDescription('Dispatches configuration and documentation files for all blast projects.')
            ->addArgument('projects', InputArgument::IS_ARRAY, 'To limit the dispatcher on given project(s).', array())
            ->addOption('with-files', null, InputOption::VALUE_NONE, 'Applies Pull Request actions for projects files');
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
            new \Twig_Loader_Filesystem(__DIR__ . '/../..'));

        $this->projects = ['DoctrinePgsqlBundle'];
    }

    /**
     * 
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {

        foreach ($this->projects as $name) {
            try {
                //$package = $this->packagistClient->get(static::PACKAGIST_GROUP . '/' . $name);
                $this->io->title($name);

                $git = $this->cloneRepository($name);
                $this->applyChanges($name);
                $this->pushChanges($git, $name);
            } catch (ExceptionInterface $e) {
                $this->io->error('Failed with message: ' . $e->getMessage());
            }
        }

        return 0;
    }

    /**
     * 
     * @param type $repositoryName
     */
    protected function cloneRepository($repositoryName)
    {

        // Ensure temp dir
        $clonePath = $this->getClonePath($repositoryName);
        if ($this->fileSystem->exists($clonePath)) {
            $this->fileSystem->remove($clonePath);
        }
        // Clone repository
        $git = $this->gitWrapper->cloneRepository(
            'https://' . static::GITHUB_USER . ':' . $this->githubAuthKey
            . '@github.com/' . static::GITHUB_GROUP . '/' . $repositoryName, $clonePath
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
    protected function pushChanges($git, $repositoryName)
    {
        $git->add('.', array('all' => true))->getOutput();
        $diff = $git->diff('--color', '--cached')->getOutput();

        if (!empty($diff)) {
            $this->io->comment('Diff is not empty.');
            $this->io->comment('Start creating a pull request from fork...');
            $this->io->comment('[apply flag] is ' . $this->apply);
            if ($this->apply) {

                $this->io->comment('Creating new commit...');
                $git->commit('DevKit updates');

                $this->io->comment('Creating new fork...');
                $this->githubClient->repos()->forks()->create(static::GITHUB_GROUP, $repositoryName);

                $this->io->comment('Adding remote based on ' . static::GITHUB_USER . ' fork...');
                $git->addRemote(static::GITHUB_USER, $this->getGithubDevkitRepoUrl($repositoryName));
                usleep(500000);

                $this->io->comment('Pushing...');
                $git->push('-u', static::GITHUB_USER, static::DEVKIT_BRANCH);

                // If the Pull Request does not exists yet, create it.
                $pulls = $this->githubClient->pullRequests()
                    ->all(static::GITHUB_GROUP, $repositoryName, array(
                    'state' => 'open',
                    'head' => static::GITHUB_USER . ':' . static::DEVKIT_BRANCH
                ));

                if (0 === count($pulls)) {
                    $this->io->comment('Pull request does not exist.');
                    $this->io->comment('Creating pull-request for ' . $repositoryName);
                    $this->githubClient->pullRequests()
                        ->create(static::GITHUB_GROUP, $repositoryName, array(
                            'title' => 'DevKit updates for ' . $repositoryName,
                            'head' => static::GITHUB_USER . ':' . static::DEVKIT_BRANCH,
                            'base' => 'master',
                            'body' => ''
                    ));
                }

                // Wait 200ms to be sure GitHub API is up to date with new pushed branch/PR.
                usleep(200000);
                $this->io->success('Pull request for ' . $repositoryName . ' created.');
                $this->deleteFork($git, $repositoryName);
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
    protected function deleteFork($git, $repositoryName)
    {
        $this->io->comment('Deleting remote ' . $repositoryName . ' fork...');
        $this->githubClient->repositories()->remove(static::GITHUB_USER, $repositoryName);
        $this->io->success('Fork for ' . $repositoryName . ' deleted.');
    }

    /**
     * 
     * @param type $repositoryName
     */
    protected function applyChanges($repositoryName)
    {
        $clonePath = $this->getClonePath($repositoryName);
        $this->fileSystem->mirror('etc/bundle-skeleton', $clonePath, null, ['override' => true]);
        $result = $this->twig->render('etc/bundle-skeleton/.travis.yml', [
            'github_url' => $this->getGithubRepoUrl($repositoryName)
        ]);

        file_put_contents($clonePath . '/.travis.yml', $result);
    }

    /**
     * 
     * @param type $repositoryName
     */
    protected function configureProjectDocSkeleton($repositoryName)
    {
        $clonePath = $this->getClonePath($repositoryName);
        $this->fileSystem->mirror($clonePath . '/Resources', $clonePath . '/app/Resources', null, ['override' => true]);
        $this->fileSystem->remove($clonePath . '/Resources');
    }
}
