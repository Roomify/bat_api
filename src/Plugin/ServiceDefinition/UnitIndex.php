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
use Drupal\bat_unit\Entity\TypeBundle;

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

    $return_children = TRUE;

    $create_event_access = FALSE;
    if (bat_event_access(bat_event_create2(array('type' => $event_type)), 'create', \Drupal::currentUser())) {
      $create_event_access = TRUE;
    }

    $ids = array_filter(explode(',', $unit_ids));

    if ($unit_types == 'all') {
      $types = array();

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

    $target_entity_type = $bat_event_type->target_entity_type;

    $units = array();

    foreach ($types as $type) {
      $entities = $this->getReferencedIds($type, $ids);

      $childrens = array();

      foreach ($entities as $entity) {
        $childrens[$entity['type_id']][] = array(
          'id' => 'S' . $entity['id'],
          'title' => $entity['name'],
          'create_event' => $create_event_access,
        );
      }

      foreach ($childrens as $type_id => $children) {
        $unit_type = bat_type_load($type_id);

        if ($return_children) {
          $units[] = array(
            'id' => $unit_type->id(),
            'title' => $unit_type->label(),
            'children' => $children,
          );
        }
        else {
          $units[] = array(
            'id' => $unit_type->id(),
            'title' => $unit_type->label(),
          );
        }
      }
    }

    return $units;
  }

  public function getReferencedIds($unit_type, $ids = array()) {
    $query = db_select('unit', 'n')
            ->fields('n', array('id', 'unit_type_id', 'type', 'name'));
    if (!empty($ids)) {
      $query->condition('id', $ids, 'IN');
    }
    $query->condition('unit_type_id', $unit_type);
    $bat_units = $query->execute()->fetchAll();

    $units = array();
    foreach ($bat_units as $unit) {
      $units[] = array(
        'id' => $unit->id,
        'name' => $unit->name,
        'type_id' => $unit_type,
      );
    }

    return $units;
  }

}
