<?php
/**
 * @file
 * Contains \Drupal\bat_api\Plugin\ServiceDefinition\EventsIndex.php
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
 *   id = "events_index",
 *   methods = {
 *     "GET"
 *   },
 *   translatable = true,
 *   deriver = "\Drupal\bat_api\Plugin\Deriver\EventsIndex"
 * )
 */
class EventsIndex extends ServiceDefinitionBase implements ContainerFactoryPluginInterface {

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
    $target_ids = $request->query->get('target_ids');
    $target_types = $request->query->get('target_types');
    $target_entity_type = $request->query->get('target_entity_type');
    $start_date = $request->query->get('start_date');
    $end_date = $request->query->get('end_date');
    $event_types = $request->query->get('event_types');

    $target_types = array_filter(explode(',', $target_types));

    $types = array_filter(explode(',', $event_types));

    $events_json = [];

    foreach ($types as $type) {
      $database = Database::getConnectionInfo('default');
      $prefix = (isset($database['default']['prefix']['default'])) ? $database['default']['prefix']['default'] : '';

      $event_store = new DrupalDBStore($type, DrupalDBStore::BAT_EVENT, $prefix);

      $start_date_object = new \DateTime($start_date);
      $end_date_object = new \DateTime($end_date);

      $today = new \DateTime();
      if (!\Drupal::currentUser()->hasPermission('view past event information') && $today > $start_date_object) {
        if ($today > $end_date_object) {
          return [];
        }

        $start_date_object = $today;
      }

      $ids = explode(',', $target_ids);

      $units = [];
      foreach ($ids as $id) {
        if ($target_entity = entity_load($target_entity_type, $id)) {
          if (in_array($target_entity->type, $target_types) || empty($target_types)) {
            // Setting the default value to 0 since we are dealing with the events array
            // so getting event IDs.
            $units[] = new Unit($id, 0);
          }
        }
      }

      if (!empty($units)) {
        $event_calendar = new Calendar($units, $event_store);
        $event_ids = $event_calendar->getEvents($start_date_object, $end_date_object);

        $bat_event_type = bat_event_type_load($type);
        if ($bat_event_type->getFixedEventStates()) {
          $event_formatter = new FullCalendarFixedStateEventFormatter($bat_event_type);
        }
        else {
          $event_formatter = new FullCalendarOpenStateEventFormatter($bat_event_type);
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

    return $events_json;
  }

}
