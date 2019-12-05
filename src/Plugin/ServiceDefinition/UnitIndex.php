<?php
/**
 * @file
 * Contains \Drupal\bat_api\Plugin\ServiceDefinition\UnitIndex.php
 */

namespace Drupal\bat_api\Plugin\ServiceDefinition;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryFactory;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
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
   * The entity query factory.
   *
   * @var \Drupal\Core\Entity\Query\QueryFactoryInterface
   */
  protected $queryFactory;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity.query'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * @param array $configuration
   * @param string $plugin_id
   * @param mixed $plugin_definition
   * @param \Drupal\Core\Entity\Query\QueryFactory $query_factory
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, QueryFactory $query_factory, EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->queryFactory = $query_factory;
    $this->entityTypeManager = $entity_type_manager;
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

    $return_children = TRUE;

    $create_event_access = FALSE;
    if (bat_event_access(bat_event_create(['type' => $event_type]), 'create', \Drupal::currentUser()) == AccessResult::allowed()) {
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

        if ($return_children) {
          $units[] = [
            'id' => $unit_type->id(),
            'title' => $unit_type->label(),
            'children' => $children,
          ];
        }
        else {
          $units[] = [
            'id' => $unit_type->id(),
            'title' => $unit_type->label(),
          ];
        }
      }
    }

    \Drupal::moduleHandler()->alter('bat_api_units_index_calendar', $units);

    return $units;
  }

  public function getReferencedIds($unit_type, $ids = []) {
    $query = \Drupal::database()->select('unit', 'n')
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
