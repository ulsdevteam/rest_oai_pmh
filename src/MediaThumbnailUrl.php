<?php

namespace Drupal\rest_oai_pmh;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Url;
use Drupal\Component\Utility\UrlHelper;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;

/**
 * Custom data converter for RDF mappings, specialized for image URLs.
 */
class MediaThumbnailUrl {

  /**
   * Generates an absolute URL for a media thumbnail.
   *
   * This callback can be used on media reference fields such as
   * `field_representative_image` or `field_thumbnail`. If the current field
   * item does not directly reference a media entity, it will fall back to the
   * dgi_image_discovery service when available.
   *
   * Supported argument formats:
   * - ['thumbnail']
   * - ['image_style' => 'thumbnail']
   * - ['style' => 'thumbnail']
   *
   * @param mixed $field_data
   *   The raw field item value array.
   * @param mixed $args
   *   Optional callback arguments.
   * @param array $context
   *   Callback context from the RDF mapper.
   *
   * @return string
   *   The absolute thumbnail URL, or an empty string if one cannot be built.
   */
  public static function makeAbsoluteUrl($field_data, $args = NULL, array $context = []) : string {
    $media = self::resolveMedia($context);
    if (!$media) {
      $media = self::discoverMedia($context['entity'] ?? NULL);
    }

    if (!$media) {
      return '';
    }

    $image = self::resolveFile($media);
    if (!$image) {
      return '';
    }

    $style_id = self::resolveStyleId($args);
    $style = \Drupal::entityTypeManager()->getStorage('image_style')->load($style_id);
    if (!$style) {
      \Drupal::logger('rest_oai_pmh')->warning('Unable to load image style "@style" for RDF thumbnail generation.', [
        '@style' => $style_id,
      ]);
      return '';
    }

    return self::makeAbsoluteFromUrl($style->buildUrl($image->getFileUri()));
  }

  /**
   * Resolve a media entity directly from the current field context.
   */
  protected static function resolveMedia(array $context) : ?MediaInterface {
    $field_item = $context['field_item'] ?? NULL;
    if (!($field_item instanceof FieldItemInterface)) {
      return NULL;
    }

    $referenced_entity = $field_item->entity ?? NULL;
    if (!($referenced_entity instanceof MediaInterface) || !$referenced_entity->access('view')) {
      return NULL;
    }

    return $referenced_entity;
  }

  /**
   * Resolve a media entity using dgi_image_discovery when available.
   */
  protected static function discoverMedia(mixed $entity) : ?MediaInterface {
    if (!$entity instanceof ContentEntityInterface || !\Drupal::hasService('dgi_image_discovery.service')) {
      return NULL;
    }

    try {
      $event = \Drupal::service('dgi_image_discovery.service')->getImage($entity);
      $media = $event->getMedia();
      return $media instanceof MediaInterface ? $media : NULL;
    }
    catch (\Throwable $e) {
      \Drupal::logger('rest_oai_pmh')->warning('Failed to discover media for RDF thumbnail generation on entity @entity_type:@entity_id. Error: @error', [
        '@entity_type' => $entity->getEntityTypeId(),
        '@entity_id' => $entity->id(),
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Resolve the image file behind a media entity.
   */
  protected static function resolveFile(MediaInterface $media) : ?FileInterface {
    try {
      $file_id = $media->getSource()->getSourceFieldValue($media);
      if (empty($file_id)) {
        return NULL;
      }

      $file = \Drupal::entityTypeManager()->getStorage('file')->load($file_id);
      return $file instanceof FileInterface ? $file : NULL;
    }
    catch (\Throwable $e) {
      \Drupal::logger('rest_oai_pmh')->warning('Failed to resolve image file for media @media_id during RDF thumbnail generation. Error: @error', [
        '@media_id' => $media->id(),
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Resolve the requested image style ID.
   */
  protected static function resolveStyleId($args) : string {
    if (is_string($args) && $args !== '') {
      return $args;
    }

    if (is_array($args)) {
      if (!empty($args['image_style'])) {
        return $args['image_style'];
      }
      if (!empty($args['style'])) {
        return $args['style'];
      }
      if (!empty($args[0]) && is_string($args[0])) {
        return $args[0];
      }
    }

    return 'thumbnail';
  }

  /**
   * Convert a generated URL into an absolute URL if needed.
   */
  protected static function makeAbsoluteFromUrl(string $url) : string {
    if ($url === '' || UrlHelper::isExternal($url)) {
      return $url;
    }

    try {
      if (str_starts_with($url, '/')) {
        return Url::fromUserInput($url, ['absolute' => TRUE])->toString();
      }

      return Url::fromUri('base:' . $url, ['absolute' => TRUE])->toString();
    }
    catch (\Exception $e) {
      \Drupal::logger('rest_oai_pmh')->warning('Failed to generate absolute thumbnail URL from "@url". Error: @error', [
        '@url' => $url,
        '@error' => $e->getMessage(),
      ]);
      return '';
    }
  }

}
