<?php

namespace Drupal\rest_oai_pmh\Plugin\QueueWorker;

use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\views\ViewExecutable;
use Drupal\views\Views;

/**
 * Keeps the OAI-PMH tables in step with a single entity.
 *
 * Jobs are queued from entity insert/update hooks (and by the reconcile step
 * after a full rebuild). Each job asks the configured View displays whether
 * the entity currently belongs, then makes the {rest_oai_pmh_*} tables say the
 * same thing. It does not depend on, and does not disturb, a running full
 * rebuild.
 *
 * This queue has its own cron time slice, so new and changed records reach
 * harvesters promptly even while a very large rebuild is still draining.
 *
 * @QueueWorker(
 *   id = "rest_oai_pmh_entity_sync",
 *   title = @Translation("REST OAI-PMH single record sync"),
 *   cron = {"time" = 60}
 * )
 */
class RestOaiPmhEntitySync extends QueueWorkerBase {

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    $entity_type = $data['entity_type'];
    $entity_id = $data['entity_id'];

    try {
      $storage = \Drupal::entityTypeManager()->getStorage($entity_type);
    }
    catch (PluginNotFoundException $e) {
      // Entity type no longer exists (module uninstalled). Nothing to do.
      return;
    }

    $entity = $storage->load($entity_id);
    if (!$entity) {
      // Deleted since the job was queued.
      rest_oai_pmh_unindex_entity($entity_type, $entity_id);
      return;
    }

    // The entity may provide a set (e.g. a taxonomy term used as a contextual
    // filter value): keep the set name current.
    $is_set = \Drupal::database()->query('SELECT 1 FROM {rest_oai_pmh_set} WHERE entity_type = :t AND set_id = :s', [
      ':t' => $entity_type,
      ':s' => $entity_type . ':' . $entity_id,
    ])->fetchField();
    if ($is_set && $entity->label() !== NULL) {
      \Drupal::database()->update('rest_oai_pmh_set')
        ->fields(['label' => (string) $entity->label()])
        ->condition('set_id', $entity_type . ':' . $entity_id)
        ->execute();
    }

    if (in_array($entity_type, rest_oai_pmh_exposed_entity_types(), TRUE)) {
      $this->syncRecord($entity);
    }
  }

  /**
   * Makes the tables agree with the Views about one record.
   */
  protected function syncRecord(EntityInterface $entity) {
    $db = \Drupal::database();
    $type = $entity->getEntityTypeId();
    $build_id = rest_oai_pmh_build_id();

    // Displays we managed to evaluate, and the sets the entity is in.
    $evaluated = [];
    $member_of = [];

    $displays = \Drupal::config('rest_oai_pmh.settings')->get('view_displays') ?: [];
    foreach ($displays as $view_display) {
      [$view_id, $display_id] = explode(':', $view_display);
      $view = Views::getView($view_id);
      if (!$view || !$view->setDisplay($display_id)) {
        continue;
      }
      $base_type = $view->getBaseEntityType();
      if (!$base_type || $base_type->id() !== $type) {
        continue;
      }
      $evaluated[] = $view_display;
      // Not read from the View: it is only known after execution. Existing
      // sets keep what the rebuild stored; new ones get a sensible default.
      $default_pager_limit = rest_oai_pmh_default_pager_limit($view_display);

      foreach ($this->candidateSets($view, $display_id, $view_display, $entity) as $set) {
        if (!$this->isMember($view_id, $display_id, $set['arguments'], $entity)) {
          continue;
        }
        $db->merge('rest_oai_pmh_set')
          ->keys([
            'entity_type' => $set['entity_type'],
            'set_id' => $set['set_id'],
          ])
          ->insertFields([
            'label' => $set['label'],
            'pager_limit' => $default_pager_limit,
            'view_display' => $view_display,
          ])
          ->updateFields([
            'label' => $set['label'],
            'view_display' => $view_display,
          ])
          ->execute();
        rest_oai_pmh_index_entity($entity, $set['set_id'], $build_id);
        $member_of[] = $set['set_id'];
      }
    }

    $this->removeStaleMemberships($type, $entity->id(), $member_of, $evaluated);
  }

  /**
   * Lists the sets this entity could be a member of for one display.
   *
   * For a display with no entity-reference contextual filter that is the one
   * set made from the display itself. Otherwise it is every set already known
   * for the display (so the entity can be removed from those it has left) plus
   * every entity the record currently references (so it can join new ones).
   *
   * @return array[]
   *   Each with keys set_id, entity_type, label and arguments.
   */
  protected function candidateSets(ViewExecutable $view, $display_id, $view_display, EntityInterface $entity) {
    $sets = [];
    $has_reference_filter = FALSE;

    foreach ($view->display_handler->getHandlers('argument') as $handler) {
      [, $set_entity_type] = rest_oai_pmh_determine_set_inclusion($handler);
      if (!$set_entity_type) {
        continue;
      }
      $has_reference_filter = TRUE;

      $ids = [];
      $known = \Drupal::database()->query('SELECT set_id FROM {rest_oai_pmh_set} WHERE view_display = :d AND entity_type = :t', [
        ':d' => $view_display,
        ':t' => $set_entity_type,
      ])->fetchCol();
      foreach ($known as $set_id) {
        $ids[] = explode(':', $set_id, 2)[1] ?? NULL;
      }
      $field = $handler->definition['field_name'] ?? NULL;
      if ($field && $entity->hasField($field)) {
        foreach ($entity->get($field) as $item) {
          $ids[] = $item->target_id ?? NULL;
        }
      }

      $set_storage = \Drupal::entityTypeManager()->getStorage($set_entity_type);
      foreach (array_unique(array_filter($ids, fn($v) => $v !== NULL && $v !== '')) as $set_entity_id) {
        $set_entity = $set_storage->load($set_entity_id);
        if ($set_entity) {
          $sets[] = [
            'set_id' => $set_entity_type . ':' . $set_entity->id(),
            'entity_type' => $set_entity_type,
            'label' => (string) $set_entity->label(),
            'arguments' => [$set_entity->id()],
          ];
        }
      }
    }

    if (!$has_reference_filter) {
      $display = $view->storage->getDisplay($display_id);
      $sets[] = [
        'set_id' => $view_display,
        'entity_type' => 'view',
        'label' => (string) ($display['display_title'] ?? $view_display),
        'arguments' => [],
      ];
    }
    return $sets;
  }

  /**
   * Asks the View whether one entity is currently in it.
   */
  protected function isMember($view_id, $display_id, array $arguments, EntityInterface $entity) {
    $view = Views::getView($view_id);
    if (!$view || !$view->setDisplay($display_id)) {
      return FALSE;
    }
    // A one-off membership probe is not worth a Views cache entry, and must
    // never be answered from one.
    $view->display_handler->setOption('cache', ['type' => 'none', 'options' => []]);
    // Restrict the Entity Reference display to this one ID.
    $view->display_handler->setOption('entity_reference_options', [
      'ids' => [$entity->id()],
      'limit' => 1,
    ]);
    $result = $view->executeDisplay($display_id, $arguments);
    return is_array($result) && isset($result[$entity->id()]);
  }

  /**
   * Removes memberships the entity no longer has, then tidies up.
   *
   * Only memberships of sets from displays that were actually evaluated are
   * touched, so a View that failed to load cannot cause records to vanish.
   */
  protected function removeStaleMemberships($type, $id, array $member_of, array $evaluated) {
    $db = \Drupal::database();
    $rows = $db->query('SELECT m.set_id, s.view_display
      FROM {rest_oai_pmh_member} m
      LEFT JOIN {rest_oai_pmh_set} s ON s.set_id = m.set_id
      WHERE m.entity_type = :t AND m.entity_id = :i', [
        ':t' => $type,
        ':i' => $id,
      ])->fetchAll();

    $removed = [];
    foreach ($rows as $row) {
      if (in_array($row->set_id, $member_of, TRUE)) {
        continue;
      }
      if ($row->view_display !== NULL && !in_array($row->view_display, $evaluated, TRUE)) {
        continue;
      }
      $db->delete('rest_oai_pmh_member')
        ->condition('entity_type', $type)
        ->condition('entity_id', $id)
        ->condition('set_id', $row->set_id)
        ->execute();
      $removed[] = $row->set_id;
    }

    // A record that is in no set is no longer exposed.
    $remaining = $db->query('SELECT 1 FROM {rest_oai_pmh_member} WHERE entity_type = :t AND entity_id = :i LIMIT 1', [
      ':t' => $type,
      ':i' => $id,
    ])->fetchField();
    if (!$remaining) {
      $db->delete('rest_oai_pmh_record')
        ->condition('entity_type', $type)
        ->condition('entity_id', $id)
        ->execute();
    }

    foreach ($removed as $set_id) {
      rest_oai_pmh_delete_set_if_empty($set_id);
    }
  }

}
