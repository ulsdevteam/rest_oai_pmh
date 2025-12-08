<?php

namespace Drupal\rest_oai_pmh;

use Drupal\Core\Url;

/**
 * Custom data converter for RDF mappings.
 */
class EntityUrl {

  /**
   * Converts a $entity->id to a full absolute URL.
   *
   * This method is designed to be used as an RDF datatype_callback
   * for the 'nid' pseudo-field.
   *
   * @param mixed $id_data
   *   The $entity->id.
   * @param mixed $args
   *   Should contain e.g. ['/node'] or ['/taxonomy/term'].
   *   Defaults to ['/node'].
   *
   * @return string
   *   The full, absolute URI (e.g., https://example.com/my-alias-path).
   */
  public static function makeAbsoluteUrl($id_data, $args) {
    $uri = '';
    if (is_array($id_data) && isset($id_data['value'])) {
      $prefix = empty($args[0]) ? '/node' : $args[0];
      $uri = 'internal:' . rtrim($prefix, '/') . '/' . $id_data['value'];
    }
    if ($uri == '') {
      return '';
    }

    try {
      $url = Url::fromUri($uri, ['absolute' => TRUE]);
      return $url->toString();
    }
    catch (\Exception $e) {
      \Drupal::logger('rest_oai_pmh')->warning('Failed to generate absolute URL for path: @path. Error: @error', [
        '@path' => $path,
        '@error' => $e->getMessage(),
      ]);
      return '';
    }
  }

}
