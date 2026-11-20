# REST OAI-PMH

This REST OAI_PMH module exposes dublin core field mappings
to an OAI-PMH endpoint using Views.
For a full description of the module visit:
https://www.drupal.org/project/rest_oai_pmh

To submit bug reports and feature suggestions, or to track changes visit:
https://www.drupal.org/project/issues/rest_oai_pmh


## Install

Install via `composer require drupal/rest_oai_pmh`

 Visit https://www.drupal.org/node/1897420 for further information.

## Enable

When you enable this module a REST endpoint will be exposed, and any user that accesses your site
that has the "Access GET on OAI-PMH" permission and can view the entities exposed to the endpoint
will have access to your OAI-PMH endpoint.

## Configure

1. If you haven't already, set the Dublin Core Metatag mappings for the entities you want to expose to OAI-PMH.
This can be done with the RDF module, Metatag's Dublin Core module, or a custom module/theme overriding this module's template.
The mapping is how the endpoint will print metadata for the records and sets.
2. Create a an Entity Reference View display for each set you want exposed to OAI-PMH. Optionally, in your View you can provide a contextual filter to an Entity Reference field. If you do so, the entities in that field will be used as the sets.
3. In the Drupal permissions admin UI, grant anonymous users the "Access GET on OAI-PMH" permissions" permission if you want anonymous users (i.e. OAI-PMH Harvesters) to be able to access the endpoint. You'll also want to be sure anonymous users can view published content.
![Screenshot of permissions field](https://www.drupal.org/files/project-images/Screen%20Shot%20on%202019-04-24%20at%2011-32-43.png)
4. Go to the REST OAI-PMH configuration form at /admin/config/services/rest/oai-pmh and supply your system configuration.

## MODS

### Configure

A simple view ("Article MODS Mapping") and twig template (`mods.html.twig`) using Drupal's Article content type has been included as an example.

1. Modify the template `mods.html.twig` found in the module's `templates` folder to suit the specific needs for the repository.
2. Create a View to map entity fields to their MODS element.
    - The View should have a contextual filter for content ID.
    - For each field you wish to display, add it to the list of fields and create a label for the field, setting it to the variable name used within the twig template.
3. Set the View machine name and display name in the REST OAI-PMH configuration form (`/admin/config/services/rest/oai-pmh`). To use the simple View included, set the "View Machine Name" to be `article_mods_mapping` and set the "View Display Name" to be `default`.

## Future Development

* The only supported OAI-PMH metadata formats currently is oai_dc. Plan to cross-walk oai_dc mapping into METS/MODS eventually

## How the OAI index is kept up to date

Harvesters are served from the module's own tables (`rest_oai_pmh_record`,
`_member` and `_set`), not from the View directly. The View is only run to
build those tables.

* **Single record sync.** With the Liberal strategy, saving an entity of a type
  the exposed Views query queues one job on the `rest_oai_pmh_entity_sync`
  queue. The job asks the View display whether that one entity belongs and
  adds, updates or removes it. The queue has its own cron time slice, so new
  and changed records appear promptly even during a long rebuild.
* **Full rebuild.** Started from `/admin/config/services/rest/oai-pmh/queue`,
  by `drush php:eval 'rest_oai_pmh_cache_views();'`, or when an exposed View
  is changed. The tables are *not* emptied: the existing index keeps being
  served while the rebuild runs. When the rebuild queue has drained, rows the
  rebuild did not confirm are re-checked individually and removed if they no
  longer belong.
* **Chunk size.** `drush cset rest_oai_pmh.settings build_chunk_size 200`
  makes rebuild jobs index 200 rows at a time without changing how many
  records harvesters receive per OAI-PMH response (that remains the View
  display's pager size).
* **Paging.** List verbs are ordered by last change (oldest first) and
  resumption tokens resume after the last record sent, so records added or
  changed mid-harvest are picked up at the end of the list.
* **Views cache.** Set the exposed display's caching to *None*: it is only run
  as an indexing source and must never answer from a cached result.
