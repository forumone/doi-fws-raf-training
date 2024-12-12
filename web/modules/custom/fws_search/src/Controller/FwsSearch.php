<?php
namespace Drupal\fws_search\Controller;

use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Controller\ControllerBase;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

use \Drupal\fws_search\Entity\SearchAppConfig;


use \Drupal\Core\Cache\CacheableMetadata;
use \Drupal\Core\Cache\CacheableJsonResponse;

class FwsSearch extends ControllerBase {
    /**
     * @var \Symfony\Component\HttpFoundation\Request
     */
    private $request;

    /**
     * @var \Drupal\fws_search\SearchAppConfigIfc
     */
    private $config;

    /**
     * @var array
     */
    private $app_config;

    /** 
     * The search index
     * 
     * @var \Drupal\search_api\IndexInterface
     */
    private $index;

    /**
     * The current query.
     * @var \Drupal\search_api\Query\QueryInterface
     */
    private $query;

    /**
     * [$top,$skip]
     * @var array
     */
    private $top_skip;

    /**
     * Number of seconds to cache results
     * @var int
     */
    private $ttl;

    /**
     * This stores the proximity setting for quoted strings and fuzziness for misspellings
     * 
     * [$proximity,$fuzzy]
     * @var array
     */
    private $proximity_fuzzy;

    /**
     * @var \Drupal\Core\Cache\CacheableMetadata
     */
    private $cache_metadata;

    /**
     * Will throw NotFoundHttpException if config not found.
     * 
     * @return \Drupal\fws_search\SearchAppConfigIfc
     */
    private function loadSearchAppConfig($machine_name) {
        if(!$this->config) {
            $this->config = SearchAppConfig::getSearchAppConfig($machine_name);
        }
        if(!$this->config) {
            throw new NotFoundHttpException();
        }
        if(!$this->app_config) {
            $this->app_config = $this->config->getAppConfig();
        }
        if(!$this->index) {
            $this->index = $this->config->getIndex();
        }

        $solr_settings = $this->config->getIndex()->getThirdPartySettings('search_api_solr');
        // Get the settings for the fuzziness and the proximity
        $fuzz = 0;
        if($solr_settings['term_modifiers']['fuzzy']){
            $fuzz = 1;
        }
        $this->proximity_fuzzy = [$solr_settings['term_modifiers']['slop'],$fuzz];
        return $this->config;
    }

    /**
     * Tests if an info is full text searchable
     * 
     * @return bool
     */
    private function isTextInfo(array $info) {
        return $info['type'] === 'text' || preg_match('/ngram/',$info['type']) === 1;
    }

    /**
     * @return array
     */
    private function textFieldInfos() {
        $fields = [];
        foreach($this->app_config['index'] as $info) {
            if($this->isTextInfo($info)) {
                $fields[] = $info;
            }
        }
        return $fields;
    } 

    /**
     * @return array
     */
    private function filterFieldInfos() {
        $fields = [];
        foreach($this->app_config['index'] as $info) {
            if(!$this->isTextInfo($info)) {
                $fields[] = $info;
            }
        }
        return $fields;
    }

    /**
     * Updates the query with any keywords on the request.
     */
    private function initKeywords() {
        $keywords = $this->request->get('$keywords');
        $this->cache_metadata->addCacheContexts(['url.query_args:$keywords']);
        if($keywords) {
            $fullTextFields = [];
            foreach($this->textFieldInfos() as $info) {
                $fullTextFields[] = $info['filter'];
            }
            if (count($fullTextFields) > 0) {
                // keywords need to be interpreted as an OR
                $parse_mode = \Drupal::service('plugin.manager.search_api.parse_mode')->createInstance('direct');
                $parse_mode->setConjunction('OR');
                $this->query->setParseMode($parse_mode);
                // split the words out making sure double quoted strings are kept as a single string
                $arr = preg_split('/("[^"]*")|\h+/', $keywords, -1, PREG_SPLIT_NO_EMPTY|PREG_SPLIT_DELIM_CAPTURE);
                foreach($arr as &$word){
                    // if the value is quoted then apply the proximity/slop value
                    if($this->proximity_fuzzy[0] != 0 && preg_match('/^(["\']).*\1$/m', $word)){
                        $word = $word . "~" . $this->proximity_fuzzy[0];
                    }else if($this->proximity_fuzzy[1] != 0 && strlen($word) < 10 && $word != "AND" 
                             && $word != "NOT" && $word != "OR"){
                        //if the value is not an operator then give it some fuzziness
                        // also words bigger than 10 characters cannot have fuzziness
                        $word = $word . "~" . $this->proximity_fuzzy[1];
                    }
                }
                $this->query->keys(implode(" ", $arr));
                $this->query->setFulltextFields($fullTextFields);
            } else {
                throw new BadRequestHttpException("Index does not support full text search");
            }
        }
    }

    /**
     * Turns on debug info if that is requested.
     */
    private function initDebug() {
        $debugQuery = $this->request->get('debugQuery');
        //$this->cache_metadata->addCacheContexts(['url.query_args:$keywords']);
        if($debugQuery) {
            $this->query->setOption("search_api_retrieved_field_values",["search_api_solr_score_debugging"]);
        }
    }

    /**
     * @return array
     */
    private function getIndexField($field) {
        foreach($this->app_config['index'] as $if) {
            if($field === $if['filter']) {
                return $if;
            }
        }
        return NULL;
    }

    private function initFilters() {
        foreach($this->filterFieldInfos() as $info) {
            $filter = $info['filter'];
            $this->cache_metadata->addCacheContexts(['url.query_args:'.$filter]);
            if($value = $this->request->get($filter)) {
                $values = json_decode($value,TRUE);
                if($info['type'] === 'rpt') {
                    if(!is_array($values)) {
                        throw new BadRequestHttpException();
                    }
                    $lat = $values['lat'] ?? NULL;
                    $lon = $values['lon'] ?? NULL;
                    $radius = $values['radius'] ?? NULL;
                    if($lat === NULL || $lon === NULL || $radius === NULL) {
                        throw new BadRequestHttpException();
                    }
                    $location_options = [];
                    $location_options[] = [
                        'field' => $filter,
                        'lat' => $lat,
                        'lon'=> $lon,
                        'radius' => $radius, // km
                    ];
                    $this->query->setOption('search_api_location',$location_options);
                } else {
                    if(is_array($values)) {
                        if(array_key_exists('from',$values) && array_key_exists('to',$values)){
                            // ranged query
                            $from = $values['from'];
                            $to = $values['to'];
                            if($info['type'] === 'date') {
                                $from = $from ? strtotime($from) : NULL;
                                $to = $to ? strtotime($to) : NULL;
                            }
                            // BETWEEN is inclusive
                            //$this->query->addCondition($filter,[$from,$to],'BETWEEN');
                            if($from !== NULL) {
                                $this->query->addCondition($filter,$from,'>=');
                            }
                            if($to !== NULL) {
                                $this->query->addCondition($filter,$to,'<=');
                            }
                        }else {
                            $this->query->addCondition($filter,$values,'IN');
                        }
                    } else {
                        $this->query->addCondition($filter,$values,'=');
                    }
                }
            }
        }
    }

    private function topSkip() {
        $this->cache_metadata->addCacheContexts(['url.query_args:$top']);
        $this->cache_metadata->addCacheContexts(['url.query_args:$skip']);
        if(!$this->top_skip) {
            $this->top_skip = [
                intval($this->request->get('$top',$this->app_config['app']['serviceDefaults']['$top'])),
                intval($this->request->get('$skip','0'))
            ];
        }
        return $this->top_skip;
    }

    private function initPaging() {
        list($top,$skip) = $this->topSkip();
        $this->query->range($skip,$top);
    }

    private function orderby() {
        return $this->request->get('$orderby',$this->app_config['app']['serviceDefaults']['$orderby']);
    }

    private function initSorting() {
        $orderby = $this->orderby();
        $this->cache_metadata->addCacheContexts(['url.query_args:$orderby']);
        $matches = [];
        if(preg_match('/^([^\s]+)(\s)?(\basc\b|\bdesc\b)?$/',$orderby,$matches)) {
            $property = $matches[1];
            $direction = strtoupper(count($matches) === 4 ? $matches[3] : 'asc');
        } else {
            throw new BadRequestHttpException('Invalid $orderby "'.$orderby.'"');
        }
        $this->query->sort($property, $direction);
    }

    private function initFacets() {
        if($this->index->getServerInstance()->supportsFeature('search_api_facets')) {
            $facets = [];
            $app_config = $this->app_config;
            $apps = [$app_config['app']];
            // discover if there are routeContextual apps and if so append them to the $apps array
            // to get the union of all possible filters
            if(isset($app_config['app']['routeContextualApps']) && isset($app_config['app']['routeContextualApps']['apps'])) {
                foreach($app_config['app']['routeContextualApps']['apps'] as $app) {
                    if(isset($app['app'])) {
                        $apps[] = $app['app'];
                    }
                }
            }
            // pull filters out of client region config
            foreach(SearchAppConfig::clientRegions() as $region) {
                foreach($apps as $app) {
                    if(isset($app[$region])) { // if components are configured for the region
                            foreach($app[$region] as $component) {
                                // if the component is a filter
                                // AND has a corresponding field in the index (i.e. not an ancillary filter like $orderby)
                                // AND its type we want facets for (at the moment everything but date)
                                if(isset($component['filter']) && ($index_field = $this->getIndexField($component['filter']))) { //} && $index_field['type'] !== 'date') { 
                                    $field = $component['filter'];
                                    $facets[$field] = [
                                        'field' => $field,
                                        'min_count' => 1,
                                        'missing' => FALSE,
                                        'limit' => -1,
                                    ];
                                }
                            }
                    }
                }
            }
            if(count($facets) > 0) {
                $this->query->setOption('search_api_facets',$facets);
            }
        }
    }

    private function unescapeFacet(&$facet) {
        $facet['filter'] = trim($facet['filter'],'"');
        return $facet;
    }

    private function unescapeFacets($facets) {
        $return = [];
        foreach($facets as $key => $facet) {
            $list = [];
            foreach($facets[$key] as $facet) {
                $list[] = $this->unescapeFacet($facet);
            }
            $return[$key] = $list;
        }
        return $return;
    }

    /**
     * /fws_search/{app_machine_name}
     */
    function search(Request $request,string $app_machine_name) {
        $this->ttl = 30*60; //default cache to 30 minutes (in seconds)
        $this->request = $request;
        if($this->request->get('debugQuery')) {
            $this->ttl = 1;
        };
        $this->loadSearchAppConfig($app_machine_name);
        

        $this->cache_metadata = new CacheableMetadata();
        $cache_tag = 'fws_search:'.$app_machine_name;
        $this->cache_metadata->addCacheTags([$cache_tag]);
        // Default to 30 minutes (in seconds) otherwise get it from the config
        if(isset($this->app_config['app']['appConfig']) && isset($this->app_config['app']['appConfig']['cacheSeconds'])){
            $this->ttl = intval($this->app_config['app']['appConfig']['cacheSeconds']);
        }
        
        $this->cache_metadata->setCacheMaxAge($this->ttl);

        $logger = \Drupal::logger('fws_search');
        $state = \Drupal::state();
        $current_state = $state->get($cache_tag);
        if(!$current_state) {
            $expiry_time = (time()+$this->ttl);
            $state->set($cache_tag,$expiry_time);
            $expiry = date('d M Y H:i:s', $expiry_time);
            $logger->info("Set purge expiry for ${cache_tag} to '${expiry}'");
        }/* else {
            $expiry = date('d M Y H:i:s', $current_state);
            $logger->info("Purge expiry for ${cache_tag} already set to '${expiry}'");
        }*/

        $this->query = $query = $this->index->query();

        $this->initDebug();
        $this->initKeywords();
        $this->initFilters();
        $this->initPaging();
        $this->initSorting();
        $this->initFacets();
        
        // tags?
        $resultSet = $query->execute();
        $total = $resultSet->getResultCount();
        $list = [];
        if($total > 0) {
            // TODO there's a "load multiple" route that may be more efficient?
            // another alternative would be to actually put the rendered view in
            // solr and pull it from the search results instead...
            $renderer = \Drupal::service('renderer');
            foreach ($resultSet->getResultItems() as $item_id => $item) {
                if ($this->request->get('printView')) {
                    $view_mode = $this->app_config['app']['serviceDefaults']['print_view_mode'];
                } else {
                    $view_mode = $this->app_config['app']['serviceDefaults']['view_mode'];
                }
                $build = $item->getDatasource()->viewItem($item->getOriginalObject(),$view_mode);
                $list[] = $renderer->renderPlain($build);
            }
        }
        list($top,$skip) = $this->topSkip();
        $_meta = [
            'total' => $total,
            'facets' => $this->unescapeFacets($resultSet->getExtraData('search_api_facets',[])),
            'input' => [
                '$top' => $top,
                '$skip' => $skip,
                '$orderby' => $this->orderby(),
            ]
        ];

        // If debug is on return return details
        if($this->request->get('debugQuery')){
            $_meta += ['solr_response' => $resultSet->getExtraData('search_api_solr_response',[])];
        }

        $response = new CacheableJsonResponse([
            'list' => $list,
            '_meta' => $_meta
        ]);
        $response->addCacheableDependency($this->cache_metadata);
        return $response;
    }

    /**
     * /fws_search/{app_machine_name}/info
     */
    function info(string $app_machine_name) {
        return new JsonResponse($this->loadSearchAppConfig($app_machine_name)->getAppConfig());
    }


    // function test() {
    //     $pids = [9906,9907,9908,9909];
    //     $processor = \Drupal\fws_search\Plugin\search_api\processor\ParagraphsInUse::create(\Drupal::getContainer(),[],'fws_search_paragraphs_in_use',[]);
    //     $results = $processor->testParagraphIdsForReferences(['revision_problem'],$pids);
    //     dd($results);
    // }
}