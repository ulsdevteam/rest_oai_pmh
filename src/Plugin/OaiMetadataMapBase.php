<?php

namespace Drupal\rest_oai_pmh\Plugin;

use Drupal\Core\Plugin\PluginBase;

/**
 * Base class for OAI Metadata Map plugins.
 */
abstract class OaiMetadataMapBase extends PluginBase implements OaiMetadataMapInterface
{

    /**
     * {@inheritdoc}
     */
    public function build($record)
    {

        $template_info = $this->getPluginDefinition()['template'];
        $record['elements']['#theme'] = "{$template_info['name']}__{$template_info['file']}";
        $return = \Drupal::service('renderer')->renderPlain($record['elements']);
        return $return;
    }

}
