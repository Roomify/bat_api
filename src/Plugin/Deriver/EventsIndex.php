<?php
/**
 * @file
 * Contains \Drupal\bat_api\Plugin\Deriver\EventsIndex.php
 */

namespace Drupal\bat_api\Plugin\Deriver;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class EventsIndex extends DeriverBase implements ContainerDeriverInterface {

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id) {
    return new static(
      $container->get('router.route_provider'),
      $container->get('entity_type.manager')
    );
  }

  public function getDerivativeDefinitions($base_plugin_definition) {
    $entity_type_id = 'events';

    $this->derivatives[$entity_type_id] = $base_plugin_definition;
    $this->derivatives[$entity_type_id]['title'] = t('Events');
    $this->derivatives[$entity_type_id]['description'] = t('Index of events objects.');
    $this->derivatives[$entity_type_id]['category'] = t('Events');
    $this->derivatives[$entity_type_id]['path'] = "$entity_type_id";

    return $this->derivatives;
  }

}
