<?php

namespace Drupal\rest_oai_pmh;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Field\FieldItemInterface;
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
   * @param mixed $field_data
   *   The raw field item value array.
   * @param mixed $args
   *   Optional callback arguments; currently unused.
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

    return \Drupal::service('file_url_generator')->generateAbsoluteString($image->getFileUri());
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

}
