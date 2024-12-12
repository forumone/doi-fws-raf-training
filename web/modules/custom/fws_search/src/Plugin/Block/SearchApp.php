<?php
namespace Drupal\fws_search\Plugin\Block;

use Drupal\Core\Block\BlockBase;

// use Drupal\Core\Cache\UncacheableDependencyTrait;

use \Drupal\fws_search\Entity\SearchAppConfig;
use \Drupal\Core\Entity\FieldableEntityInterface;
/**
 * @Block(
 *   id = "fws_search_app",
 *   admin_label = @Translation("FWS Search App block"),
 *   deriver = "Drupal\fws_search\Plugin\Derivative\SearchApp",
 * )
 */
class SearchApp extends BlockBase {
    /* dev time, uncomment no caching
    use UncacheableDependencyTrait;
    */

    public function build() {
        /* dev time, uncomment no caching
        \Drupal::service('page_cache_kill_switch')->trigger();
        */
        $app_machine_name = $this->getDerivativeId();
        $app_config = SearchAppConfig::getSearchAppConfig($app_machine_name);
        $json_config = $app_config->getAppConfig(TRUE);
        // if the app uses contextual filters then build the filter values based on route parameters
        if(isset($json_config['app']['routeContextualFilter']) && is_array($json_config['app']['routeContextualFilter'])) {
            foreach ($json_config['app']['routeContextualFilter'] as $filter => $input) {
                if(is_array($input)) {
                    if(isset($input['routeParam'])) {
                        $param_value =  \Drupal::routeMatch()->getParameter($input['routeParam']);
                        if(isset($input['property'])) {
                            $entity = $param_value;
                            if($entity instanceof FieldableEntityInterface) {
                                if($input['property'] === 'id') {
                                    $json_config['app']['contextualFilters'][$filter] = [$entity->id()];
                                } else {
                                    try {
                                        $prop = $entity->get($input['property']);
                                        $count = $prop->count();
                                        $values = [];
                                        for($i = 0; $i < $count; $i++) {
                                            $values[] = $prop->get($i)->value;
                                        }
                                        $json_config['app']['contextualFilters'][$filter] = $values;
                                    } catch(\Exception $e) {
                                    }
                                }
                            }
                        } else {
                            // just pass along the route parameter value
                            $json_config['app']['contextualFilters'][$filter] = [$param_value];
                        }
                    } else if (isset($input['queryParam'])) {
                        $query_arg = $_GET[$input['queryParam']] ?? NULL;
                        if($query_arg !== NULL) {
                            $json_config['app']['contextualFilters'][$filter] = [$query_arg];
                        }
                    }
                }
            }
        }
        if(isset($json_config['app']['routeContextualApps']) && is_array($json_config['app']['routeContextualApps'])) {
            $key = $json_config['app']['routeContextualApps']['key'] ?? [];
            $routeParam = $key['routeParam'] ?? NULL;
            $property = $key['property'] ?? NULL;
            if($routeParam !== NULL){
                $value =  \Drupal::routeMatch()->getParameter($routeParam);
                if($property !== NULL) {
                    if($value instanceof FieldableEntityInterface) {
                        if($property === 'id') {
                            $value = $entity->id();
                        } else {
                            try {
                                $value = $value->get($property)->value;
                            } catch(\Exception $e) {
                                $value = NULL;
                            }
                        }
                    } else {
                        $value = NULL;
                    }
                }
                $apps = $json_config['app']['routeContextualApps']['apps'] ?? [];
                foreach($apps as $app) {
                    $match = $app['value'] ?? NULL;
                    if($match == $value) {
                        $app = $app['app'] ?? [];
                        foreach($app as $top_key => $override) {
                            $json_config['app'][$top_key] = $override;
                        }
                        break;
                    }
                }
                
            }
        }
        return [
            '#theme' => 'search_app',
            '#attached' => [
                'library' => ['fws_search/fws_search.app'],
            ],
            '#app_config' => json_encode($json_config),
        ];
    }
}