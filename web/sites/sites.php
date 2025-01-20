<?php

/**
 * @file
 * Configuration file for multi-site support and directory aliasing feature.
 */

$sites['eps.ddev.site'] = 'eps';
$sites['fws-raf.ddev.site/epsandhill'] = 'eps';
$sites['fws-raf.ddev.site.epsandhill'] = 'eps';

// Acquia environment configurations.
$sites['doifwsdevapps.prod.acquia-sites.com'] = 'default';
$sites['doifwsdevapps.stage.acquia-sites.com'] = 'default';
$sites['doifwsdevapps.dev.acquia-sites.com'] = 'default';

// Handle /epsandhill paths on Acquia environments.
$sites['doifwsdevapps.prod.acquia-sites.com.epsandhill'] = 'eps';
$sites['doifwsdevapps.stage.prod.acquia-sites.com/epsandhill'] = 'eps';
