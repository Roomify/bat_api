<?php
/**
 * @file
 * Contains \Drupal\bat_api\Plugin\ServiceDefinition\UnitIndex.php
 */

namespace Drupal\bat_api\Plugin\ServiceDefinition;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\services\ServiceDefinitionBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Access\AccessResult;

/**
 * @ServiceDefinition(
 *   id = "unit_index",
 *   methods = {
 *     "GET"
 *   },
 *   translatable = true,
 *   deriver = "\Drupal\bat_api\Plugin\Deriver\UnitIndex"
 * )
 */
class UnitIndex extends ServiceDefinitionBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_manager
   *   The entity manager.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   Current user.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_manager, AccountInterface $current_user, ModuleHandlerInterface $module_handler, Connection $connection) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_manager;
    $this->currentUser = $current_user;
    $this->moduleHandler = $module_handler;
    $this->connection = $connection;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('module_handler'),
      $container->get('database')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    return 0;
  }

  /**
   * {@inheritdoc}
   */
  public function processRequest(Request $request, RouteMatchInterface $route_match, SerializerInterface $serializer) {
    $event_type = $request->query->get('event_type');
    $unit_types = $request->query->get('types');
    $unit_ids = $request->query->get('ids');

    $create_event_access = FALSE;
    if (bat_event_access(bat_event_create(['type' => $event_type]), 'create', $this->currentUser) == AccessResult::allowed()) {
      $create_event_access = TRUE;
    }

    $ids = array_filter(explode(',', $unit_ids));

    if ($unit_types == 'all') {
      $types = [];

      foreach (bat_unit_get_types() as $type) {
        $type_bundle = bat_type_bundle_load($type->bundle());

        if (isset($type_bundle->default_event_value_field_ids[$event_type]) &&
            !empty($type_bundle->default_event_value_field_ids[$event_type])) {
          $types[] = $type->id();
        }
      }
    }
    else {
      $types = array_filter(explode(',', $unit_types));
    }

    $bat_event_type = bat_event_type_load($event_type);

    $target_entity_type = $bat_event_type->getTargetEntityType();

    $units = [];

    foreach ($types as $type) {
      $entities = $this->getReferencedIds($type, $ids);

      $childrens = [];

      foreach ($entities as $entity) {
        $childrens[$entity['type_id']][] = [
          'id' => 'S' . $entity['id'],
          'title' => $entity['name'],
          'create_event' => $create_event_access,
        ];
      }

      foreach ($childrens as $type_id => $children) {
        $unit_type = bat_type_load($type_id);

        $units[] = [
          'id' => $unit_type->id(),
          'title' => $unit_type->label(),
          'children' => $children,
        ];
      }
    }

    $this->moduleHandler->alter('bat_api_units_index_calendar', $units);

    return $units;
  }

  public function getReferencedIds($unit_type, $ids = []) {
    $query = $this->connection->select('unit', 'n')
            ->fields('n', ['id', 'unit_type_id', 'type', 'name']);
    if (!empty($ids)) {
      $query->condition('id', $ids, 'IN');
    }
    $query->condition('unit_type_id', $unit_type);
    $bat_units = $query->execute()->fetchAll();

    $units = [];
    foreach ($bat_units as $unit) {
      $units[] = [
        'id' => $unit->id,
        'name' => $unit->name,
        'type_id' => $unit_type,
      ];
    }

    return $units;
  }

}
