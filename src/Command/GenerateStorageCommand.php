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
 * A generator command for Kustomize for modifying Kubernetes storage mounts.
 */
class GenerateStorageCommand extends Command {

  /**
   * The name of this command.
   *
   * @var string
   */
  protected static $defaultName = 'generate-storage';

  /**
   * {@inheritdoc}
   */
  protected function configure() {
    $this
      ->setDescription('Generates storage configuration for Kustomize')
      ->setHelp(
        'Generates Kubernetes deployment manifests to configure storage for '.
        'applications.'
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
