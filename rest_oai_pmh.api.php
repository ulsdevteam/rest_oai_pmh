<?php

/**
 * @file
 * Hooks specific to the REST OAI-PMH module.
 */

/**
 * Override the location of the template file used by a metadata plugin.
 *
 * @param array $template
 *   The OaiMetadataMap "template" found in the Plugin's annotations
 *     e.g. Drupal\rest_oai_pmh\Plugin\OaiMetadataMap\DublinCoreMetatag's
 *     template passed here would be
 *     [
 *       "type" => "module",
 *       "name" => "rest_oai_pmh",
 *       "directory" => "templates",
 *       "file" => "oai-default"
 *     ].
 */
function hook_rest_oai_pmh_metadata_template_alter(array &$template) {
  // Use my module's template file to render mods metadata.
  if ($template['file'] == 'mods') {
    // This would use the template path/to/mymodule/templates/mods.html.twig
    // for mods metadata rendered in OAI-PMH.
    $template['name'] = 'mymodule';
  }
}

/**
 * Alters list of possible ids (sets) for views argument
 *
 * @param array $ids
 * @param \Drupal\views\Plugin\views\argument\ArgumentPluginBase $contextual_filter
 * @return void
 */
function hook_rest_oai_pmh_views_args_alter(array &$ids, $contextual_filter) {
  if($contextual_filter->view->storage->id() == 'view_name' && $contextual_filter->view->current_display == 'view_display') {
    $ids = [1, 2, 3];
  }
}
