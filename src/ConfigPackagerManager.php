<?php

/**
 * @file
 * Contains \Drupal\config_packager\ConfigPackagerManager.
 */

namespace Drupal\config_packager;
use Drupal\Component\Serialization\Yaml;
use Drupal\config_packager\ConfigPackagerAssignerInterface;
use Drupal\config_packager\ConfigPackagerManagerInterface;
use Drupal\Core\Archiver\ArchiveTar;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\InstallStorage;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Entity\EntityManagerInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Extension\Extension;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The ConfigPackagerManager provides helper functions for building package.
 */
class ConfigPackagerManager implements ConfigPackagerManagerInterface {
  use StringTranslationTrait;

  /**
   * The entity manager.
   *
   * @var \Drupal\Core\Entity\EntityManagerInterface
   */
  protected $entityManager;

  /**
   * The target storage.
   *
   * @var \Drupal\Core\Config\StorageInterface
   */
  protected $configStorage;

  /**
   * The configuration manager.
   *
   * @var \Drupal\Core\Config\ConfigManagerInterface
   */
  protected $configManager;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The Configuration Packager profile settings.
   *
   * @var array
   */
  protected $profileSettings;

  /**
   * The configuration present on the site.
   *
   * @var array
   */
  private $configCollection;

  /**
   * The packages to be generated.
   *
   * @var array
   */
  protected $packages;

  /**
   * The install profile, also used as the "package" value in info files.
   *
   * @var array
   */
  protected $profile;

  /**
   * The package assigner.
   *
   * @var \Drupal\config_packager\ConfigPackagerAssigner
   */
  protected $assigner;

  /**
   * Constructs a ConfigPackagerManager object.
   *
   * @param \Drupal\Core\Entity\EntityManagerInterface $entity_manager
   *   The entity manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \Drupal\Core\Config\StorageInterface $config_storage
   *   The target storage.
   * @param \Drupal\Core\Config\ConfigManagerInterface $config_manager
   *   The configuration manager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   */
  public function __construct(EntityManagerInterface $entity_manager, ConfigFactoryInterface $config_factory, StorageInterface $config_storage, ConfigManagerInterface $config_manager, ModuleHandlerInterface $module_handler) {
    $this->entityManager = $entity_manager;
    $this->configStorage = $config_storage;
    $this->configManager = $config_manager;
    $this->moduleHandler = $module_handler;
    $this->profileSettings = $config_factory->get('config_packager.settings')->get('profile');
    $this->packages = [];
    $this->initProfile();
    $this->configCollection = [];
  }

  /**
   * {@inheritdoc}
   */
  public function reset() {
    $this->packages = [];
    // Don't use getConfigCollection because reset() may be called in
    // cases where we don't need to load config.
    foreach ($this->configCollection as &$config) {
      $config['package'] = NULL;
    }
    // Clean up the pass by reference
    unset($config);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfigCollection() {
    $this->initConfigCollection();
    return $this->configCollection;
  }

  /**
   * {@inheritdoc}
   */
  public function setConfigCollection(array $config_collection) {
    $this->configCollection = $config_collection;
  }

  /**
   * {@inheritdoc}
   */
  public function getPackages() {
    return $this->packages;
  }

  /**
   * {@inheritdoc}
   */
  public function setPackages(array $packages) {
    $this->packages = $packages;
  }

  /**
   * {@inheritdoc}
   */
  public function getProfile() {
    return $this->profile;
  }

  /**
   * {@inheritdoc}
   */
  public function setProfile(array $profile) {
    $this->profile = $profile;
  }

  /**
   * {@inheritdoc}
   */
  public function getAssigner() {
    return $this->assigner;
  }

  /**
   * {@inheritdoc}
   */
  public function setAssigner(ConfigPackagerAssignerInterface $assigner) {
    $this->assigner = $assigner;
    $this->reset();
  }

  /**
   * Initialize the profile based on stored settings.
   */
  protected function initProfile() {
    if (empty($this->profile)) {
      $this->assignProfile(
        $this->profileSettings['machine_name'],
        $this->profileSettings['name'],
        $this->profileSettings['description']
      );
    }
  }

  /**
   * Set the profile to a given machine_name, name, and description.
   */
  protected function assignProfile($machine_name, $name = NULL, $description = '') {
    $this->profile = $this->getProject($machine_name, $name, $description, 'profile');
  }

  /**
   * {@inheritdoc}
   */
  public function initPackage($machine_name, $name = NULL, $description = '') {
    if (!isset($this->packages[$machine_name])) {
      $this->packages[$machine_name] = $this->getProject($machine_name, $name, $description);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function initCorePackage() {
    $machine_name = 'core';
    $name = $this->t('Core');
    $description = $this->t('Provide core components required by other configuration modules.');
    $this->initPackage($machine_name, $name, $description);
  }

  /**
   * {@inheritdoc}
   */
  public function assignConfigPackage($package_name, array $item_names) {
    $config_collection = $this->getConfigCollection();
    foreach ($item_names as $item_name) {
      if (empty($config_collection[$item_name]['package']) && !in_array($item_name, $this->packages[$package_name]['config'])) {
        // Add the item to the package's config array.
        $this->packages[$package_name]['config'][] = $item_name;
        // Mark the item as already assigned.
        $config_collection[$item_name]['package'] = $package_name;
        // Set any module dependencies of the configuration item as package
        // dependencies.
        if (isset($config_collection[$item_name]['data']['dependencies']['module'])) {
          $dependencies =& $this->packages[$package_name]['dependencies'];
          $dependencies = array_unique(array_merge($dependencies, $config_collection[$item_name]['data']['dependencies']['module']));
        }
      }
    }
    $this->setConfigCollection($config_collection);
  }

  /**
   * {@inheritdoc}
   */
  public function assignConfigByPattern(array $patterns) {
    $config_collection = $this->getConfigCollection();
    // Reverse sort by key so that child package will claim items before parent
    // package. E.g., event_registration will claim before event.
    krsort($config_collection);
    foreach ($patterns as $pattern => $machine_name) {
      if (isset($this->packages[$machine_name])) {
        foreach ($config_collection as $item_name => $item) {
          if (empty($item['package']) && preg_match('/[_\-.]' . $pattern . '[_\-.]/', '.' . $item['short_name'] . '.')) {
            $this->assignConfigPackage($machine_name, [$item_name]);
          }
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function assignConfigDependents(array $item_names = NULL) {
    $config_collection = $this->getConfigCollection();
    if (empty($item_names)) {
      $item_names = array_keys($config_collection);
    }
    foreach ($item_names as $item_name) {
      if (!empty($config_collection[$item_name]['package'])) {
        foreach ($config_collection[$item_name]['dependents'] as $dependent_item_name) {
          if (isset($config_collection[$dependent_item_name]) && empty($config_collection[$dependent_item_name]['package'])) {
            $this->assignConfigPackage($config_collection[$item_name]['package'], [$dependent_item_name]);
          }
        }
      }
    }
  }

  /**
   * Initialize and return a package or profile array.
   *
   * @param string $machine_name
   *   Machine name of the package.
   * @param string $name
   *   Human readable name of the package.
   * @param string $description
   *   Description of the package.
   * @return array
   *   An array with the following keys:
   *   - 'machine_name': machine name of the project.
   *   - 'name': human readable name of the project.
   *   - 'description': description of the project.
   *   - 'type': type of Drupal project ('profile' or 'module').
   *   - 'core': Drupal core compatibility ('8.x'),
   *   - 'dependencies': array of module dependencies.
   *   - 'themes': array of names of themes to enable.
   *   - 'config': array of names of configuration items.
   *   - 'files' array of files, each having the following keys:
   *      - 'filename': the name of the file.
   *      - 'string': the contents of the file.
   */
  protected function getProject($machine_name, $name = NULL, $description = '', $type = 'module') {
    $description = $description ?: $this->t('@name configuration.', ['@name' => $name]);
    return [
      'machine_name' => $machine_name,
      'name' => $this->getName($machine_name, $name),
      'description' => $description,
      'type' => $type,
      'core' => '8.x',
      'dependencies' => [],
      'themes' => [],
      'config' => [],
      'files' => []
    ];
  }

  /**
   * Generate and add .info.yml files to a package.
   *
   * @param array $package
   *   The package.
   */
  protected function addInfoFile(array &$package) {
    $machine_name = $package['machine_name'];
    // Filter to standard keys of the profiles that we will use in info files.
    $info_keys = [
      'name',
      'description',
      'type',
      'core',
      'dependencies',
      'themes'
    ];
    $info = array_intersect_key($package, array_combine($info_keys, $info_keys));

    // Assign to a "package" named for the profile.
    $info['package'] = $this->profile['name'];

    // Add in the configuration in a form used by the Configuragion
    // Development (config_devel) module, allowing that module to update
    // configuration.
    // @see https://www.drupal.org/node/2300717.
    if (!empty($package['config'])) {
      $info['config_devel'] = $package['config'];
    }

    switch ($info['type']) {
      // Prefix modules with the profile name.
      case 'module':
        $machine_name = $this->profile['machine_name'] . '_' . $machine_name;
        $info['name'] = $this->profile['name'] . ' ' . $info['name'];
        break;
      // Conditionally merge in profile dependencies and theme info.
      case 'profile':
        if ($this->profileSettings['add_standard']) {
          $info_file_name = 'core/profiles/standard/standard.info.yml';
          if (file_exists($info_file_name)) {
            $profile_info = \Drupal::service('info_parser')->parse($info_file_name);
            // Merge in dependencies and themes data.
            foreach (['dependencies', 'themes'] as $key) {
              $info[$key] = array_unique(
                array_merge(
                  $info[$key],
                  $profile_info[$key]
                )
              );
              sort($info[$key]);
            }
          }
        }
        break;
    }
    $package['files']['info'] = [
      'filename' => $machine_name . '/' . $machine_name . '.info.yml',
      // Filter to remove any empty keys, e.g., an empty themes array.
      'string' => Yaml::encode(array_filter($info))
    ];
  }

  /**
   * Generate and add files to the profile.
   */
  protected function addProfileFiles() {
    // Adjust file paths to include the profile.
    $packages = $this->getPackages();
    foreach ($packages as &$package) {
      foreach ($package['files'] as &$file) {
        $file['filename'] = $this->profile['machine_name'] . '/modules/custom/' . $file['filename'];
      }
      // Clean up the $file pass by reference.
      unset($file);
    }
    // Clean up the $package pass by reference.
    unset($package);
    $this->setPackages($packages);

    // Add the profile's files.
    $profile = $this->getProfile();
    $this->addInfoFile($profile);
    // Conditionally add .profile and .install files from Standard profile.
    if ($this->profileSettings['add_standard']) {
      $files = [
        'install',
        'profile',
      ];
      // Iterate through the files.
      foreach ($files as $extension) {
        $filename = 'core/profiles/standard/standard.' . $extension;
        if (file_exists($filename)) {
          // Read the file contents.
          $string = file_get_contents($filename);
          // Substitute the profile's machine name and name for the Standard
          // profile's equivalents.
          $string = str_replace(
            ['standard', 'Standard'],
            [$profile['machine_name'], $profile['name']],
            $string
          );
          // Add the files to those to be output.
          $profile['files'][$extension] = [
            'filename' => $profile['machine_name'] . '/' . $profile['machine_name'] . '.' . $extension,
            'string' => $string
          ];
        }
      }
    }
    $this->setProfile($profile);
  }

  /**
   * Generate and add files to all packages.
   */
  protected function addPackageFiles() {
    $config_collection = $this->getConfigCollection();
    foreach ($this->packages as $machine_name => &$package) {
      // Only add files if there is at least one piece of configuration
      // present.
      if (!empty($package['config'])) {
        // Add .info.yml files.
        $this->addInfoFile($package);
        // Add configuration files.
        foreach ($package['config'] as $name) {
          $config = $config_collection[$name];
          // The UUID is site-specfic, so don't export it.
          if ($entity_type_id = $this->configManager->getEntityTypeIdByName($name)) {
            unset($config['data']['uuid']);
          }
          $package['files'][$name] = [
            'filename'=> $this->profile['machine_name'] . '_' . $package['machine_name'] . '/' . InstallStorage::CONFIG_INSTALL_DIRECTORY . '/' . $config['name'] . '.yml',
            'string' => Yaml::encode($config['data'])
          ];
        }
      }
    }
    // Clean up the pass by reference.
    unset($package);
  }

  /**
   * Return a human readable name.
   *
   * If no human readable name is available, generate a default one based on
   * the machine name.
   *
   * @param string $machine_name
   *   Machine name of the package.
   * @param string $name
   *   Human readable name, if any, of the package.
   *
   * @return string
   *   Human readable name of the package.
   */
  protected function getName($machine_name, $name = NULL) {
    // Provide a default name based on the machine name.
    if (empty($name)) {
      $name = ucwords(str_replace('_', ' ', $machine_name));
    }
    return $name;
  }

  /**
   * {@inheritdoc}
   */
  public function getConfigTypes() {
    $definitions = [];
    foreach ($this->entityManager->getDefinitions() as $entity_type => $definition) {
      if ($definition->isSubclassOf('Drupal\Core\Config\Entity\ConfigEntityInterface')) {
        $definitions[$entity_type] = $definition;
      }
    }
    $entity_types = array_map(function (EntityTypeInterface $definition) {
      return $definition->getLabel();
    }, $definitions);
    // Sort the entity types by label, then add the simple config to the top.
    uasort($entity_types, 'strnatcasecmp');
    return [
      ConfigPackagerManagerInterface::SYSTEM_SIMPLE_CONFIG => $this->t('Simple configuration'),
    ] + $entity_types;
  }

  /**
   * {@inheritdoc}
   */
  public function getModuleList($name = NULL, $namespace = NULL) {
    $modules = $this->moduleHandler->getModuleList();
    if (!empty($name) || !empty($namespace)) {
      $return = [];
      if (!empty($name) && isset($modules[$name])) {
        $return[$name] = $modules[$name];
      }
      if (!empty($namespace)) {
        foreach ($modules as $module_name => $extension) {
          if (strpos($module_name, $namespace) === 0) {
            $return[$module_name] = $extension;
          }
        }
      }
      return $return;
    }
    return $modules;
  }

  /**
   * {@inheritdoc}
   */
  public function getExtensionConfig(Extension $extension) {
    $config_path = $extension->getPath() .  '/' . InstallStorage::CONFIG_INSTALL_DIRECTORY;

    if (is_dir($config_path)) {
      $install_storage = new FileStorage($config_path);
      return $install_storage->listAll();
    }

    return [];
  }

  /**
   * Get stored configuration for a given configuration type.
   *
   * @param string $config_type
   *   The type of configuration.
   */
  protected function getConfigByType($config_type) {
    // For a given entity type, load all entities.
    if ($config_type && $config_type !== ConfigPackagerManagerInterface::SYSTEM_SIMPLE_CONFIG) {
      $entity_storage = $this->entityManager->getStorage($config_type);
      foreach ($entity_storage->loadMultiple() as $entity) {
        $entity_id = $entity->id();
        $label = $entity->label() ?: $entity_id;
        $names[$entity_id] = $label;
      }
    }
    // Handle simple configuration.
    else {
      $definitions = [];
      foreach ($this->entityManager->getDefinitions() as $entity_type => $definition) {
        if ($definition->isSubclassOf('Drupal\Core\Config\Entity\ConfigEntityInterface')) {
          $definitions[$entity_type] = $definition;
        }
      }
      // Gather the config entity prefixes.
      $config_prefixes = array_map(function (EntityTypeInterface $definition) {
        return $definition->getConfigPrefix() . '.';
      }, $definitions);

      // Find all config, and then filter our anything matching a config prefix.
      $names = $this->configStorage->listAll();
      $names = array_combine($names, $names);
      foreach ($names as $item_name) {
        foreach ($config_prefixes as $config_prefix) {
          if (strpos($item_name, $config_prefix) === 0) {
            unset($names[$item_name]);
          }
        }
      }
    }
    return $names;
  }

  /**
   * Load configuration from storage into a property.
   */
  protected function initConfigCollection() {
    if (empty($this->configCollection)) {
      $config_collection = [];
      $config_types = $this->getConfigTypes();
      foreach (array_keys($config_types) as $config_type) {
        $config = $this->getConfigByType($config_type);
        foreach ($config as $item_name => $label) {
          // Determine the full config name for the selected config entity.
          if ($config_type !== ConfigPackagerManagerInterface::SYSTEM_SIMPLE_CONFIG) {
            $definition = $this->entityManager->getDefinition($config_type);
            $name = $definition->getConfigPrefix() . '.' . $item_name;
          }
          // The config name is used directly for simple configuration.
          else {
            $name = $item_name;
          }
          $data = $this->configStorage->read($name);
          $config_collection[$name] = [
            'name' => $name,
            'short_name' => $item_name,
            'label' => $label,
            'type' => $config_type,
            'data' => $data,
            // @todo: use // ConfigDependencyManager::getDependentEntities('config', $name) ?
            'dependents' => []
          ];
        }
      }
      // Add dependency information. The definition of each piece of
      // configuration includes the other configuration it's dependent on. Here
      // we add reverse dependencies: the configuration that is dependent on
      // a given piece of configuration.
      foreach ($config_collection as $config) {
        if (isset($config['data']['dependencies']['config'])) {
          foreach ($config['data']['dependencies']['config'] as $name) {
            if (isset($config_collection[$name])) {
              $config_collection[$name]['dependents'][] = $config['name'];
            }
          }
        }
      }
      $this->setConfigCollection($config_collection);
    }
  }

  /**
   * Iterate through packages and profile and prepare file names and contents.
   *
   * @param boolean $add_profile
   *   Whether to add an install profile. Defaults to FALSE.
   */
  protected function prepareFiles($add_profile = FALSE) {
    // Add package files first so their filename values can be altered to nest
    // them in a profile.
    $this->addPackageFiles();
    if ($add_profile) {
      $this->addProfileFiles();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function generatePackages($method, array $package_names = array()) {
    return $this->generate($method, FALSE, $package_names);
  }

  /**
   * {@inheritdoc}
   */
  public function generateProfile($method, array $package_names = array()) {
    return $this->generate($method, TRUE, $package_names);
  }

  /**
   * Generate a file representation of configuration packages and, optionally,
   * an install profile.
   *
   * @param string $method
   *   The method to use, either
   *   ConfigPackagerManagerInterface::GENERATE_METHOD_ARCHIVE to generate an
   *   archive (tarball) or
   *   ConfigPackagerManagerInterface::GENERATE_METHOD_WRITE to write files
   *   to the file system.
   * @param boolean $add_profile
   *   Whether to add an install profile. Defaults to FALSE.
   * @param array $package_names
   *   Array of names of packages to be generated. If none are specified, all
   *   available packages will be added.
   *
   * @return array
   *   Array of results for profile and/or packages, each result including the
   *   following keys:
   *   - 'success': boolean TRUE or FALSE for successful writing.
   *   - 'message': a message about the result of the operation.
   *   - 'variables': an array of substitutions to be used in the message.
   */
  protected function generate($method, $add_profile = FALSE, array $package_names = array()) {
    // Prepare the files.
    $this->prepareFiles($add_profile);
  
    $packages = $this->getPackages();

    // Filter out the packages that weren't requested.
    if (!empty($package_names)) {
      $packages = array_intersect_key($packages, array_fill_keys($package_names, NULL));
    }

    switch ($method) {
      case ConfigPackagerManagerInterface::GENERATE_METHOD_ARCHIVE:
        $return = $this->generateArchive($add_profile, $packages);
        break;
      case ConfigPackagerManagerInterface::GENERATE_METHOD_WRITE:
        $return = $this->generateWrite($add_profile, $packages);
        break;
    }
    foreach ($return as $message) {
      // Archive writing doesn't merit a message, and if done through the UI
      // would appear on the subsequent page load.
      if ($method == ConfigPackagerManagerInterface::GENERATE_METHOD_WRITE) {
        $type = $message['success'] ? 'status' : 'error';
        drupal_set_message($this->t($message['message'], $message['variables']), $type);
      }
      $type = $message['success'] ? 'notice' : 'error';
      \Drupal::logger('config_packager')->{$type}($message['message'], $message['variables']);
    }
    return $return;
  }

  /**
   * Generate a compressed archive (tarball) of specified packages, or of all
   * packages if none are specified.
   *
   * @param boolean $add_profile
   *   Whether to add an install profile. Defaults to FALSE.
   * @param array $packages
   *   Array of package data.
   */
  protected function generateArchive($add_profile = FALSE, array $packages = array()) {
    // If no packages were specified, get all packages.
    if (empty($packages)) {
      $packages = $this->getPackages();
    }

    $return = [];

    // Remove any previous version of the exported archive.
    $archive_name = file_directory_temp() . '/' . $this->profileSettings['machine_name'] . '.tar.gz';
    if (file_exists($archive_name)) {
      file_unmanaged_delete($archive_name);
    }

    $archiver = new ArchiveTar($archive_name);

    if ($add_profile) {
      $profile = $this->getProfile();
      $this->archivePackage($return, $profile, $archiver);
    }

    // Add package files.
    foreach ($packages as $package) {
      $this->archivePackage($return, $package, $archiver);
    }

    return $return;
  }

  /**
   * Write a package or profile's files to an archive.
   *
   * @param array &$return
   *   The return value, passed by reference.
   * @param array $package
   *   The package or profile.
   * @param ArchiveTar $archiver
   *   The archiver.
   */
  protected function archivePackage(array &$return, array $package, ArchiveTar $archiver) {
    $success = TRUE;
    foreach ($package['files'] as $file) {
      try {
        $this->archiveFile($archiver, $file);
      }
      catch(\Exception $exception) {
        $this->archiveFailure($return, $package, $exception);
        $success = FALSE;
        break;
      }
    }
    if ($success) {
      $this->archiveSuccess($return, $package);
    }
  }

  /**
   * Register a successful package or profile archive operation.
   *
   * @param array &$return
   *   The return value, passed by reference.
   * @param array $package
   *   The package or profile.
   */
  protected function archiveSuccess(array &$return, array $package) {
    $type = $package['type'] == 'module' ? $this->t('Package') : $this->t('Profile');
    $return[] = [
      'success' => TRUE,
      'message' => $this->t('!type @package written to archive.'),
      'variables' => [
        '!type' => $type,
        '@package' => $package['name']
      ],
    ];
  }

  /**
   * Register a failed package or profile archive operation.
   *
   * @param array &$return
   *   The return value, passed by reference.
   * @param array $package
   *   The package or profile.
   * @param Exception $exception
   *   The exception object.
   */
  protected function archiveFailure(&$return, array $package, Exception $exception) {
    $type = $package['type'] == 'package' ? $this->t('Package') : $this->t('Profile');
    $return[] = [
      'success' => FALSE,
      'message' => $this->t('!type @package not written to archive. Error: @error.'),
      'variables' => [
        '!type' => $type,
        '@package' => $package['name'],
        '@error' => $exception->getMessage()
      ],
    ];
  }

  /**
   * Write a file to the file system, creating its directory as needed.
   *
   * @param ArchiveTar $archiver
   *   The archiver.
   * @param array $file
   *   Array with keys 'filename' and 'string'.
   *
   * @throws Exception
   */
  protected function archiveFile(ArchiveTar $archiver, array $file) {
    if ($archiver->addString($file['filename'], $file['string']) === FALSE) {
      throw new \Exception($this->t('Failed to archive file @filename.', ['@filename' => basename($file['filename'])]));
    }
  }

  /**
   * Write to the file system specified packages, or of all packages if none
   * are specified.
   *
   * @param boolean $add_profile
   *   Whether to add an install profile. Defaults to FALSE.
   * @param array $packages
   *   Array of package data.
   *
   * @return array
   *   Array of results for profile and/or packages, each result including the
   *   following keys:
   *   - 'success': boolean TRUE or FALSE for successful writing.
   *   - 'message': a message about the result of the operation.
   *   - 'variables': an array of substitutions to be used in the message.
   */
  protected function generateWrite($add_profile = FALSE, array $packages = array()) {
    // If no packages were specified, get all packages.
    if (empty($packages)) {
      $packages = $this->getPackages();
    }

    // If it's a profile, write it to the 'profiles' directory. Otherwise,
    // it goes in 'modules/custom'.
    $base_directory = $add_profile ? 'profiles' : 'modules/custom';

    $return = [];

    // Add profile files.
    if ($add_profile) {
      $profile = $this->getProfile();
      $this->writePackage($return, $profile, $base_directory);
    }

    // Add package files.
    foreach ($packages as $package) {
      $this->writePackage($return, $package, $base_directory);
    }
    return $return;
  }

  /**
   * Write a package or profile's files to the file system.
   *
   * @param array &$return
   *   The return value, passed by reference.
   * @param array $package
   *   The package or profile.
   * @param string $base_directory
   *   The base directory.
   */
  protected function writePackage(array &$return, array $package, $base_directory) {
    $success = TRUE;
    foreach ($package['files'] as $file) {
      try {
        $this->writeFile($base_directory, $file);
      }
      catch(Exception $exception) {
        $this->writeFailure($return, $package, $base_directory, $exception);
        $success = FALSE;
        break;
      }
    }
    if ($success) {
      $this->writeSuccess($return, $package, $base_directory);
    }
  }

  /**
   * Register a successful package or profile write operation.
   *
   * @param array &$return
   *   The return value, passed by reference.
   * @param array $package
   *   The package or profile.
   * @param string $base_directory
   *   The base directory.
   */
  protected function writeSuccess(&$return, $package, $base_directory) {
    $directory = $base_directory . '/' . dirname($package['files']['info']['filename']);
    $type = $package['type'] == 'module' ? $this->t('Package') : $this->t('Profile');
    $return[] = [
      'success' => TRUE,
      'message' => $this->t('!type @package written to @directory.'),
      'variables' => [
        '!type' => $type,
        '@package' => $package['name'],
        '@directory' => $directory
      ],
    ];
  }

  /**
   * Register a failed package or profile write operation.
   *
   * @param array &$return
   *   The return value, passed by reference.
   * @param array $package
   *   The package or profile.
   * @param string $base_directory
   *   The base directory.
   * @param Exception $exception
   *   The exception object.
   */
  protected function writeFailure(&$return, $package, $base_directory, Exception $exception) {
    $directory = $base_directory . '/' . dirname($package['files']['info']['filename']);
    $type = $package['type'] == 'package' ? $this->t('Package') : $this->t('Profile');
    $return[] = [
      'success' => FALSE,
      'message' => $this->t('!type @package not written to @directory. Error: @error.'),
      'variables' => [
        '!type' => $type,
        '@package' => $package['name'],
        '@directory' => $directory,
        '@error' => $exception->getMessage()
      ],
    ];
  }

  /**
   * Write a file to the file system, creating its directory as needed.
   *
   * @param string $base_directory
   *   Directory to prepend to file path.
   * @param array $file
   *   Array with keys 'filename' and 'string'.
   *
   * @throws Exception
   */
  protected function writeFile($base_directory, $file) {
    $directory = $base_directory . '/' . dirname($file['filename']);
    if (!is_dir($directory)) {
      if (drupal_mkdir($directory, NULL, TRUE) === FALSE) {
        throw new \Exception($this->t('Failed to create directory @directory.', ['@directory' => $directory]));
      }
    }
    if (file_put_contents($base_directory . '/' . $file['filename'], $file['string']) === FALSE) {
      throw new \Exception($this->t('Failed to write file @filename.', ['@filename' => basename($file['filename'])]));
    }
  }
}
