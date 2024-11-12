<?php
namespace Drupal\fws_search;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * Represents configuration for a given search app.
 */
interface SearchAppConfigIfc extends ConfigEntityInterface {
    /**
     * A configuration array for a single search app that merges SearchAppConfig
     * and information from the underlying index.
     * 
     * Will have top level keys:
     * - id The id of the search application.
     * - root The app root.
     * - app Opaque configuration for a given search app.
     * - index The index definition and all fields/filters it contains.
     * 
     * @param bootstrap {boolean} Whether the resulting config should be processed for bootstraping the client.
     * @return \Drupal\fws_search\SearchAppConfigIfc
     */
    public function getAppConfig($bootstrap = FALSE);
    /**
     * @return \Drupal\fws_search\SearchAppConfigIfc[]
     */
    public static function getAllSearchAppConfigs();
    /**
     * @return \Drupal\search_api\IndexInterface
     */
    public function getIndex();
}