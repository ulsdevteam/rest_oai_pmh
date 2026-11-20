<?php

namespace Drupal\rest_oai_pmh\Plugin\QueueWorker;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\views\Views;

/**
 * Abstract class to implement an OAI cache queue worker.
 */
abstract class RestOaiPmhViewsCacheBase extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  use DependencySerializationTrait;

  /**
   * A connection to Drupal's database.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $db;

  /**
   * The OAI set ID being processed.
   *
   * @var string
   */
  protected $setId;

  /**
   * The entity type being exposed to OAI-PMH.
   *
   * @var string
   */
  protected $memberEntityType;

  /**
   * The entity storage interface for $memberEntityType.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $memberEntityStorage;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->db = \Drupal::database();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
          $configuration,
          $plugin_id,
          $plugin_definition
      );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    $view_id = $data['view_id'];
    $display_id = $data['display_id'];
    $offset = $data['offset'] ?? NULL;
    $limit = $data['limit'];
    // The size of the OAI-PMH response pages. Jobs queued before this
    // option existed used the chunk size for both.
    $pager_limit = $data['pager_limit'] ?? $limit;
    $arguments = $data['arguments'];
    $this->setId = $data['set_id'];
    $set_entity_type = $data['set_entity_type'];
    $set_label = $data['set_label'];
    $view_display = $data['view_display'];

    // Load the View and apply the display ID.
    $view = Views::getView($view_id);
    $view->setDisplay($display_id);
    $view->getDisplay()->setOption('entity_reference_options', ['limit' => $limit]);
    if (!is_null($offset)) {
      $view->setOffset($offset);
    }

    // Get this chunk of results from the View. The total was already worked
    // out when the jobs were queued, so there is no need to pay for a count
    // query on every chunk.
    $members = $view->executeDisplay($display_id, $arguments);
    // If some results were returned, save them to our rest_oai_pmh_* tables.
    //
    // Deliberately nothing is removed here when a chunk comes back empty: this
    // chunk may just be past the end because content was removed mid-build.
    // Anything that no longer belongs is cleaned up by the reconcile step
    // once the whole rebuild has been drained.
    if (!empty($members)) {
      // Init the variables used for the UPSERT db call to add/update this SET.
      $merge_keys = [
        'entity_type',
        'set_id',
      ];
      $merge_values = [
        $set_entity_type,
        $this->setId,
      ];
      $this->db->merge('rest_oai_pmh_set')
        ->keys($merge_keys, $merge_values)
        ->fields(
                [
                  'label' => $set_label,
                  'pager_limit' => $pager_limit,
                  'view_display' => $view_display,
                ]
            )->execute();

      // See what type of entity was returned by the View and
      // set variable accordingly so we can load the entity.
      $this->memberEntityType = $view->getBaseEntityType()->id();
      $this->memberEntityStorage = \Drupal::entityTypeManager()->getStorage($this->memberEntityType);

      // Add the results returned to the OAI cache tables.
      $this->indexViewRecords($members);
    }
  }

  /**
   * Helper function. Add items returned by a view to the OAI cache tables.
   */
  protected function indexViewRecords($members = FALSE) {
    $build_id = rest_oai_pmh_build_id();
    // Load the entities in one go, partly to ensure they exist, also to get
    // the changed/created properties.
    $entities = $this->memberEntityStorage->loadMultiple(array_keys($members));
    foreach ($entities as $entity) {
      rest_oai_pmh_index_entity($entity, $this->setId, $build_id);
    }
  }

}
