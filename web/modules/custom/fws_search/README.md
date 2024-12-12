# FWS Search

## Overview

Research was done to see if advanced search components within fws.gov could be achieved in a maintainable fashion using out of the box Drupal modules (`search_api`, `search_api_solr`, `views`, `facets`).  While a great deal of the functionality could be realized the end result wasn't complete.  For more advanced search applications a more advanced approach is being taken that uses a JavaScript ([Angular](https://angular.io/)) front-end with a supporting RESTful web service.

## Architectural Overview

### Search Indexes

Each advanced search application will be sourced by a search index configured via the Search API (`/admin/config/search/search-api`).  [Apache Solr](https://lucene.apache.org/solr/) along with the `search_api_solr` module will serve as the search index engine though technically any engine that supports faceted search and has a `search_api` module implementation should be able to be used.

### SearchAppConfig

A new Drupal config entity is supplied as part of this module (`src/Entity/SearchAppConfig`).  The entity can be managed from the Drupal UI via the "Configuration &gt; Search and metadata &gt; FWS Search apps" (`/admin/config/search/fws-search-app-config`).

Each instance of the configuration represents a single configured search application and will result in a Drupal block that can be placed to display the configured search application (`src/Plugin/Block/SearchApp.php` and `src/Plugin/Derivative/SearchApp.php`).  The contents of each configuration entity serves as the glue between a given search index and its corresponding application view.  A `SearchAppConfig` entity instance is made up of the following information:

- `Label` - Results in a `machine_name` that is the identifier for the search application.
- `Index` - The search index that drives the search application.
- `Root` - Defines the base application.
- `SearchApp Config (YAML)` - YAML configuration shared by the search application and its custom REST endpoint that services it.

#### `Root`

The `Root` configuration property is used by the application to choose special application views as necessary.  There will be a base "default" application view that is highly configurable but in some cases a search application may have very specialized needs (e.g. an interactive map component, etc.) and so will have custom functionality compiled specifically for it.

#### `SearchApp Config (YAML)`

This configuration property contains `YAML` that is used by a search application and its supporting REST endpoint.  For example the REST endpoint uses the `service_defaults` property to define its application's page size and default sorting behavior.  The search application makes use of regions like `top`, `left`, `bottom` and `menu` to define what filters/facets and components to expose, where on the page to display them, what order to display them, what type of control to associate with each (e.g. select, region, tagging auto-complete, etc.).  There is also an `appConfig` section for any high level config settings.

_Note:_ At the moment this configuration is being defined/evolving as the requirements of more search applications are realized.

**`appConfig`**

This section is used for any high level config settings. It is a map of key value pairs. The following options are available. 

- `showShare` - if set to `true` the share search button will be shown. When a user clicks the button it will copy the url to the clipboard.
- `showViews` - This will show the toggle for the searches view classes. Set to `true` if you would like to show them, then you can use `alternateClassName`, `defaultClassName`, `alternateViewIcon`, `defaultViewIcon`
- `alternateClassName` - This is the name of the class to use when the user clicks the layout button for the alternate search results view.
- `alternateViewIcon` - This is the angular material icon to use on the button for the alternate layout.
- `alternateViewText` - Text to show in the title and aria for accessibility.
- `defaultClassName` - This is the default class to use when the default layout for the search is selected.
- `defaultViewIcon` - This is the angular material icon to use on the button for the default layout. 
- `DefaultViewText` - Text to show in the title and aria for accessibility.
- `cacheSeconds` - The number of seconds to cache results for.  By default this is 30 minutes (`1800`).  Set this to `1` second if you don't want any caching.
- `leftTitleText` - Remove or set the title that is shown above the `left` region's filter controls. This is also used as the header text for the accordion drop down.
- `removeOnZeroResults` - Boolean or Array: Set to `true` if you want it to remove the search when there are no results on the initial load. You can also set it to an array of css selectors to remove one or more DOM elements when there are no results on load. For example, ['#myidtoremove','.myclasstoremove']. NOTE: the way this works is to apply a hidden class to any elements you are selecting.  If you have a header in a block outside the search, you will want to apply the "Hidden" class to that block and then add a selector for it in your array. The header will then be hidden by default and the app will remove the hidden class from it if there are any results.
- `doNotShowResults` - Defaults to true, but if set to false it will not show any search results.  Used mostly for pages where you only want to show the map.
- `defaultFilter` - This acts like setting the filter parameters in the url to give you control of what the search shows when a user first opens the map.  This is mainly used with displaying a map on a page.  You can set the the `defaultFilter` to only show Fish Hatcheries and by default the map will not show other facilities.  It uses a `key` and `value` pair in an array.  The `key` is the name of the field to filter and the `value` is what you want it to default the search to.  Note that most values are going need to be arrays. See the example below. You can set the default start date or end date using `'$DATE:date'` where "date" can be any of the following (see example for clarification):
      - A date in the format `MM/DD/YYYY`
      - `today` - which will display the todays date.
      - A time in the future or past like `+1 months`.  Notice the plural, they must all be plural.  You can do a past dates like `-2 weeks`.  The available options are:
        - `days`, `weeks`, `months`, `years`
- `doNotReparentFiltersOnMobile` - defaults to false.  Currently used on pages like Visit Us where a map is shown with some filters and you don't want to reparent all the filters on mobile since they still look good on mobile without reparenting them.

Here is an example:

```
appConfig:
    leftTitleText: 'Refine Your Search'
    removeOnZeroResults: ['#menu', .field--name-description]
    defaultFilter: [{ key: type, value: ['National Fish Hatchery'] },{ key: event_date_and_time, value: [{ from: '$DATE:today', to: null }] }]
    emitInitialEmptyQuery: true
    cacheSeconds: 1
```

**`serviceDefaults`**

The `serviceDefaults` top-level key is used to configure default parameters for an application's REST endpoint.  Default values are shown below.

```
serviceDefaults:
  # The default page size for the application
  $top: 5 
  # The default sort behavior for the application
  $orderby: search_api_relevance desc
  # The default view mode to use when rendering search results
  view_mode: search_result
  # The default view mode to use when rendering search results for printing
  print_view_mode: print
```

**`routeContextualApps`**

If your search application block is placed on a path that has contextual input, for instance a taxonomy term view page or all view pages for a specific content-type, then you can use contextual information about the entity to decide if filters should be removed or added to the page.

To do this create an app as though you are creating it for just a standard search.  Then based on a contextual field if it has a certain value you can replace the different areas of the search and their filters, like `left` or `top`.

Here's an example with a description below:

```
routeContextualApps:
    key: { routeParam: taxonomy_term, property: name }
    apps: [{ value: 'Ecological Risk Screening', app: { left: [{ filter: combined_type, type: selectMultiple }, { filter: top_level, type: checkboxes }] } }]
```

`key` is used to tell it which property you want to key off to decide to make changes to the app.  Next `apps` is an array of objects that tell the app when to make the substitutions and what substitutions to make.  For example, if the taxonomy term's name equals 'Ecological Risk Screening' (`value`), then we want to use `app` to replace the `left` section with the 2 filters listed.  

One thing to note is that in the example above it completely replaces the `left` sections filters but doesn't touch any of the other sections.


**`routeContextFilter`**

If your search application block is placed on a path that has contextual input, for instance a taxonomy term view page or all view pages for a specific content-type, then you can use contextual information about the entity where the search app has been placed to seed search criteria for it.

E.g. Fill in a contextual filter from an entity property on a route (for `property` to be used the value of `routeParam` must be an instance of `Drupal\Core\Entity\FieldableEntityInterface`).
```
routeContextualFilter:
    titleFilter: { routeParam: node, property: title }
```

Would seed the `titleFilter` filter with the `title` property of the entity found via the `node` route parameter.

Or if placed on a taxonomy term's view page:

```
routeContextualFilter:
  myTerm: { routeParam: taxonomy_term, property: name }
```

Would seed the search filter `myTerm` with the `name` of the taxonomy term.

The value for `property` must be either `id` or an actual property on the entity.  If the property is not found on the entity in question it will be silently ignored and the filter criteria will not be applied by the search app.

E.g. Fill in a contextual filter from a non-entity property on a route.
```
routeContextualFilter:
    foo: { routeParam: bar }
```

Would seed the `foo` filter with the value of `bar` from the route as is.

E.g. Fill in a contextual filter from a query parameter (_Note:_ if the query arg is not set the filter value supplied to the search app will be null and so won't be supplied to the initial query).
```
routeContextualFilter:
    foo: { queryParam: bar }
```

Woud seed the `foo` filter with the value of the `bar` query parameter (E.g. `?foo=abc`).

**IMPORTANT:** You cannot place a filter in the refiners for any filters provided contextually or the contextual filter will be over-ridden by the behavior of the control, nullifying the contextual filter.

**`Regions`**

Search pages are made up of 4 potential regions, `header`, `top`, `menu`, `left`, and `bottom`.  The regions are placed at specific places on the page as described here:

- `left` - Left sidebar of the screen.  In mobile it will be at the top of the page, but will be in an accordion.  If no `left` or `menu` then no left side bar will be present.
- `menu` - Left sidebar of the screen.  However, it will not be in an accordion.
- `header` - An area above Top that can be used to display header text or other components that you don't want as part of Top since Top is using flex.
- `top` - Top of the content side of the screen, this section is using flex when displayed on most search pages.
- `hidden` - this is a section that won't be shown by default, but if the user clicks the show filters button it will show up.  If you use this section then the "Refine your search" will not be shown on mobile, but instead this will be shown.
- `bottom` - Bottom of the content side of the screen.

**`Filters and Components`**

The filters and components (listed in the following sections), follow this general format

```
  - { filter: <filter_machine_name>, type: <control_type>, config: { <config_settings> } }
```

Values for `<filter_machine_name>` must align with the `MACHINE NAME` for a field within the search index.  If they do not match then the application will log an error to the JavaScript console and ignore the configuration item.

Values for `<control_type>` are listed in the following two sections of this document below (note: this list is being defined and may change).

Values for `<config_settings>` are dependant on the control and give flexibility to pass in optional configs.


**`Control Types: Components`**

Search components are features you can put into your search page that are not filters.  They include the following:

- `block` - Blocks allow you to place Drupal content onto a page. For the config it takes one required parameter `uuid` which can be either the uuid of the drupal content or simply it's id. The example below has just the content's id.  After you save the search config it will convert 22 to the uuid for the content if it exists.  

    Optionally you can supply `label` if you want to display a block title and `view_mode` if you want to use a `view_mode` other than `default` (_Note:_ The acceptable values for `view_mode` are dictated by the type of custom block being placed and so can't be documented here but can be found via the "Manage display" tab of your block's type)
    
  - example: ` { type: block, config: { uuid: 22, label: 'Block heading', view_mode: default } }`

- `label` - A label that can be used for checkbox/boolean groups or any other labeling needs. Has two config settings, `label` which is the text you want to display to the user and `class` which can be used to add classes to the lable.  In particular classes h1-h6 mimic the FWS stylings of the h tags.
  - example: `{ type: label, config: { label: 'Testing Label', class: 'h4' } }`

- `print` - A button to print the full set of results (unpaginated) to be rendered as a list of items in the view mode set by the `print_view_mode` service default.
  - usage: `{ type: print }`

**`Control Types: Filters`**

Filter components allow you to give the user the ability to filter a certain datafield with a given control type listed below.  They include some special case filters that begin with `$` and some normal filters without the `$`.

All controls can use a `config` setting `paramName` if you would like the change the name of the param as it is passed in the url.  For example for a `$keywords` filter you can use `config : { paramName: keys }` and then in the url pass the keywords as `?keys=North Mississippi`.

- `quickFilter` - This requires `type` which is the name of the filter that will be used to actually perform the filter.  Currently, you must also add a filter record for this.  The `config` field must also be set, as well as the `doNotFilter` field to be set to `true`. The `config` allows you to decide what chips to show for the quickfilter.  Each chip requires a `label` which is what is shown to the user, a `filter` which is an array with the filters that will be used if this is selected, and `selected` which most likely should be set to false by default.  The `filter` can filter by more than one value. You can create a filter called `All` which is a special filter that will reset the entire filter when selected. See the example below (this example would also need another filter for `type` to be set on this search as well in order to work).
  - example: `{ filter: type, doNotFilter: true, type: quickFilter, config: [{ label: All, filter: [ALL], selected: false }, { label: Species, filter: [Species], selected: false }, { label: People, filter: ['Staff Profile'], selected: false }, { label: Places, filter: [Facility], selected: false }, { label: Programs, filter: [Program], selected: false }, { label: Services, filter: [Service], selected: false }, { label: Documents, filter: [Document], selected: false }, { label: News, filter: ['Press Release', Story], selected: false }] }`
- `keywords` - (filter is $keywords) Allows the user to search fulltext fields that are indexed by typing words. Has one config setting `label` which is the textbox label for the control (act's like the placeholder).
  - example: `{ filter: $keywords, type: keywords, config: { label: 'Search by Facility Name' } }`
- `orderby` - (filter is $orderby) Gives you the ability to sort on provided fields.
  - Has 3 config settings:
    - `label` - the text to display in the box before sorting takes place
    - `direction` - the default direction for the sort, either `asc` or `desc`
    - `sorts` - an array of the `<control_names>` to allow the user to sort. Note: each of these must be fields indexed for the search.
  - example: `{ filter: $orderby, type: orderby, config: { label: 'Sort by', direction: asc, sorts: [title, type] } }`
- `pager` - (filter is $skip) Paging capabilities for the page. Has one config, `numberOfPageLinks` which is the number of page links to allow the user to click on.
  - example: `{ filter: $skip, type: pager, config: { numberOfPageLinks: 10 } }` 
- `azList` - A to Z list for a certain field.  If no results for a letter then the letter is disabled. No config. To use this type, you need to index the field you want to be controlling the A to Z list, such as "title" or "field_last_name". Save that, then go back to add another field to the index, and you will see a field speciifc to alphabetical searching such as "fws_search_alphabet__field_last_name" and you need to add this as a field. Then the value in "Filter" (below) needs to match the machine name in the index.
  - example: `{ filter: az_title, type: azList }`
- `checkboxes` - [Not currently working. Changes to the way the app works made it so when you select one checkbox others will dissapear since it's an AND instead of OR filter] Shows a list of checkboxes that the user can select to filter by. Allows setting label in config. Also allows using with a mime/type, which can also show an icon.   `ContentTypeIcon` is optional when `ContentType` is set. By Default `ContentType` is set to just `text`, but it can also be `mime` which will then display the mime type in more familiar text. For example application/PDF would be displayed as just PDF. You can also dictate the order of the options using `options` in the config.  It doesn't use the `options` field to populate the select, but does order them in the same way.  Item's not in your list will be shown first by default.
  - example: `{ filter: species, type: checkboxes, config: { label: 'Species', contentType: 'mime', contentTypeIcon: true } }`
- `selectMultiple` - A select dropdown box that allows multiple selections. Allows setting label in config. Also allows using with a mime/type, as seen in checkboxes above. You can also dictate the order of the options using `options` in the config.  It doesn't use the `options` field to populate the select, but does order them in the same way.  Item's not in your list will be shown first by default.
  - example: `{ filter: species, type: selectMultiple , config: { label: 'Species Type', options: ['Pre-K','Elementary School','Middle School'] } }`
- `selectHierarchy` - Similar to `select`, except it shows a tree, so it REQUIRES the field to be a hierarchical taxonomy AND that you have applied the `FWS Search Taxonomy Hierarchy` processor to the field.  It has two options which are required, `showLevel` and `taxonomy`.  `showLevel` can be 0 if you want it to show the entire tree in the select options.  If you want to skip the first level, for example, you can set this to 1 and it will not show the first level of the tree. `taxonomoy` can be set to null, but you can set this to a specific taxonomy name and if it isn't present in the tree of a given option, then it won't show that option.
  - example: `{ filter: document_type, type: selectHierarchy, config: { label: 'Document Type', showLevel: 2, taxonomy: 'Ecological Risk Screening' } }`
- `select` - A select dropdown box that allows only one selection. Allows setting label in config. Also allows using with a mime/type, as seen in checkboxes above. You can also dictate the order of the options using `options` in the config.  It doesn't use the `options` field to populate the select, but does order them in the same way.  Item's not in your list will be shown first by default. 
  - example: `{ filter: species, type: selectMultiple }`
- `chips` - [Not currently working due to changes in the way the app works, after you select one item all the other choices dissappear from the autocomplete] Select items from a list by typing and selecting.  For large lists of items, similar to tags. Allows `label` in the config for setting the placeholder.
  - example: `{ filter: title, type: chips, config: { label: 'Search by Facility Name' } }`
- `typeAhead` - A single selection type-ahead filter. Allows `label` in the config for setting the placeholder.
  - example: `{ filter: state_name, type: typeAhead, config: { label: 'Search by State' } }`
- `boolean` - A single checkbox value.  If you have multiple single checkboxes together you will also need to us a label control. No config.
  - example: `{ filter: featured, type: boolean }`
- `menu` - a single select that looks like a menu. Note:  it must be placed in the `menu` region. 
  - Has two config settings:
    - `title` - which is what is displayed as the header text for the menu.
    - `subTitle` - a gray sub title underneath the big title.
  - example: `{ filter: type, type: menu, config: { title: 'Facility Type' } }`
- `range` - date range picker
  - Has 7 config setting.
    - `label` - text label for control above the input fields.
    - `startLabel` - label for the start input, acts like a placeholder.
    - `endLabel` - label for the end input, acts like a placeholder.
    - `min`, `max` - Min and max set the min and max the user will be able to see on the control.
  - example: `{ filter: changed, type: range, config: { label: 'Filter by Last Updated', startLabel: 'Start Date', endLabel: 'End Date', min: 01/20/2019, max: 12/01/2020 } }`
- `map` - A map display for choosing a facility by its visible location
  - Has 6 config settings:
    - `zoom` - an number value between 1 - 18 to specify how zoomed in the map should be. The map's zoom level increases exponentially (by powers of 2) with the value of the zoom level - see [examples](https://leafletjs.com/examples/zoom-levels/).
    - `centerLat` - the initial latitude that the map is centered on. To use this, there also must be a `centerLng` provided.
    - `centerLng` - the initial longitude that the map is centered on. To use this, there also must be a `centerLat` provided.
    - `banner` - a boolean value that determines whether the map is displayed in the banner of the page or not. Its default value is `false`.
    - `centerOnMobile` - if set to true then when map is loaded in mobile the map will center to the default center (which is currently the center of the US)
    - `minZoom` - the highest zoomed out allowed, defaults to 3.
    - `maxLongitudeDistance` - If the bound around the markers has a difference in longitude greater than this number then on the initial load it will use the default zoom level and centers. Current default is 201 (degrees).
- `zipcode` - a text input for searching for zip codes, along with a number input for miles from a given zip code
  - Uses a third-part ArcGIS endpoint for getting the lat/long from a given zip code string. Find out more about the [zip code feature server here](https://services.arcgis.com/P3ePLMYs2RVChkJx/arcgis/rest/services/USA_ZIP_Code_Points_analysis/FeatureServer).
  - The only config option for this filter is the `radius` option. This value is the distance (in miles) to search from the point returned from the zip code feature server. If no default is provided then it defaults to 50 miles.
- `submit` - a submit button that will send the current filters to a search page
  - Has the following config settings:
    - `path` - the url path to submit the filters
    - `title` - the submit button title
### REST endpoint

A Drupal route connected to `/fws_search/{app_machine_name}` serves up search results for each application (`{app_machine_name}` is the `machine_name` for the corresponding `SearchAppConfig` entity).  The service will return an HTTP 404 Not Found if given a supplied `{app_machine_name}` that does not exist.

Hitting the endpoint without any parameters will execute an open ended search against the corresponding index using the configured default values.  The supported query parameters are (most names based on `odata` services):

- `$top` Specifes the page size to use.  The page size as configured in the `SearchAppConfig` will be used (default of `5` if not supplied in configuration).
- `$skip` The number of records to skip facilitating movement through pages.
- `$orderby` The name of a given `filter` to sort the results by and the sort order (default of `search_api_relevance desc` if not supplied in configuration).  The format of the parameter is `<filter>[ asc|desc]`, if not supplied the default order is `asc`.
- `$keywords` A list of words when performing full text search (if an index supports it).
- `printView` A flag to use when fetching results to be rendered in the print view mode.

The endpoint responds with a JSON object structured as follows:

- `list` Array of string search results.  Each string is the rendered markup of the `search_result` view mode for the corresponding entity (the application is not responsible for dealing with rendering views, that remains strictly the responsibility of Drupal).
- `_meta` An object containing information about the request/response.
  - `total` The total number of objects in the entire search result.
  - `facets` An object containing facet data for the current response.  The object keys are the `filter` name and the values are an array of objects with keys `filter` and `count` where `filter` is the name of the filter property and `count` is the number of results in the total result set that match the given `filter` value.
  - `input` An object containing the input parameters (or defaults if parameters were not supplied).  The keys correspond to the parameters above (`$top`,`$orderby`, etc.).

The search application is simple minded and drives itself entirely from its configuration and responses from its REST endpoint.  I.e. filter controls populate themselves based on the contents of `_meta.facets`, paging controls generate themselves based on the combination of `_meta.total`, `_meta.input.$top` and `_meta.input.$skip`, etc.

## Reverse entity references

Drupal's support for indexing fields of entities that reference the entity being indexed is lacking.  Searches on the topic result in threads suggesting custom code to fill the need.  The "Processors" tab of a search index does expose a processor titled "Reverse entity references".  This processor does not appear to be well documented and when configured results in entities failing to be indexed due to it assigning cardinality based upon the field being stored in the index (not the fact that all fields off of such referencing entities, by nature, must be multi-valued).

_Note:_ The [SearchApiSolrBackend is setting incorrect prefix to Search API reverse entity references](https://www.drupal.org/project/search_api_solr/issues/3050475) bug might imply that this bug _should_ be fixed but does not appear to be in practice.

As a result, at the moment, a custom search api processor has been implemented (`src/Plugin/search_api/processor/Backreferences.php`) to fill this need, each reference that it will support must be configured via the "Configuration > Search and metadata > FWS Search Settings" module configuration in the "Backreferences config" field.

Some example config YAML would be:

```
node:
  facility:
    amenities:
      label: "Facility amenities (title)"
      type: node
      bundle: amenity
      reference_field: field_facility_reference
      property: title
      property_type: string
    activities:
      label: "Facility activities (title)"
      type: node
      bundle: activity
      reference_field: field_facility_reference
      property: title
      property_type: string
```

So in the above example, for the `amenitites` key, when indexing instances of `node:facility` allow indexing of `node:amenity.title` where `node:amenity.field_facility_reference` points to the `node:facility` entity being indexed.


The format of the YAML is:

```
<entity_type>: # entity type being indexed
  <entity_bundle>: # entity bundle being indexed
    <reference_key>: # arbitrary unique key
       label: <label>
       type: <otherside_entity_type>
       bundle: <otherside_entity_bundle>
       reference_field: <field name referencing entity_type:entity_bundle>
       property: <property_to_index>
       property_type: <property_type>
```

The YAML configuration does not itself cause anything to be indexed but makes it possible when building an index for such references will be available to be configured when `<entity_type>:<entity_bundle>` is being indexed.

## Alphabet index fields

In support of an alphabet A-Z filter control (filter based on field starting with a given letter) a custom search api processor has been written to fill this need (`src/Plugin/search_api/processor/AlphabetProcessor.php`).  The only known use case would be when searching an index of people (`user:user`) be able to filter by last name starting with a letter.

The processor is limited in its functionality but broader than the known use case.  It will allow indexing of the first letter (capitalized) of any string property/field that resides directly on the entity being indexed.  In the case of user (which holds name parts in a complex field) it allows for indexing of the `family` and `given` name portions of the complex field of type `name`.

The REST webservice will implicitly return facet information properties indexed with this search api processor.

### Alternate approach

Drupal's search api query does not support filters like "starts with" (all string based filtering is strict equality) so as a result a normal string index cannot be used for wildcard querying.  Even if that were the case faceting would likely produce too much data to be useful (the control's functionality would be limited).

It would be possible to use a `Fulltext "edgestring"` indexed property for this purpose _but_ that approach has two distinct downsides:

1) facets cannot be returned for text indexed fields (the control's functionality would be limited).
2) The solr configuration generated by Drupal requires a minimum ngram size of 2 meaning the solr configuration would have to be hand edited to support the desired functionality.  This also means if the solr configuration ever needed to be re-generated the edit would have to be re-applied manually or fear breaking the functionality.

Due to those detractors it felt like a more maintainable and robust approach was to simply support indexing the first character of a given property.

# Explaining Search Results

If you see search results and they don't seem to make sense you can ask for solr to explain itself. If you add `&explain=true` to a search url the page will return explanations of why content is ranked the way it is.  At a high level this shows the content node’s Drupal ID, its overall score, and then the overall score summed up from scores for each field where a search term was present and the calculations that went into that calculation.  Each search result is numbered (starting with 1), since the title is not available. #1 is the first search result that appears on that page, and #2 is the second and so forth.   It might be most helpful to open 2 windows with the same search results (one with `&explain=true` and one without it) and then you can line up the first explanation with the first search result and so forth.

Here is an example of the 2nd search result from a search on “hunting.”  The bracketed bold numbers have explanations at the bottom of the post:

2. 4tjly8-everything-entity:node/3407:en - 29.333134 [1]
    1 is a result of the boost [2]
    29.333134 is a result of the product of: [3]
      29.333134 is a result of the sum of: [4]
        5.268477 is a result of the weight(tm_X3b_en_field_abstract:hunt in 75381) [SchemaSimilarity], result of: [5]
          5.268477 is a result of the score(doc=75381,freq=1.0 = termFreq=1.0 ), product of: [6]
            3.9854505 is a result of the idf, computed as log(1 + (docCount - docFreq + 0.5) / (docFreq + 0.5)) from: [7]
              10 is a result of the docFreq [8]
              564 is a result of the docCount [9]
            1.3219275 is a result of the tfNorm, computed as (freq * (k1 + 1)) / (freq + k1 * (1 - b + b * fieldLength / avgFieldLength)) from: [10]
              1 is a result of the termFreq=1.0 [11]
              1.2 is a result of the parameter k1 [12]
              0.75 is a result of the parameter b [13]
              24.709219 is a result of the avgFieldLength [14]
              10 is a result of the fieldLength [15]
        1.4782699 is a result of the weight(tm_X3b_en_keys:hunt in 75381) [SchemaSimilarity], result of: [16]
          1.4782699 is a result of the score(doc=75381,freq=4.0 = termFreq=4.0 ), product of:
            0.3 is a result of the boost
            2.625944 is a result of the idf, computed as log(1 + (docCount - docFreq + 0.5) / (docFreq + 0.5)) from:
              16031 is a result of the docFreq
              221516 is a result of the docCount
            1.8764933 is a result of the tfNorm, computed as (freq * (k1 + 1)) / (freq + k1 * (1 - b + b * fieldLength / avgFieldLength)) from:
              4 is a result of the termFreq=4.0
              1.2 is a result of the parameter k1
              0.75 is a result of the parameter b
              868.5865 is a result of the avgFieldLength
              376 is a result of the fieldLength
        22.586388 is a result of the weight(tcngramm_X3b_en_title:hunting in 75381) [SchemaSimilarity], result of: [17]
          22.586388 is a result of the score(doc=75381,freq=1.0 = termFreq=1.0 ), product of:
            3 is a result of the boost
            4.5463343 is a result of the idf, computed as log(1 + (docCount - docFreq + 0.5) / (docFreq + 0.5)) from:
              2991 is a result of the docFreq
              282056 is a result of the docCount
            1.6560146 is a result of the tfNorm, computed as (freq * (k1 + 1)) / (freq + k1 * (1 - b + b * fieldLength / avgFieldLength)) from:
              1 is a result of the termFreq=1.0
              1.2 is a result of the parameter k1
              0.75 is a result of the parameter b
              126.35715 is a result of the avgFieldLength
              4 is a result of the fieldLength
      1 is a result of the float(boost_document)=1.0 [18]

[1] This is the explanation of the 2nd result in the search results on this page with node id 3407 and an overall search score of 29.33.  Higher scores rank the result higher in the results.

[2] Any boost applied at the top level.  1 is equivalent to no extra boost

[3] Score (29.33) which is the product of the numbers at the next bullet level, ie 29.33 X 1

[4] Score (29.33) which is the sum of numbers at the next bullet level, ie 5.26 + 1.47 + 22.58.  Essentially this is saying that 29.33 is made up of the scores for all the fields that matched a search term.

[5] The important thing on this line is that it shows the field that matched a search term and the search term that was matched.  In this case the field is the “abstract” field and the search term matched was “hunt”

[6] The score (5.26) for this field was calculated as the product of the scores from the next bullet level, ie 3.98 X 1.32

[7] 3.98 is the score of the IDF, which is the measurement of how common the search term is across all the documents in the index.

[8] and [9] and the values that make up the IDF calculation

[10] 1.32 is the Term Frequency Normalized, which is a relative score of how frequently the search term appears in this content node

[11] The frequency of the term in this field not normalized

[12] The default constant to normalize term frequency. 0 would rank results soley on the number of terms that match.

[13] The default constant that normalizes the field length. 0 would ignore the field length.

[14] The average length of content in this field across all nodes

[15] The length of the field on this content node.

[16] The next field that had a match and its score. In this case the field is “keys” which means the HTML content of this node, and the term that was matched was “hunt.”   Then the next few lines are similar to the 5-15.

[17] The next field that had a match and it’s score. The field is “title” and the word matched was “hunting”.  Again, the next few lines are the same as 5-15.

[18] The boost for this document type is 1.  Numbers higher than 1 will give the scores higher values, and number below 1 will pull down the score.