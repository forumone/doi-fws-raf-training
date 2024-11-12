<?php

namespace Drupal\fws_search\Entity;

use \Drupal\block_content\Entity\BlockContent;
use \Drupal\Core\Entity\EntityStorageInterface;
use \Drupal\Core\Config\Entity\ConfigEntityBase;
use \Drupal\fws_search\SearchAppConfigIfc;
use \Drupal\search_api\Entity\Index;
use \Drupal\search_api\Item\FieldInterface;
use \Symfony\Component\Yaml\Yaml;

/**
 * Defines the FWS SearchAppConfig entity.
 *
 * @ConfigEntityType(
 *   id = "fws_search_app_config",
 *   label = @Translation("FWS Search app config"),
 *   handlers = {
 *     "list_builder" = "Drupal\fws_search\Controller\SearchAppConfigListBuilder",
 *     "form" = {
 *       "add" = "Drupal\fws_search\Form\SearchAppConfigForm",
 *       "edit" = "Drupal\fws_search\Form\SearchAppConfigForm",
 *       "delete" = "Drupal\fws_search\Form\SearchAppConfigDeleteForm",
 *     }
 *   },
 *   config_prefix = "app",
 *   admin_permission = "administer fws_search",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "index",
 *     "root",
 *     "config"
 *   },
 *   links = {
 *     "collection" = "/admin/config/search/fws-search-app-config",
 *     "edit-form" = "/admin/config/search/fws-search-app-config/{fws_search_app_config}",
 *     "delete-form" = "/admin/config/search/fws-search-app-config/{fws_search_app_config}/delete",
 *   }
 * )
 */
class SearchAppConfig extends ConfigEntityBase implements SearchAppConfigIfc
{
    /**
     * The SearchAppConfig ID.
     *
     * @var string
     */
    public $id;

    /**
     * The SearchAppConfig label.
     *
     * @var string
     */
    public $label;

    /**
     * The corresponding search index machine_name.
     *
     * @var string
     */
    public $index;

    /**
     * The app root.
     *
     * @var string
     */
    public $root;

    /**
     * The SearchAppConfig config (YAML).
     *
     * @var string
     */
    public $config;

    /** 
     * The search index
     * 
     * @var \Drupal\search_api\IndexInterface
     */
    private $_index;

    /** @var array */
    private $parsed;


    /**
     * @todo remove searchFilterConfig after client regions implemented
     * @return array An array of string keys for the component regions of the client app.
     */
    public static function clientRegions() {
        return ['top','menu','left','bottom','header','hidden'];
    }

    /**
     * @return string The config.
     */
    public function getConfig() {
        return $this->config;
    }

    /**
     * @param string $config The new config.
     */
    public function setConfig($config) {
        $this->config = $config;
    }

    /**
     * @todo make private
     * @return array Parsed as YAML from config
     */
    public function getParsedConfig() {
        if (!$this->parsed && $this->config) {
            $this->parsed = Yaml::parse($this->config);
        }
        return $this->parsed;
    }

    /**
     * @return \Drupal\fws_search\SearchAppConfigIfc
     */
    public static function getSearchAppConfig($machine_name) {
        return \Drupal::entityTypeManager()->getStorage('fws_search_app_config')->load($machine_name);
    }

    /**
     * @return \Drupal\fws_search\SearchAppConfigIfc[]
     */
    public static function getAllSearchAppConfigs() {
        $storage = \Drupal::entityTypeManager()->getStorage('fws_search_app_config');
        $configs = $storage->getQuery()->accessCheck(TRUE)->execute();
        if(count($configs) > 0) {
            $configs = $storage->loadMultiple(array_keys($configs));
        }
        return $configs;
    }

    /**
     * @return \Drupal\search_api\IndexInterface
     */
    public function getIndex() {
        if (!$this->_index) {
            $this->_index = Index::load($this->index);
        }
        return $this->_index;
    }

    /**
     * Info for a single filter field.
     * @return array
     */
    private function filterInfo($machine_name, FieldInterface $field) {
        return ['filter' => $machine_name] + $field->getSettings();
    }

    /**
     * index fields of type other than 'text' are considered possible filters.
     * 
     * @return array
     */
    private function indexFields() {
        $indexFields = [];
        foreach ($this->getIndex()->getFields() as $machine_name => $field) {
            $indexFields[] = $this->filterInfo($machine_name, $field);
        }
        return $indexFields;
    }

    /**
     * {@inheritdoc}
     */
    public function getAppConfig($bootstrap = FALSE) {
        static $app_config = NULL;
        if(!$app_config) {
            $app = $this->getParsedConfig();
            if(!$app) {
                $app = [];
            }
            if(!isset($app['serviceDefaults'])) {
                $app['serviceDefaults'] = [];
            }
            if(!isset($app['serviceDefaults']['$top'])) {
                $app['serviceDefaults']['$top'] = 5;
            }
            if(!isset($app['serviceDefaults']['$orderby'])) {
                $app['serviceDefaults']['$orderby'] = 'search_api_relevance desc';
            }
            if(!isset($app['serviceDefaults']['view_mode'])) {
                $app['serviceDefaults']['view_mode'] = 'search_result';
            }
            if(!isset($app['serviceDefaults']['print_view_mode'])) {
                $app['serviceDefaults']['print_view_mode'] = 'print';
            }
            if($bootstrap) {
                $block_plugin_manager = NULL;
                $renderer = NULL;
                foreach(self::clientRegions() as $region) {
                    if(isset($app[$region])) {
                        foreach($app[$region] as &$component) {
                            if($component['type'] === 'block' && isset($component['config']) && isset($component['config']['uuid'])) {
                                if(!$renderer) {
                                    $renderer = \Drupal::service('renderer');
                                    $block_plugin_manager = \Drupal::service('plugin.manager.block');
                                }
                                // $component['config'] can contain label/view_mode
                                $config = $component['config'] + [
                                    'view_mode' => 'default'
                                ];
                                unset($config['uuid']);
                                $block_plugin = $block_plugin_manager->createInstance('block_content:'.$component['config']['uuid'], $config);
                                $build = [
                                    'content' => $block_plugin->build(),
                                    '#theme' => 'block',
                                    '#attributes' => [],
                                    '#contextual_links' => [],
                                    '#configuration' => $block_plugin->getConfiguration(),
                                    '#plugin_id' => $block_plugin->getPluginId(),
                                    '#base_plugin_id' => $block_plugin->getBaseId(),
                                    '#derivative_plugin_id' => $block_plugin->getDerivativeId(),
                                ];
                                $component['config']['html'] = $renderer->render($build);    
                            }
                        }
                    }
                }
            }
            $app_config = [
                'id' => $this->id(),
                'root' => $this->root,
                'app' => $app,
                'index' => $this->indexFields(),
            ];
        }
        return $app_config;
    }

    public function preSave(EntityStorageInterface $storage) {
        $app = Yaml::parse($this->config);
        foreach(self::clientRegions() as $region) {
            if(isset($app[$region])) {
                foreach($app[$region] as &$component) {
                    if($component['type'] === 'block' && isset($component['config']) && isset($component['config']['uuid']) && is_numeric($component['config']['uuid'])) {
                        $block =  BlockContent::load($component['config']['uuid']);
                        if($block) {
                            $component['config']['uuid'] = $block->uuid();
                        }
                    }
                }
            }
        }
        $this->config = Yaml::dump($app);
    }
}
