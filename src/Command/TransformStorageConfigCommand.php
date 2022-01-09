<?php
/** @noinspection PhpFullyQualifiedNameUsageInspection */

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
   * Config. key under permutations that specifies the permutation values.
   */
  const CONFIG_KEY_PERM_VALUES = 'values';

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
   * Config. key that specifies the container volume template target containers.
   */
  const CONFIG_KEY_CV_CONTAINERS = 'containers';

  /**
   * Config. key that specifies the container volume template for volume mounts.
   */
  const CONFIG_KEY_CV_VOLUME_MOUNT_TEMPLATES = 'volumeMountTemplates';

  /**
   * Config. key that specifies the container volume template for volumes.
   */
  const CONFIG_KEY_CV_VOLUMES_TEMPLATE = 'volumeTemplates';

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
   * Names and versions of resource types that can contain containers.
   *
   * Each value is a list of JSONPath expressions for reaching the container
   * and volume definitions of the resource.
   */
  const CONTAINER_RESOURCES = [
    'Deployment:apps/v1' => [
      'containersPath' => '$.spec.template.spec.containers',
      'volumesPath'    => '$.spec.template.spec.volumes',
    ]
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
      // event of an exception.
      $output_yaml = [
        'apiVersion' => self::SUPPORTED_RESOURCE_LIST_VERSION,
        'kind'       => self::SUPPORTED_RESOURCE_LIST_KIND,
        'items'      => [
          // This is a kludge/workaround for the issue mentioned above. To
          // ensure we can debug the input, we put the error output into the
          // regular output. This can be removed when issue #4321 is resolved.
          [
            'apiVersion' => self::SUPPORTED_FUNC_CONFIG_VERSION,
            'kind'       => 'ErrorReport',
            'metadata' => [
              'name' => 'error-report',
            ],
            'error' => [
              'message'   => $exception_message,
              'givenYaml' => $input_yaml ?? [],
            ],
          ],
          // End kludge.
        ],
        'results' => [
          [
            'message'  => $exception_message,
            'severity' => 'error',
          ]
        ],
      ];

      // This is a kludge/workaround for the issue mentioned above. To
      // ensure we can debug, we return success on failure. This can be removed
      // when issue #4321 is resolved.
      // $exit_code = 1;
      $exit_code = 0;
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
   * @param array $input_resources
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
  protected function applyTransformation(array $input_resources,
                                         array $transform_config): array {
    $permutation_values =
      $transform_config[self::CONFIG_KEY_PERMUTATIONS][self::CONFIG_KEY_PERM_VALUES] ?? NULL;

    if (empty($permutation_values)) {
      throw new \InvalidArgumentException(
        sprintf(
          'No permutations provided under "spec.%s.%s" key',
          self::CONFIG_KEY_PERMUTATIONS,
          self::CONFIG_KEY_PERM_VALUES
        )
      );
    }

    $at_least_one_callback_invoked = FALSE;
    $config_keys                   = [];

    $output_resources = array_reduce(
      self::TRANSFORMATIONS,
      function (array $resources, array $transform_info) use (
        $transform_config,
        $permutation_values,
        &$at_least_one_callback_invoked,
        &$config_keys
      ) {
        $transform_function = $transform_info['function'];
        $config_key         = $transform_info['configKey'];

        $config_keys[]           = $config_key;
        $function_section_config = $transform_config[$config_key] ?? [];

        if (!empty($function_section_config)) {
          /** @var callable $callback */
          $callback  = [$this, $transform_function];

          $resources =
            $callback(
              $resources,
              $permutation_values,
              $function_section_config
            );

          $at_least_one_callback_invoked = TRUE;
        }

        return $resources;
      },
      $input_resources
    );

    if (!$at_least_one_callback_invoked) {
      throw new \InvalidArgumentException(
        sprintf(
          'At least one of [%s] must be provided under the "spec" key',
          implode(', ', $config_keys)
        )
      );
    }

    return $output_resources;
  }

  /**
   * Applies transformations for all persistent volumes.
   *
   * The persistent volume template is repeated and customized for each
   * permutation value.
   *
   * @param array $input_resources
   *   An associative array representing the Kubernetes resource manifests to
   *   transform.
   * @param string[] $permutation_values
   *   The values for which the persistent volume template will be repeatedly
   *   applied and customized.
   * @param array $function_section_config
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
      array $input_resources,
      array $permutation_values,
      array $function_section_config): array {
    return $this->generateResourcesOfType(
      self::CONFIG_KEY_PVS,
      'PersistentVolume',
      'v1',
      $input_resources,
      $permutation_values,
      $function_section_config
    );
  }

  /**
   * Applies transformations for all persistent volume claims.
   *
   * The persistent volume claim template is repeated and customized for each
   * permutation value.
   *
   * @param array $input_resources
   *   An associative array representing the Kubernetes resource manifests to
   *   transform.
   * @param string[] $permutation_values
   *   The values for which the persistent volume claim template will be
   *   repeatedly applied and customized.
   * @param array $function_section_config
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
      array $input_resources,
      array $permutation_values,
      array $function_section_config): array {
    return $this->generateResourcesOfType(
      self::CONFIG_KEY_PVCS,
      'PersistentVolumeClaim',
      'v1',
      $input_resources,
      $permutation_values,
      $function_section_config
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
   * @param array $input_resources
   *   An associative array representing the Kubernetes resource manifests to
   *   transform.
   * @param string[] $permutation_values
   *   The values for which container volume templates will be repeatedly
   *   applied and customized.
   * @param array $function_section_config
   *   Settings for each of the transformations. Each element provides the
   *   settings for a single transformation. The settings control how a
   *   transformation injects volumes into container resources.
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
      array $input_resources,
      array $permutation_values,
      array $function_section_config): array {

    $output_resources = $input_resources;

    foreach ($function_section_config as $index => $container_transform_config) {
      try {
        $output_resources =
          $this->applyContainerVolumeTransform(
            $output_resources,
            $permutation_values,
            $container_transform_config
          );
      }
      catch (\InvalidArgumentException $ex) {
        throw new \InvalidArgumentException(
          sprintf(
            'Error while processing transformation for "%s[%d]": %s',
            self::CONFIG_KEY_CONTAINER_VOLUMES,
            $index,
            $ex->getMessage(),
          ),
        );
      }
    }

    return $output_resources;
  }

  /**
   * Applies a transformation for container-related volumes and volume mounts.
   *
   * This applies only the given transformation out of the full list of
   * container volume transformations.
   *
   * Whenever a resource that references one of the containers in the list is
   * encountered, it is modified to include one volume for each permutation,
   * using the volume template specified in the transformation function
   * configuration. Then, each matching container is modified to include to
   * include a volume mount for each permutation, using the volume mount
   * template specified in the transformation function configuration.
   *
   * @param array $input_resources
   *   An associative array representing the Kubernetes resource manifests to
   *   transform.
   * @param string[] $permutation_values
   *   The values for which the persistent value template will be repeatedly
   *   applied and customized.
   * @param array $container_transform_config
   *   Settings that control how this transformation injects volumes into
   *   container resources.
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
  protected function applyContainerVolumeTransform(
      array $input_resources,
      array $permutation_values,
      array $container_transform_config): array {
    $containers =
      $container_transform_config[self::CONFIG_KEY_CV_CONTAINERS] ?? [];

    $volume_templates =
      $container_transform_config[self::CONFIG_KEY_CV_VOLUMES_TEMPLATE] ?? [];

    $volume_mount_templates =
      $container_transform_config[self::CONFIG_KEY_CV_VOLUME_MOUNT_TEMPLATES] ?? [];

    if (empty($containers)) {
      throw new \InvalidArgumentException(
        '"containerVolumeTemplates.containers" key is missing or empty'
      );
    }

    $target_container_names = $this->getTargetContainerNames($containers);

    $output_resources = $input_resources;
    $output_items     = $output_resources['items'];

    foreach ($output_items as &$resource) {
      $resource_kind    = $resource['kind']       ?? '';
      $resource_version = $resource['apiVersion'] ?? '';

      $resource_type = implode(':', [$resource_kind, $resource_version]);

      if (isset(self::CONTAINER_RESOURCES[$resource_type])) {
        $resource =
          $this->applyContainerVolumeTransformToResource(
            $volume_templates,
            $volume_mount_templates,
            $target_container_names,
            $permutation_values,
            $resource_type,
            $resource
          );
      }
    }

    $output_resources['items'] = $output_items;

    return $output_resources;
  }

  /**
   * Applies templates for volumes and volume mounts to the given resource.
   *
   * @param array $volume_templates
   *   The list of templates to apply to volumes shared by all matching
   *   containers.
   * @param array $volume_mount_templates
   *   The list of templates to apply to volume mounts within each matching
   *   container.
   * @param array $target_container_names
   *   The names of containers to manipulate.
   * @param string[] $permutation_values
   *   The values for which each template will be repeatedly applied and
   *   customized.
   * @param string $resource_type
   *   The resource to which container volume transforms are being applied.
   * @param array $resource
   *   The type of the resource (e.g., "Deployment:apps/v1").
   *
   * @return array
   *   The modified resource.
   */
  protected function applyContainerVolumeTransformToResource(
      array $volume_templates,
      array $volume_mount_templates,
      array $target_container_names,
      array $permutation_values,
      string $resource_type,
      array $resource): array {
    $expressions = self::CONTAINER_RESOURCES[$resource_type];

    $containers_expression = $expressions['containersPath'];
    $volumes_expression    = $expressions['volumesPath'];

    try {
      $resource_object = new JsonObject($resource, TRUE);
    }
    catch (InvalidJsonException $ex) {
      // This should never happen because we're providing an array.
      throw new \RuntimeException($ex->getMessage(), 0, $ex);
    }

    $containers = $resource_object->get($containers_expression) ?: [];
    $volumes    = $resource_object->get($volumes_expression)    ?: [];

    $have_container_match = FALSE;

    foreach ($containers as &$container) {
      $container_name = $container['name'] ?? '';

      if (in_array($container_name, $target_container_names)) {
        $have_container_match = TRUE;

        $volume_mounts = $container['volumeMounts'] ?? [];

        $new_volume_mounts =
          $this->generateResourceFragments(
            self::CONFIG_KEY_CV_VOLUME_MOUNT_TEMPLATES,
            $permutation_values,
            $volume_mount_templates
          );

        $container['volumeMounts'] =
          array_merge($volume_mounts, $new_volume_mounts);
      }
    }

    if ($have_container_match) {
      $new_volumes =
        $this->generateResourceFragments(
          self::CONFIG_KEY_CV_VOLUMES_TEMPLATE,
          $permutation_values,
          $volume_templates
        );

      $volumes = array_merge($volumes, $new_volumes);

      $resource_object->set($containers_expression, $containers);
      $resource_object->set($volumes_expression,    $volumes);
    }

    return $resource_object->getValue();
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
   * @param array $input_resources
   *   An associative array representing the Kubernetes resource manifests to
   *   transform.
   * @param string[] $permutation_values
   *   The values for which the resource template will be repeatedly applied and
   *   customized.
   * @param array $config_section
   *   Settings for how each resource will be generated, including its
   *   specification template, name template, and injected value templates.
   *
   * @return array
   */
  protected function generateResourcesOfType(
      string $config_key,
      string $resource_kind,
      string $resource_version,
      array $input_resources,
      array $permutation_values,
      array $config_section): array {
    $output_resources = $input_resources;

    $res_spec            = $config_section['spec'] ?? [];
    $res_injected_values = $config_section['injectedValues'] ?? [];

    if (empty($res_spec)) {
      throw new \InvalidArgumentException(
        sprintf('"%s.spec" key is missing or empty', $config_key)
      );
    }

    foreach ($permutation_values as $index => $permutation_value) {
      if (empty($permutation_value)) {
        throw new \InvalidArgumentException(
          sprintf(
            'Empty value encountered at "permutations.values[%d]"',
            $index
          )
        );
      }

      $new_res_name =
        $this->generateName($config_section, $permutation_value);

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

      $this->applyInjectedValues(
        $config_key,
        $permutation_value,
        $new_res_object,
        $res_injected_values
      );

      $new_res_yaml = $new_res_object->getValue();

      $output_resources['items'][] = $new_res_yaml;
    }

    return $output_resources;
  }

  /**
   * Generates resource fragments based on a config template.
   *
   * Resource fragments are parts of larger specifications. For example,
   * elements in the "volumes" and "volumeMounts" keys of deployments and
   * containers, respectively.
   *
   * @param string $config_key
   *   The name of the configuration key that provides the template for the
   *   resources. This is used only for error reporting.
   * @param string[] $permutation_values
   *   The values for which the fragment template will be repeatedly applied and
   *   customized.
   * @param array[] $config_sections
   *   Settings for how each fragment will be generated, including its
   *   merge specification template, name template, and injected value
   *   templates.
   *
   * @return array[]
   *   An array containing each resource fragment that was generated by the
   *   permutations and configuration sections. The total array length will be
   *   M x N, where M is the number of permutation values and N is the number of
   *   configuration sections.
   */
  protected function generateResourceFragments(
      string $config_key,
      array $permutation_values,
      array $config_sections): array {
    $resource_fragments = [];

    foreach ($permutation_values as $permutation_index => $permutation_value) {
      if (empty($permutation_value)) {
        throw new \InvalidArgumentException(
          sprintf(
            'Empty value encountered at "permutations.values[%d]"',
            $permutation_index
          )
        );
      }

      foreach ($config_sections as $config_index => $config_section) {
        $merge_spec      = $config_section['mergeSpec']      ?? [];
        $injected_values = $config_section['injectedValues'] ?? [];

        $new_fragment_name =
          $this->generateName($config_section, $permutation_value);

        $new_fragment_array =
          array_merge(['name' => $new_fragment_name], $merge_spec);

        try {
          $new_fragment_object = new JsonObject($new_fragment_array);
        }
        catch (InvalidJsonException $ex) {
          // This should never happen because we're providing an array.
          throw new \RuntimeException($ex->getMessage(), 0, $ex);
        }

        $this->applyInjectedValues(
          sprintf('%s[%d]', $config_key, $config_index),
          $permutation_value,
          $new_fragment_object,
          $injected_values
        );

        $resource_fragments[] = $new_fragment_object->getValue();
      }
    }

    return $resource_fragments;
  }

  /**
   * Returns the names of the containers targeted by the specified configs.
   *
   * @param array $container_configs
   *   The target containers configuration in a container volume template of the
   *   plugin configuration.
   *
   * @return string[]
   *   The list of the names of containers that are being targeted.
   */
  protected function getTargetContainerNames(array $container_configs): array {
    $target_container_names = [];

    foreach ($container_configs as $index => $target_container) {
      $target_container_name = $target_container['name'] ?? NULL;

      if (empty($target_container_name)) {
        throw new \InvalidArgumentException(
          sprintf('"containers[%d].name" key is missing or empty', $index)
        );
      }

      $target_container_names[] = $target_container_name;
    }

    return $target_container_names;
  }

  /**
   * Generates a name from settings that include the name prefix and suffix.
   *
   * @param array $config_section
   *   The configuration section containing the name template settings.
   * @param string $permutation_value
   *   The current permutation for which a name is being generated.
   *
   * @return string
   *   The name generated from the permutation.
   */
  protected function generateName(array $config_section,
                                  string $permutation_value): string {
    $res_name_template = $config_section['name'] ?? [];

    $name_prefix   = $res_name_template['prefix'] ?? '';
    $name_suffix   = $res_name_template['suffix'] ?? '';

    return implode('', [$name_prefix, $permutation_value, $name_suffix]);
  }

  /**
   * Applies injected values to the given resource object.
   *
   * @param string $config_key
   *   The name of the configuration key that provides the injected values
   *   configuration.
   * @param string $permutation_value
   *   The current permutation value that is being injected into the resource.
   * @param \JsonPath\JsonObject $res_object
   *   The resource object into which values are being injected.
   * @param array $injected_values_config
   *   The configuration for how values should be formatted and where they
   *   should be injected into the resource object.
   */
  protected function applyInjectedValues(string $config_key,
                                         string $permutation_value,
                                         JsonObject $res_object,
                                         array $injected_values_config): void {
    foreach ($injected_values_config as $value_index => $injected_value) {
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

      $field_value =
        implode('', [$field_prefix, $permutation_value, $field_suffix]);

      $json_path = '$.' . $field_path;

      $res_object->set($json_path, $field_value);
    }
  }

}
