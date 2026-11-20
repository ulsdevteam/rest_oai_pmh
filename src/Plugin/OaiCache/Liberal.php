<?php

namespace Drupal\rest_oai_pmh\Plugin\OaiCache;

use Drupal\rest_oai_pmh\Plugin\OaiCacheBase;

/**
 * Liberal cache clearing strategy.
 *
 * Keep the OAI index current as entities are added/updated/deleted.
 *
 * Inserts and updates queue a cheap single-record check (see
 * RestOaiPmhEntitySync). They never empty the index and never trigger a full
 * rebuild. Only a change to an exposed View's configuration does that, and
 * even then the existing index keeps being served until the rebuild finishes.
 *
 * @OaiCache(
 *  id = "liberal_cache",
 *  label = @Translation("Liberal Cache Clearing Strategy")
 * )
 */
class Liberal extends OaiCacheBase {

  /**
   * {@inheritdoc}
   */
  public function clearCache($entity, $op) {
    if ($op === 'delete') {
      parent::clearCache($entity, $op);
      return;
    }

    if ($entity->getEntityTypeId() === 'view') {
      $this->viewChanged($entity);
      return;
    }

    // Any other entity: let the sync worker decide whether it is (still) a
    // member. This must include inserts and updates of entities that are not
    // in the index yet, as publishing a draft is exactly such an update.
    rest_oai_pmh_enqueue_entity_sync($entity, $op);
  }

  /**
   * Reacts to a View being added/updated.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The View config entity.
   */
  protected function viewChanged($entity) {
    $entity_id = $entity->id();
    $config = \Drupal::service('config.factory')->getEditable('rest_oai_pmh.settings');
    $oai_view_displays = $config->get('view_displays') ?: [];

    // Only this View's displays are of interest.
    $own_displays = array_filter($oai_view_displays, fn($view_display) => explode(':', $view_display)[0] === $entity_id);
    if (!$own_displays) {
      return;
    }

    // Forget displays that were deleted from the View.
    $displays = [];
    foreach ($entity->get('display') as $display_id => $display) {
      $displays[] = $entity_id . ':' . $display_id;
    }
    $deleted_displays = array_diff($own_displays, $displays);
    if (count($deleted_displays)) {
      foreach ($deleted_displays as $deleted_display) {
        rest_oai_pmh_remove_sets_by_display_id($deleted_display);
        unset($oai_view_displays[$deleted_display]);
      }
      $config->set('view_displays', $oai_view_displays)->save();
    }

    // Only restart a full rebuild if the View really changed: saving it again
    // unchanged (e.g. a config import) must not discard hours of progress.
    $state = \Drupal::state();
    $key = 'rest_oai_pmh.view_hash.' . $entity_id;
    $hash = md5(serialize($entity->get('display')));
    if ($state->get($key) !== $hash) {
      $state->set($key, $hash);
      rest_oai_pmh_cache_views();
    }
  }

}
