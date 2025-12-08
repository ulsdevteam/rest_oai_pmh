<?php

namespace Drupal\rest_oai_pmh;

use Drupal\Core\Url;

/**
 * Custom data converter for RDF mappings, specialized for URL conversion.
 */
class PathConverter {

  /**
   * Converts a relative path (e.g., /my-alias-path) into a full absolute URL.
   *
   * This method is designed to be used as an RDF datatype_callback
   * for fields like the 'path' pseudo-field.
   *
   * @param mixed $path_data
   *   The relative path (e.g., /my-alias-path) passed as the field value.
   *
   * @return string
   *   The full, absolute URI (e.g., https://example.com/my-alias-path).
   */
  public static function makeAbsoluteUrl($path_data) {
    $path = '';
    if (is_array($path_data) && isset($path_data['alias'])) {
      $path = $path_data['alias'];
    }
    elseif (is_string($path_data)) {
      $path = $path_data;
    }
    if (empty($path) || $path === '/') {
      return '';
    }

    try {
      $internal_uri = "internal:{$path}";
      $url = Url::fromUri($internal_uri, ['absolute' => TRUE]);
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
