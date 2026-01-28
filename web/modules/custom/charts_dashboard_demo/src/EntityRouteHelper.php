<?php

namespace Drupal\charts_dashboard_demo;

use Drupal\navigation\EntityRouteHelper as CoreEntityRouteHelper;
use Drupal\Core\Entity\ContentEntityInterface;

/**
 * A safe wrapper for the navigation EntityRouteHelper to avoid null route objects.
 *
 * This class delegates to the core helper but guards against missing Route
 * objects during certain render phases.
 */
class EntityRouteHelper extends CoreEntityRouteHelper {

  /**
   * {@inheritdoc}
   */
  public function isContentEntityRoute(): bool {
    $route_object = $this->routeMatch->getRouteObject();
    if ($route_object === NULL) {
      return FALSE;
    }
    return array_key_exists($route_object->getPath(), $this->getContentEntityPaths());
  }

  /**
   * {@inheritdoc}
   */
  public function getContentEntityFromRoute(): ?ContentEntityInterface {
    $route_object = $this->routeMatch->getRouteObject();
    if ($route_object === NULL) {
      return NULL;
    }

    $path = $route_object->getPath();
    if (!$entity_type = $this->getContentEntityPaths()[$path] ?? NULL) {
      return NULL;
    }

    $entity = $this->routeMatch->getParameter($entity_type);
    if ($entity instanceof ContentEntityInterface && $entity->getEntityTypeId() === $entity_type) {
      return $entity;
    }

    return NULL;
  }

}
