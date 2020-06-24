<?php
/**
 * @file
 * Contains \Drupal\bat_api\Plugin\ServiceDefinition\CalendarEventsIndex.php
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
use Drupal\Core\Database\Database;

use Roomify\Bat\Calendar\Calendar;
use Roomify\Bat\Store\DrupalDBStore;
use Roomify\Bat\Unit\Unit;
use Drupal\bat_fullcalendar\FullCalendarFixedStateEventFormatter;
use Drupal\bat_fullcalendar\FullCalendarOpenStateEventFormatter;

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
   * @var \Drupal\bat_fullcalendar\FullCalendarFixedStateEventFormatter
   */
  protected $fixedStateEventFormatter;

  /**
   * @var \Drupal\bat_fullcalendar\FullCalendarOpenStateEventFormatter
   */
  protected $openStateEventFormatter;

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
   * @param \Drupal\bat_fullcalendar\FullCalendarFixedStateEventFormatter $fixedStateEventFormatter
   * @param \Drupal\bat_fullcalendar\FullCalendarOpenStateEventFormatter $openStateEventFormatter
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_manager, AccountInterface $current_user, ModuleHandlerInterface $module_handler, Connection $connection, FullCalendarFixedStateEventFormatter $fixedStateEventFormatter, FullCalendarOpenStateEventFormatter $openStateEventFormatter) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_manager;
    $this->currentUser = $current_user;
    $this->moduleHandler = $module_handler;
    $this->connection = $connection;
    $this->fixedStateEventFormatter = $fixedStateEventFormatter;
    $this->openStateEventFormatter = $openStateEventFormatter;
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
      $container->get('database'),
      $container->get('bat_fullcalendar.fixed_state_event_formatter'),
      $container->get('bat_fullcalendar.open_state_event_formatter')
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
    $event_types = $request->query->get('event_types');
    $background = $request->query->get('background');
    $unit_ids = $request->query->get('unit_ids');

    $start_date = $request->query->get('start');
    $end_date = $request->query->get('end');

    $start_date_object = new \DateTime($start_date);
    $end_date_object = new \DateTime($end_date);

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
      if (!$this->currentUser->hasPermission('view calendar data for any ' . $type . ' event')) {
        continue;
      }

      // Get the event type definition from Drupal
      $bat_event_type = bat_event_type_load($type);

      $target_entity_type = $bat_event_type->getTargetEntityType();

      // For each type of event create a state store and an event store
      $database = Database::getConnectionInfo('default');
      $prefix = (isset($database['default']['prefix']['default'])) ? $database['default']['prefix']['default'] : '';

      $event_store = new DrupalDBStore($type, DrupalDBStore::BAT_EVENT, $prefix);

      $today = new \DateTime();
      if (!$this->currentUser->hasPermission('view past event information') && $today > $start_date_object) {
        if ($today > $end_date_object) {
          return [];
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
            $event_formatter = $this->fixedStateEventFormatter;
          }
          else {
            $event_formatter = $this->openStateEventFormatter;
          }

          $event_formatter->setEventType($bat_event_type);
          $event_formatter->setBackground($background);

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

    $this->moduleHandler->alter('bat_api_events_index_calendar', $events_json, $context);

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
