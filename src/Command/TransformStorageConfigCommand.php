<?php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\Process;

/**
 * A transformer plug-in for Kustomize for modifying Kubernetes storage mounts.
 *
 * Takes in a resource list of deployment manifests and outputs modified
 * manifests that can include persistent volumes (PV), persistent volume
 * claims (PVC), and volume mounts that reference the PVCs.
 */
class TransformStorageConfigCommand extends Command {

  /**
   * The name of this command.
   *
   * @var string
   */
  protected static $defaultName = 'transform-storage-config';

  /**
   * {@inheritdoc}
   */
  protected function configure() {
    $this
      ->setDescription('Transform storage configuration for Kustomize')
      ->setHelp(
        'Transforms Kubernetes deployment manifests to configure storage.'
      );
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $output_style = new SymfonyStyle($input, $output);

    $output_style->error('Yo mama!');

    return 0;
  }

}
