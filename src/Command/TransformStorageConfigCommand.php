<?php

namespace App\Command;

use JsonPath\InvalidJsonException;
use JsonPath\JsonObject;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * A transformer plug-in for Kustomize for modifying Kubernetes storage mounts.
 *
 * Takes in a resource list of deployment manifests and outputs modified
 * manifests that can include persistent volumes (PV), persistent volume
 * claims (PVC), and volume mounts that reference the PVCs.
 */
class TransformStorageConfigCommand extends Command {

  /**
   * The "kind" of resource list this command can process.
   */
  const SUPPORTED_RESOURCE_LIST_KIND = 'ResourceList';

  /**
   * The "apiVersion" of resource list this command can process.
   */
  const SUPPORTED_RESOURCE_LIST_VERSION = 'config.kubernetes.io/v1';

  /**
   * The "kind" of function config this command can process.
   */
  const SUPPORTED_FUNC_CONFIG_KIND = 'StorageConfigTransformer';

  /**
   * The "apiVersion" of function config this command can process.
   */
  const SUPPORTED_FUNC_CONFIG_VERSION = 'kubernetes.inveniem.com/storage-config-transformer/v1alpha';

  /**
   * Config. key that specifies all the different data permutations.
   */
  const CONFIG_KEY_PERMUTATIONS = 'permutations';

  /**
   * Config. key that specifies the template for persistent volumes.
   */
  const CONFIG_KEY_PVS = 'persistentVolumeTemplate';

  /**
   * Config. key that specifies the template for persistent volume claims.
   */
  const CONFIG_KEY_PVCS = 'persistentVolumeClaimTemplate';

  /**
   * Config. key that specifies the template for container mounts of volumes.
   */
  const CONFIG_KEY_CONTAINER_VOLUMES = 'containerVolumeTemplates';

  /**
   * Config. key under permutations that specifies the permutation values.
   */
  const CONFIG_KEY_PERM_VALUES = 'values';

  /**
   * Mappings between configuration keys and transformer functions.
   *
   * Additional transformations can be implemented by extending this array.
   */
  const TRANSFORMATIONS = [
    [
      'configKey' => self::CONFIG_KEY_PVS,
      'function'  => 'applyPersistentVolumeTransforms',
    ],
    [
      'configKey' => self::CONFIG_KEY_PVCS,
      'function'  => 'applyPersistentVolumeClaimTransforms',
    ],
    [
      'configKey' => self::CONFIG_KEY_CONTAINER_VOLUMES,
      'function'  => 'applyContainerVolumeTransforms',
    ],
  ];

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
    try {
      $input_yaml_string = stream_get_contents(STDIN);
      $input_yaml        = Yaml::parse($input_yaml_string);

      $output_yaml = $this->applyTransformations($input_yaml);

      $exit_code = 0;
    }
    catch (\Exception $ex) {
      if ($ex instanceof \InvalidArgumentException) {
        // The message should contain enough detail for the end-user.
        $exception_message = $ex->getMessage();
      }
      else {
        // Something broke with the program itself, so a stack trace may be
        // helpful here.
        $exception_message =
          sprintf("%s\n%s", $ex->getMessage(), $ex->getTraceAsString());
      }

      // NOTE: Until https://github.com/kubernetes-sigs/kustomize/issues/4321
      // gets addressed, you will not see error results output at all in the
      // output in the event of an exception.
      $output_yaml = [
        'apiVersion' => self::SUPPORTED_RESOURCE_LIST_VERSION,
        'kind'       => self::SUPPORTED_RESOURCE_LIST_KIND,
        'items'      => [],
        'results'    => [
          [
            'message'  => $exception_message,
            'severity' => 'error',
          ]
        ],
      ];

      $exit_code = 1;
    }

    $output_yaml_string = Yaml::dump($output_yaml, 10, 2);

    $output->writeln($output_yaml_string);

    return $exit_code;
  }

  /**
   * Applies all storage transformations to the given Kubernetes resources.
   *
   * @param array $input_yaml
   *   An associative array representing the Kubernetes resource manifests
   *   received via standard input from Kustomize that was decoded from YAML
   *   format.
   *
   * @return array
   *   An associative array representing the modified Kubernetes resource
   *   manifests, to be encoded into YAML format and echoed back to the calling
   *   Kustomize process on standard output.
   *
   * @throws \InvalidArgumentException
   *   If any of the provided configuration is invalid.
   */
  protected function applyTransformations(array $input_yaml): array {
    $resource_list_kind    = $input_yaml['kind']       ?? NULL;
    $resource_list_version = $input_yaml['apiVersion'] ?? NULL;
    $resource_list_items   = $input_yaml['items']      ?? [];

    $function_config         = $input_yaml['functionConfig']  ?? [];
    $function_config_kind    = $function_config['kind']       ?? NULL;
    $function_config_version = $function_config['apiVersion'] ?? NULL;
    $transformer_configs     = $function_config['spec']       ?? [];

    if (($resource_list_kind !== self::SUPPORTED_RESOURCE_LIST_KIND) ||
        ($resource_list_version !== self::SUPPORTED_RESOURCE_LIST_VERSION)) {
      throw new \InvalidArgumentException(
        sprintf(
          'Expected a top-level resource of kind "%s", version "%s"; but got a "%s" of version "%s".',
          self::SUPPORTED_RESOURCE_LIST_KIND,
          self::SUPPORTED_RESOURCE_LIST_VERSION,
          $resource_list_kind,
          $resource_list_version
        )
      );
    }

    if (empty($resource_list_items)) {
      throw new \InvalidArgumentException(
        'Missing or empty "items" key in top-level resource list.'
      );
    }

    if (($function_config_kind !== self::SUPPORTED_FUNC_CONFIG_KIND) ||
        ($function_config_version !== self::SUPPORTED_FUNC_CONFIG_VERSION)) {
      throw new \InvalidArgumentException(
        sprintf(
          'Expected a function config of kind "%s", version "%s"; but got a "%s" of version "%s".',
          self::SUPPORTED_FUNC_CONFIG_KIND,
          self::SUPPORTED_FUNC_CONFIG_VERSION,
          $function_config_kind,
          $function_config_version
        )
      );
    }

    if (empty($transformer_configs)) {
      throw new \InvalidArgumentException(
        'Missing or empty "spec" key in kustomize-storage-transformer plugin configuration. See plugin documentation.'
      );
    }

    $output_yaml = $input_yaml;

    // Normally, I'd use array_reduce() here but we need to keep track of the
    // index of the transformer configuration for error reporting, so a foreach
    // is more elegant for that.
    foreach ($transformer_configs as $index => $transformer_config) {
      try {
        $output_yaml =
          $this->applyTransformation($output_yaml, $transformer_config);
      }
      catch (\InvalidArgumentException $ex) {
        throw new \InvalidArgumentException(
          sprintf(
            'Error while processing transformation for "spec[%d]" in kustomize-storage-transformer plugin configuration: %s. See plugin documentation.',
            $index,
            $ex->getMessage(),
          ),
        );
      }
    }

    return $output_yaml;
  }

  /**
   * Applies the given storage transformation to Kubernetes resources.
   *
   * @param array $input_manifests
   *   An associative array representing the Kubernetes resource manifests to
   *   transform.
   * @param array $transform_config
   *   Configuration settings for the specific transformation.
   *
   * @return array
   *   An associative array representing the modified Kubernetes resource
   *   manifests.
   *
   * @throws \InvalidArgumentException
   *   If any of the provided configuration is invalid.
   */
  protected function applyTransformation(array $input_manifests,
                                         array $transform_config): array {
    $permutation_values =
      $transform_config[self::CONFIG_KEY_PERMUTATIONS][self::CONFIG_KEY_PERM_VALUES] ?? NULL;

    if (empty($permutation_values)) {
      throw new \InvalidArgumentException(
        sprintf(
          'No permutations provided under "spec.%s.%s" key in kustomize-storage-transformer plugin configuration. See plugin documentation.',
          self::CONFIG_KEY_PERMUTATIONS,
          self::CONFIG_KEY_PERM_VALUES
        )
      );
    }

    $at_least_one_callback_invoked = FALSE;
    $config_keys                   = [];

    $output_manifests = array_reduce(
      self::TRANSFORMATIONS,
      function (array $manifests, array $transform_info) use (
        $transform_config,
        $permutation_values,
        &$at_least_one_callback_invoked,
        &$config_keys
      ) {
        $transform_function = $transform_info['function'];
        $config_key         = $transform_info['configKey'];

        $config_keys[]   = $config_key;
        $function_config = $transform_config[$config_key] ?? [];

        if (!empty($function_config)) {
          /** @var callable $callback */
          $callback  = [$this, $transform_function];

          $manifests =
            $callback($manifests, $permutation_values, $function_config);

          $at_least_one_callback_invoked = TRUE;
        }

        return $manifests;
      },
      $input_manifests
    );

    if (!$at_least_one_callback_invoked) {
      throw new \InvalidArgumentException(
        sprintf(
          'At least one of [%s] must be provided under the "spec" key in kustomize-storage-transformer plugin configuration. See plugin documentation.',
          implode(', ', $config_keys)
        )
      );
    }

    return $output_manifests;
  }

  /**
   * Applies transformations for all persistent volumes.
   *
   * The persistent volume template is repeated and customized for each
   * permutation value.
   *
   * @param array $input_manifests
   *   An associative array representing the Kubernetes resource manifests to
   *   transform.
   * @param string[] $permutation_values
   *   The values for which the persistent volume template will be repeatedly
   *   applied and customized.
   * @param array $function_config
   *   Settings for how each persistent volume will be generated, including its
   *   specification template, name template, and injected value templates.
   *
   * @return array
   *   An associative array representing the modified Kubernetes resource
   *   manifests.
   *
   * @throws \InvalidArgumentException
   *   If any of the provided configuration is invalid.
   *
   * @noinspection PhpUnused
   */
  protected function applyPersistentVolumeTransforms(
      array $input_manifests,
      array $permutation_values,
      array $function_config): array {
    return $this->generateResourcesOfType(
      self::CONFIG_KEY_PVS,
      'PersistentVolume',
      'v1',
      $input_manifests,
      $permutation_values,
      $function_config
    );
  }

  /**
   * Applies transformations for all persistent volume claims.
   *
   * The persistent volume claim template is repeated and customized for each
   * permutation value.
   *
   * @param array $input_manifests
   *   An associative array representing the Kubernetes resource manifests to
   *   transform.
   * @param string[] $permutation_values
   *   The values for which the persistent volume claim template will be
   *   repeatedly applied and customized.
   * @param array $function_config
   *   Settings for how each persistent volume claim will be generated,
   *   including its specification template, name template, and injected value
   *   templates.
   *
   * @return array
   *   An associative array representing the modified Kubernetes resource
   *   manifests.
   *
   * @throws \InvalidArgumentException
   *   If any of the provided configuration is invalid.
   *
   * @noinspection PhpUnused
   */
  protected function applyPersistentVolumeClaimTransforms(
      array $input_manifests,
      array $permutation_values,
      array $function_config): array {
    return $this->generateResourcesOfType(
      self::CONFIG_KEY_PVS,
      'PersistentVolumeClaim',
      'v1',
      $input_manifests,
      $permutation_values,
      $function_config
    );
  }

  /**
   * Applies transformations for container-related volumes and volume mounts.
   *
   * Whenever a resource that references one of the containers in the list is
   * encountered, it is modified to include one volume for each permutation,
   * using the volume template specified in the transformation function
   * configuration. Then, each matching container is modified to include to
   * include a volume mount for each permutation, using the volume mount
   * template specified in the transformation function configuration.
   *
   * @param array $input_manifests
   *   An associative array representing the Kubernetes resource manifests to
   *   transform.
   * @param string[] $permutation_values
   *   The values for which the persistent value template will be repeatedly
   *   applied and customized.
   * @param array $function_config
   *   Settings that control how volumes are injected into container resources.
   *
   * @return array
   *   An associative array representing the modified Kubernetes resource
   *   manifests.
   *
   * @throws \InvalidArgumentException
   *   If any of the provided configuration is invalid.
   *
   * @noinspection PhpUnused
   */
  protected function applyContainerVolumeTransforms(
      array $input_manifests,
      array $permutation_values,
      array $function_config): array {
    return $input_manifests;
  }

  /**
   * Generates resources of a particular kind based on a config template.
   *
   * @param string $config_key
   *   The name of the configuration key that provides the template for the
   *   resources. This is used only for error reporting.
   * @param string $resource_kind
   *   The type of the resources being generated.
   * @param string $resource_version
   *   The version of the resources being generated.
   * @param array $input_manifests
   *   An associative array representing the Kubernetes resource manifests to
   *   transform.
   * @param string[] $permutation_values
   *   The values for which the resource template will be repeatedly applied and
   *   customized.
   * @param array $function_config
   *   Settings for how each resource will be generated, including its
   *   specification template, name template, and injected value templates.
   *
   * @return array
   */
  protected function generateResourcesOfType(string $config_key,
                                             string $resource_kind,
                                             string $resource_version,
                                             array $input_manifests,
                                             array $permutation_values,
                                             array $function_config): array {
    $output_manifests = $input_manifests;

    $res_spec            = $function_config['spec'] ?? [];
    $res_name_template   = $function_config['name'] ?? [];
    $res_injected_values = $function_config['injectedValues'] ?? [];

    $name_prefix   = $res_name_template['prefix'] ?? '';
    $name_suffix   = $res_name_template['suffix'] ?? '';

    if (empty($res_spec)) {
      throw new \InvalidArgumentException(
        sprintf('"%s.spec" key is missing or empty.', $config_key)
      );
    }

    foreach ($permutation_values as $index => $value) {
      if (empty($value)) {
        throw new \InvalidArgumentException(
          sprintf(
            'Empty value encountered at "permutations.values[%d]"',
            $index
          )
        );
      }

      $new_res_name = implode('', [$name_prefix, $value, $name_suffix]);

      try {
        $new_res_object = new JsonObject([
          'kind'       => $resource_kind,
          'apiVersion' => $resource_version,
          'metadata'   => ['name' => $new_res_name],
          'spec'       => $res_spec,
        ]);
      }
      catch (InvalidJsonException $ex) {
        // This should never happen because we're providing an array.
        throw new \RuntimeException($ex->getMessage(), 0, $ex);
      }

      foreach ($res_injected_values as $value_index => $injected_value) {
        $field_path   = $injected_value['field']  ?? NULL;
        $field_prefix = $injected_value['prefix'] ?? '';
        $field_suffix = $injected_value['suffix'] ?? '';

        if (empty($field_path)) {
          throw new \InvalidArgumentException(
            sprintf(
              'Missing or empty "field" key for "%s.injectedValues[%d]"',
              $config_key,
              $value_index
            )
          );
        }

        $field_value = implode('', [$field_prefix, $value, $field_suffix]);
        $json_path   = '$.' . $field_path;

        $new_res_object->set($json_path, $field_value);
      }

      $new_res_yaml = $new_res_object->getValue();

      $output_manifests['items'][] = $new_res_yaml;
    }

    return $output_manifests;
  }

}
