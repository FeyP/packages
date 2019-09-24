<?php

/*
 * Copyright (c) werk21
 *
 * For the full copyright and license information, please view the LICENSE file
 * that was distributed with this source code.
 */

namespace Terramar\Packages\Plugin\Sami\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Process\ProcessBuilder;
use Terramar\Packages\Console\Application;
use Terramar\Packages\Console\Command\ContainerAwareCommand;
use Terramar\Packages\Entity\Package;
use Terramar\Packages\Plugin\Sami\UpdateJob;
use Terramar\Packages\Plugin\CloneProject\Events;
use Terramar\Packages\Plugin\CloneProject\PackageCloneEvent;

/**
 * Wraps Sami build command.
 */
class BuildCommand extends ContainerAwareCommand
{
    /**
     * @var ContainerInterface
     */
    protected $container;

    /**
     * Sets the container.
     *
     * @param ContainerInterface|null $container A ContainerInterface instance or null
     */
    public function setContainer(ContainerInterface $container = null)
    {
        $this->container = $container;
    }

    protected function configure()
    {
        $this->setName('sami:build');
        $this->setDescription('Clones project and builds documentation.');
        $def = $this->getDefinition();
        $args = $def->getArguments();
        $args['package'] = new InputArgument('package', InputArgument::OPTIONAL,
            'Package name. If left blank, all packages.');
        $def->setArguments($args);
    }

    /**
     * @param InputInterface $input The input instance
     * @param OutputInterface $output The output instance
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
      $package_name = $input->getArgument('package');
      if (!$package_name) {
        $packages = $this->getEntityManager()->getRepository('Terramar\Packages\Plugin\Sami\PackageConfiguration')
          ->createQueryBuilder('pc')
          ->join('pc.package', 'p')
          ->where('pc.enabled = true')
          ->andWhere('p.enabled = true')
          ->getQuery()
          ->getResult();
        foreach ($packages as $package_configuration) {
          $package = $package_configuration->getPackage();
          $this->buildDocumentation($package, $input, $output);
        }
        return;
      }
      $package = $this->getEntityManager()->getRepository(Package::class)->findOneBy(['name' => $package_name]);
      $this->buildDocumentation($package, $input, $output);
    }

    protected function buildDocumentation(Package $package, InputInterface $input, OutputInterface $output) {
      if (!$package) {
        throw new \RuntimeException('Invalid project');
      }
      if (!$package->isEnabled()) {
        $output->writeln(sprintf('<comment>Package %s is disabled. Skipping...</comment>', $package->getName()));
        return;
      }

      $config_clone = $this->getEntityManager()->getRepository('Terramar\Packages\Plugin\CloneProject\PackageConfiguration')
        ->findOneBy(['package' => $package]);

      if (!$config_clone || !$config_clone->isEnabled()) {
        $output->writeln(sprintf('<comment>Package %s is not configured to be cloned. Skipping...</comment>', $package->getName()));
        return;
      }

      $config_sami = $this->getEntityManager()->getRepository('Terramar\Packages\Plugin\Sami\PackageConfiguration')
        ->findOneBy(['package' => $package]);

      if (!$config_sami || !$config_sami->isEnabled()) {
        $output->writeln(sprintf('<comment>Package %s is not configured to build documentation. Skipping...</comment>', $package->getName()));
        return;
      }

      $directory = $this->getCacheDir($package);

      if (file_exists($directory) || is_dir($directory)) {
          $this->emptyAndRemoveDirectory($directory);
      }

      mkdir($directory, 0777, true);

      $builder = new ProcessBuilder(['clone', $package->getSshUrl(), $directory]);
      $builder->setPrefix('git');

      $process = $builder->getProcess();
      $process->run(function ($type, $message) {
        echo $message;
      });

      if (!$process->isSuccessful()) {
        throw new \RuntimeException('Unable to clone package.');
      }

      $config_sami->setRepositoryPath($directory);

      $this->getEntityManager()->persist($config_sami);
      $this->getEntityManager()->flush($config_sami);

      $docs_path = $config_sami->getDocsPath() . DIRECTORY_SEPARATOR . $package->getFqn() . DIRECTORY_SEPARATOR . 'build' . DIRECTORY_SEPARATOR;
      if (file_exists($docs_path) && is_dir($docs_path)) {
        $output->writeln(sprintf('<comment>Removing old documentation from %s...</comment>', $docs_path));
        $this->emptyAndRemoveDirectory($docs_path);
      }

      $cache_dir = $this->container->getParameter('app.cache_dir') . '/sami/' . $package->getFqn();
      if (file_exists($cache_dir) && is_dir($cache_dir)) {
        $output->writeln(sprintf('<comment>Removing old cache from %s...</comment>', $cache_dir));
        $this->emptyAndRemoveDirectory($cache_dir);
      }

      try {
        $job = new UpdateJob();
        $job->run(['id' => $package->getId()]);
      }
      catch (\Exception $e) {
        $output->writeln(sprintf('<error>Unable to build documentation for %s. Skipping...</error>', $package->getName()));
      }
    }

    /**
     * @return EntityManager
     */
    private function getEntityManager()
    {
        return $this->container->get('doctrine.orm.entity_manager');
    }

    /**
     * @return string
     */
    private function getCacheDir(Package $package)
    {
        return $this->container->getParameter('app.cache_dir') . '/cloned_project/' . $package->getFqn();
    }

    private function emptyAndRemoveDirectory($directory)
    {
        $files = array_diff(scandir($directory), ['.', '..']);
        foreach ($files as $file) {
            (is_dir("$directory/$file")) ? $this->emptyAndRemoveDirectory("$directory/$file") : unlink("$directory/$file");
        }

        return rmdir($directory);
    }

}
