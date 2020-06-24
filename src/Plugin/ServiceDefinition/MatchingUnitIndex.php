<?php
/**
 * @file
 * Contains \Drupal\bat_api\Plugin\ServiceDefinition\MatchingUnitIndex.php
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

    $target_entity_type = $bat_event_type->getTargetEntityType();

    // For each type of event create a state store and an event store
    $state_store = new DrupalDBStore($event_type, DrupalDBStore::BAT_STATE);

    $start_date_object = new \DateTime($start_date);
    $end_date_object = new \DateTime($end_date);

    $today = new \DateTime();
    if (!$this->currentUser->hasPermission('view past event information') && $today > $start_date_object) {
      if ($today > $end_date_object) {
        return [];
      }
      $start_date_object = $today;
    }

    foreach ($unit_types as $unit_type) {
      $entities = $this->getReferencedIds($unit_type);

      $childrens = [];

      $units = [];
      foreach ($entities as $entity) {
        $target_entity = $this->entityTypeManager->getStorage($target_entity_type)->load($entity['id']);
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

    $context = array(
      'unit_types' => $unit_types,
      'start_date' => $start_date_object,
      'end_date' => $end_date_object,
      'event_type' => $event_type,
      'event_states' => $event_states,
    );

    $this->moduleHandler->alter('bat_api_matching_units_calendar', $events_json, $context);

    return array_values($events_json);
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
