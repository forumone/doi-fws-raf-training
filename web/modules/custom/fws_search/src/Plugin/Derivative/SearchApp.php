<?php
namespace Drupal\fws_search\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

use Drupal\fws_search\Entity\SearchAppConfig;

class SearchApp extends DeriverBase implements ContainerDeriverInterface {
    
    public function __construct() {}

    /** 
     * {@inheritdoc}
     */ 
    public static function create(ContainerInterface $container, $base_plugin_id) {
        return new static();
    }

    /**
     * {@inheritdoc}
     */
    public function getDerivativeDefinitions($base_plugin_definition) {
        foreach(SearchAppConfig::loadMultiple() as $app_id => $config) {
            $this->derivatives[$app_id] = $base_plugin_definition;
            $this->derivatives[$app_id]['admin_label'] = 'FWS Search App ('.$config->label().')';
            $this->derivatives[$app_id]['config_dependencies']['config']= [$config->getConfigDependencyName()];
        }
        return $this->derivatives;
    }
}