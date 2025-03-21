<?php

namespace Drupal\user;

use Drupal\Core\Discovery\YamlDiscovery;
use Drupal\Core\Controller\ControllerResolverInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Utility\CallableResolver;

/**
 * Provides the available permissions based on yml files.
 *
 * To define permissions you can use a $module.permissions.yml file. This file
 * defines machine names, human-readable names, restrict access (if required for
 * security warning), and optionally descriptions for each permission type. The
 * machine names are the canonical way to refer to permissions for access
 * checking.
 *
 * If your module needs to define dynamic permissions you can use the
 * permission_callbacks key to declare a callable that will return an array of
 * permissions, keyed by machine name. Each item in the array can contain the
 * same keys as an entry in $module.permissions.yml.
 *
 * Here is an example from the core filter module (comments have been added):
 * @code
 * # The key is the permission machine name, and is required.
 * administer filters:
 *   # (required) Human readable name of the permission used in the UI.
 *   title: 'Administer text formats and filters'
 *   # (optional) Additional description fo the permission used in the UI.
 *   description: 'Define how text is handled by combining filters into text formats.'
 *   # (optional) Boolean, when set to true a warning about site security will
 *   # be displayed on the Permissions page. Defaults to false.
 *   restrict access: false
 *
 * # An array of callables used to generate dynamic permissions.
 * permission_callbacks:
 *   # The callable should return an associative array with one or more
 *   # permissions. Each permission array can use the same keys as the example
 *   # permission defined above. Additionally, a dependencies key is supported.
 *   # For more information about permission dependencies see
 *   # PermissionHandlerInterface::getPermissions().
 *   - Drupal\filter\FilterPermissions::permissions
 * @endcode
 *
 * @see \Drupal\user\PermissionHandlerInterface::getPermissions()
 * @see filter.permissions.yml
 * @see \Drupal\filter\FilterPermissions
 * @see user_api
 */
class PermissionHandler implements PermissionHandlerInterface {

  use StringTranslationTrait;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The YAML discovery class to find all .permissions.yml files.
   *
   * @var \Drupal\Core\Discovery\YamlDiscovery
   */
  protected $yamlDiscovery;

  /**
   * The callable resolver.
   *
   * @var \Drupal\Core\Utility\CallableResolver
   */
  protected CallableResolver $callableResolver;

  /**
   * Constructs a new PermissionHandler.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation.
   * @param \Drupal\Core\Utility\CallableResolver|\Drupal\Core\Controller\ControllerResolverInterface $callable_resolver
   *   The callable resolver.
   * @param \Drupal\Core\Extension\ModuleExtensionList|null $moduleExtensionList
   *   The module extension list.
   */
  public function __construct(ModuleHandlerInterface $module_handler, TranslationInterface $string_translation, ControllerResolverInterface|CallableResolver $callable_resolver, protected ?ModuleExtensionList $moduleExtensionList = NULL) {
    if ($callable_resolver instanceof ControllerResolverInterface) {
      @trigger_error('Calling ' . __METHOD__ . '() with an argument of ControllerResolverInterface is deprecated in drupal:10.3.0 and is removed in drupal:11.0.0. Use \Drupal\Core\Utility\CallableResolver instead. See https://www.drupal.org/node/3397954', E_USER_DEPRECATED);
      $callable_resolver = \Drupal::service('callable_resolver');
    }
    $this->callableResolver = $callable_resolver;

    // @todo It would be nice if you could pull all module directories from the
    //   container.
    $this->moduleHandler = $module_handler;
    $this->stringTranslation = $string_translation;
    if ($this->moduleExtensionList === NULL) {
      @trigger_error('Calling ' . __METHOD__ . '() without the $moduleExtensionList argument is deprecated in drupal:10.3.0 and will be required in drupal:11.0.0. See https://www.drupal.org/node/3310017', E_USER_DEPRECATED);
      $this->moduleExtensionList = \Drupal::service('extension.list.module');
    }
  }

  /**
   * Gets the YAML discovery.
   *
   * @return \Drupal\Core\Discovery\YamlDiscovery
   *   The YAML discovery.
   */
  protected function getYamlDiscovery() {
    if (!isset($this->yamlDiscovery)) {
      $this->yamlDiscovery = new YamlDiscovery('permissions', $this->moduleHandler->getModuleDirectories());
    }
    return $this->yamlDiscovery;
  }

  /**
   * {@inheritdoc}
   */
  public function getPermissions() {
    $all_permissions = $this->buildPermissionsYaml();

    return $this->sortPermissions($all_permissions);
  }

  /**
   * {@inheritdoc}
   */
  public function moduleProvidesPermissions($module_name) {
    // @todo Static cache this information.
    //   https://www.drupal.org/node/2339487
    $permissions = $this->getPermissions();

    foreach ($permissions as $permission) {
      if ($permission['provider'] == $module_name) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Builds all permissions provided by .permissions.yml files.
   *
   * @return array[]
   *   An array with the same structure as
   *   PermissionHandlerInterface::getPermissions().
   *
   * @see \Drupal\user\PermissionHandlerInterface::getPermissions()
   */
  protected function buildPermissionsYaml() {
    $all_permissions = [];
    $all_callback_permissions = [];

    foreach ($this->getYamlDiscovery()->findAll() as $provider => $permissions) {
      // The top-level 'permissions_callback' is a list of methods in callable
      // syntax, see \Drupal\Core\Utility\CallableResolver. These methods
      // should return an array of permissions in the same structure.
      if (isset($permissions['permission_callbacks'])) {
        foreach ($permissions['permission_callbacks'] as $permission_callback) {
          $callback = $this->callableResolver->getCallableFromDefinition($permission_callback);
          if ($callback_permissions = call_user_func($callback)) {
            // Add any callback permissions to the array of permissions. Any
            // defaults can then get processed below.
            foreach ($callback_permissions as $name => $callback_permission) {
              if (!is_array($callback_permission)) {
                $callback_permission = [
                  'title' => $callback_permission,
                ];
              }

              $callback_permission += [
                'description' => NULL,
                'provider' => $provider,
              ];

              $all_callback_permissions[$name] = $callback_permission;
            }
          }
        }

        unset($permissions['permission_callbacks']);
      }

      foreach ($permissions as &$permission) {
        if (!is_array($permission)) {
          $permission = [
            'title' => $permission,
          ];
        }
        $permission['title'] = $this->t($permission['title']);
        $permission['description'] = isset($permission['description']) ? $this->t($permission['description']) : NULL;
        $permission['provider'] = !empty($permission['provider']) ? $permission['provider'] : $provider;
      }

      $all_permissions += $permissions;
    }

    return $all_permissions + $all_callback_permissions;
  }

  /**
   * Sorts the given permissions by provider name and title.
   *
   * @param array $all_permissions
   *   The permissions to be sorted.
   *
   * @return array[]
   *   An array with the same structure as
   *   PermissionHandlerInterface::getPermissions().
   *
   * @see \Drupal\user\PermissionHandlerInterface::getPermissions()
   */
  protected function sortPermissions(array $all_permissions = []) {
    // Get a list of all the modules providing permissions and sort by
    // display name.
    $modules = $this->getModuleNames();

    uasort($all_permissions, function (array $permission_a, array $permission_b) use ($modules) {
      if ($modules[$permission_a['provider']] == $modules[$permission_b['provider']]) {
        return $permission_a['title'] <=> $permission_b['title'];
      }
      else {
        return $modules[$permission_a['provider']] <=> $modules[$permission_b['provider']];
      }
    });
    return $all_permissions;
  }

  /**
   * Returns all module names.
   *
   * @return string[]
   *   Returns the human readable names of all modules keyed by machine name.
   */
  protected function getModuleNames() {
    $modules = [];
    foreach (array_keys($this->moduleHandler->getModuleList()) as $module) {
      $modules[$module] = $this->moduleExtensionList->getName($module);
    }
    asort($modules);
    return $modules;
  }

}
