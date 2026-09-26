<?php

namespace Drupal\rest_oai_pmh\Hook;

use Drupal\rest_oai_pmh\Utility\GenerateBatch;
use Drupal\views\Plugin\views\ViewsHandlerInterface;
use Drupal\views\Views;
use Drupal\Core\Url;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\Core\Utility\Error;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
/**
 * Hook implementations for rest_oai_pmh.
 */
class RestOaiPmhHooks
{
    use StringTranslationTrait;
    /**
     * Implements hook_help().
     */
    #[Hook('help')]
    public function help($route_name, \Drupal\Core\Routing\RouteMatchInterface $route_match)
    {
        switch ($route_name) {
            // Main module help for the rest_oai_pmh module.
            case 'help.page.rest_oai_pmh':
                $output = '';
                $output .= '<h3>' . $this->t('About') . '</h3>';
                $output .= '<p>' . $this->t('Exposes schema.org dublin core mappings in an OAI-PMH endpoint') . '</p>';
                return $output;
            default:
        }
    }
    /**
     * Implements hook_rest_resource_alter().
     */
    #[Hook('rest_resource_alter')]
    public function restResourceAlter(&$definitions)
    {
        // If the repository admin set a path, override the default URI.
        $path = \Drupal::config('rest_oai_pmh.settings')->get('repository_path');
        if ($path) {
            $definitions['oai_pmh']['uri_paths']['canonical'] = $path;
            $definitions['oai_pmh']['uri_paths']['https://www.drupal.org/link-relations/create'] = $path;
        }
    }
    /**
     * Implements hook_metatag_tags_alter().
     */
    #[Hook('metatag_tags_alter')]
    public function metatagTagsAlter(&$definitions)
    {
        // Set some dublin core metatags to allow for multiple values.
        $terms = [
            'dcterms_creator',
            'dcterms_subject',
        ];
        foreach ($terms as $term) {
            if (!empty($definitions[$term])) {
                $definitions[$term]['multiple'] = TRUE;
            }
        }
    }
    /**
     * Implements hook_entity_insert().
     */
    #[Hook('entity_insert')]
    public function entityInsert(\Drupal\Core\Entity\EntityInterface $entity)
    {
        rest_oai_pmh_entity_alter($entity, 'insert');
    }
    /**
     * Implements hook_entity_update().
     */
    #[Hook('entity_update')]
    public function entityUpdate(\Drupal\Core\Entity\EntityInterface $entity)
    {
        rest_oai_pmh_entity_alter($entity, 'update');
    }
    /**
     * Implements hook_entity_delete().
     */
    #[Hook('entity_delete')]
    public function entityDelete(\Drupal\Core\Entity\EntityInterface $entity)
    {
        rest_oai_pmh_entity_alter($entity, 'delete');
    }
}
