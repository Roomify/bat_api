<?php
/**
 * @file
 * Contains \Drupal\bat_api\Plugin\ServiceDefinition\MatchingUnitIndex.php
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

use Roomify\Bat\Calendar\Calendar;
use Roomify\Bat\Store\DrupalDBStore;
use Roomify\Bat\Unit\Unit;

/**
 * @ServiceDefinition(
 *   id = "matching_unit_index",
 *   methods = {
 *     "GET"
 *   },
 *   translatable = true,
 *   deriver = "\Drupal\bat_api\Plugin\Deriver\MatchingUnitIndex"
 * )
 */
class MatchingUnitIndex extends ServiceDefinitionBase implements ContainerFactoryPluginInterface {

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
    $unit_types = $request->query->get('unit_types');
    $start_date = $request->query->get('start_date');
    $end_date = $request->query->get('end_date');
    $event_type = $request->query->get('event_type');
    $event_states = $request->query->get('event_states');

    if ($unit_types == 'all') {
      $unit_types = [];
      foreach (bat_unit_get_types() as $type => $info) {
        $unit_types[] = $type;
      }
    }
    else {
      $unit_types = array_filter(explode(',', $unit_types));
    }

    $states = array_filter(explode(',', $event_states));

    $events_json = [];

    // Get the event type definition from Drupal
    $bat_event_type = bat_event_type_load($event_type);

    $target_entity_type = $bat_event_type->target_entity_type;

    // For each type of event create a state store and an event store
    $state_store = new DrupalDBStore($event_type, DrupalDBStore::BAT_STATE);

    $start_date_object = new \DateTime($start_date);
    $end_date_object = new \DateTime($end_date);

    $today = new \DateTime();
    if (!\Drupal::currentUser()->hasPermission('view past event information') && $today > $start_date_object) {
      if ($today > $end_date_object) {
        $return->events = [];
        return $return;
      }
      $start_date_object = $today;
    }

    foreach ($unit_types as $unit_type) {
      $entities = $this->getReferencedIds($unit_type);

      $childrens = [];

      $units = [];
      foreach ($entities as $entity) {
        $target_entity = entity_load($target_entity_type, $entity['id']);
        $units[] = new Unit($entity['id'], $target_entity->getEventDefaultValue($event_type));
      }

      if (!empty($units)) {
        $dates = [
          $end_date_object->getTimestamp() => $end_date_object,
        ];

        $calendar = new Calendar($units, $state_store);

        $event_ids = $calendar->getEvents($start_date_object, $end_date_object);

        foreach ($event_ids as $unit_id => $unit_events) {
          foreach ($unit_events as $key => $event) {
            $event_start_date = $event->getStartDate();
            $dates[$event_start_date->getTimestamp()] = $event_start_date;
          }
        }

        ksort($dates);
        $dates = array_values($dates);

        for ($i = 0; $i < (count($dates) - 1); $i++) {
          $sd = $dates[$i];
          $ed = clone($dates[$i + 1]);
          $ed->sub(new \DateInterval('PT1M'));

          $response = $calendar->getMatchingUnits($sd, $ed, $states, [], FALSE, FALSE);

          if (count(array_keys($response->getIncluded()))) {
            $color = 'green';
          }
          else {
            $color = 'red';
          }
          $events_json[] = [
            'id' => $unit_type,
            'resourceId' => $unit_type,
            'start' => $sd->format('Y-m-d') . 'T' . $sd->format('H:i:00'),
            'end' => $ed->format('Y-m-d') . 'T' . $ed->format('H:i:00'),
            'color' => $color,
            'rendering' => 'background',
            'blocking' => 0,
            'title' => '',
          ];
        }
      }
    }

    $events_json = bat_api_merge_non_blocking_events($events_json);

    return array_values($events_json);
  }

  public function getReferencedIds($unit_type, $ids = []) {
    $query = db_select('unit', 'n')
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
