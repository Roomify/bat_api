<?php
/**
 * @file
 * Contains \Drupal\bat_api\Plugin\ServiceDefinition\CalendarEventsIndex.php
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
use Drupal\Core\Database\Database;

use Roomify\Bat\Calendar\Calendar;
use Roomify\Bat\Store\DrupalDBStore;
use Roomify\Bat\Unit\Unit;
use Drupal\bat_fullcalendar\FullCalendarOpenStateEventFormatter;
use Drupal\bat_fullcalendar\FullCalendarFixedStateEventFormatter;

/**
 * @ServiceDefinition(
 *   id = "calendar_events_index",
 *   methods = {
 *     "GET"
 *   },
 *   translatable = true,
 *   deriver = "\Drupal\bat_api\Plugin\Deriver\CalendarEventsIndex"
 * )
 */
class CalendarEventsIndex extends ServiceDefinitionBase implements ContainerFactoryPluginInterface {

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
    $event_types = $request->query->get('event_types');
    $background = $request->query->get('background');
    $unit_ids = $request->query->get('unit_ids');

    $start_date = $request->query->get('start');
    $end_date = $request->query->get('end');

    if ($unit_types == 'all') {
      $unit_types = [];
      foreach (bat_unit_get_types() as $type => $info) {
        $unit_types[] = $type;
      }
    }
    else {
      $unit_types = array_filter(explode(',', $unit_types));
    }

    if ($event_types == 'all') {
      $types = [];
      foreach (bat_event_get_types() as $type => $info) {
        $types[] = $type;
      }
    }
    else {
      $types = array_filter(explode(',', $event_types));
    }

    $events_json = [];

    foreach ($types as $type) {
      // Check if user has permission to view calendar data for this event type.
      if (!\Drupal::currentUser()->hasPermission('view calendar data for any ' . $type . ' event')) {
        continue;
      }

      // Get the event type definition from Drupal
      $bat_event_type = bat_event_type_load($type);

      $target_entity_type = $bat_event_type->target_entity_type;

      // For each type of event create a state store and an event store
      $database = Database::getConnectionInfo('default');
      $prefix = (isset($database['default']['prefix']['default'])) ? $database['default']['prefix']['default'] : '';

      $event_store = new DrupalDBStore($type, DrupalDBStore::BAT_EVENT, $prefix);

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

      $ids = array_filter(explode(',', $unit_ids));

      foreach ($unit_types as $unit_type) {
        $entities = $this->getReferencedIds($unit_type, $ids);

        $childrens = [];

        // Create an array of unit objects - the default value is set to 0 since we want
        // to know if the value in the database is actually 0. This will allow us to identify
        // which events are represented by events in the database (i.e. have a value different to 0)
        $units = [];
        foreach ($entities as $entity) {
          $units[] = new Unit($entity['id'], 0);
        }

        if (!empty($units)) {
          $event_calendar = new Calendar($units, $event_store);

          $event_ids = $event_calendar->getEvents($start_date_object, $end_date_object);

          if ($bat_event_type->getFixedEventStates()) {
            $event_formatter = new FullCalendarFixedStateEventFormatter($bat_event_type, $background);
          }
          else {
            $event_formatter = new FullCalendarOpenStateEventFormatter($bat_event_type, $background);
          }

          foreach ($event_ids as $unit_id => $unit_events) {
            foreach ($unit_events as $key => $event) {
              $events_json[] = [
                'id' => (string) $key . $unit_id,
                'bat_id' => $event->getValue(),
                'resourceId' => 'S' . $unit_id,
              ] + $event->toJson($event_formatter);
            }
          }
        }
      }
    }

    $context = array(
      'unit_ids' => $unit_ids,
      'unit_types' => $unit_types,
      'start_date' => $start_date_object,
      'end_date' => $end_date_object,
      'event_types' => $event_types,
      'background' => $background,
    );

    \Drupal::moduleHandler()->alter('bat_api_events_index_calendar', $events_json, $context);

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
