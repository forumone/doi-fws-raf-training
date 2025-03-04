# FWS Reusable Application

This is the FWS reusable application for FWS projects. It provides a base configuration and setup for Drupal 10 projects, including essential modules, configurations, and recipes.

## Table of Contents

- [Introduction](#introduction)
- [Prerequisites](#prerequisites)
- [Getting Started](#getting-started)
  - [Using DDEV](#using-ddev)
  - [Using Docksal](#using-docksal)
- [Testing](#testing)
- [Theming](#theming)
- [Project Structure](#project-structure)
- [Contributing](#contributing)
- [License](#license)

## Introduction

The FWS reusable application is designed to streamline the development of Drupal 10 projects by providing a standardized setup with commonly used modules and configurations. It leverages tools like DDEV and Docksal for local development, making it easy to get started quickly.

## Prerequisites

- **Git**: For cloning the repository.
- **Docker**: Required by both DDEV and Docksal.
- **DDEV** or **Docksal**: Local development environments.
- **Node.js**: Required for running Cypress tests.

## Getting Started

You can set up the project locally using either DDEV or Docksal. Follow the instructions below based on your preferred local development environment.

### Using DDEV

[DDEV](https://ddev.readthedocs.io/en/stable/) is an open-source tool that simplifies setting up a local PHP development environment.

1. **Install DDEV**: Follow the [official installation guide](https://ddev.readthedocs.io/en/stable/#installation).

2. **Clone the Repository**:

   ```bash
   git clone [repository-url]
   cd [repository-directory]
   ```

3. **Start DDEV**:

   ```bash
   ddev start
   ```

4. **Install FWS**:

   ```bash
   ddev install
   ```

   This command runs the installation script that:

   - Installs Composer dependencies
   - Installs Drupal with the minimal profile
   - Applies FWS core recipe
   - Runs cleanup scripts
   - Generates a login link

5. **Install site specific config**:

   This will run through commands for a file located in `.ddev/commands/host/` relative to the site you are working on.  For example, if you are working on the site run the following script

   ```bash
   .ddev/commands/host/install-aerial
   ```
**NOTE:**
You can run `ddev composer install` to run composer through ddev when you need to run ddev

### Using Docksal

[Docksal](https://docksal.io/) is a Docker-based development environment for web projects.

1. **Install Docksal**: Follow the [official installation guide](https://docs.docksal.io/getting-started/setup/).

2. **Clone the Repository**:

   ```bash
   git clone [repository-url]
   cd [repository-directory]
   ```

3. **Start Docksal**:

   ```bash
   fin start
   ```

4. **Install FWS**:

   ```bash
   fin install
   ```

   This command runs the installation script that performs the same steps as the DDEV installation.

## Testing

To run Cypress tests:

1. Navigate to the Cypress test directory:
   ```bash
   cd tests/cypress
   ```

2. Install Node.js using nvm:
   ```bash
   nvm install
   ```

3. Install dependencies:
   ```bash
   npm install
   ```

4. Run Cypress tests:
   ```bash
   npm run cypress
   ```

## Theme Development

The application uses the `fws_raf` theme, which is a subtheme of the Bootstrap-based `fws_gov` theme. The parent theme is automatically installed via Composer dependencies during the installation process.

1. Change to the theme directory:

   ```bash
   cd web/themes/custom/fws_raf
   ```

2. Install dependencies:

   ```bash
   npm install
   ```

3. Run Gulp to compile changes:

   ```bash
   npm run watch
   ```

4. Enable theme debugging and local development configuration

   This RAF scaffolds multiple directories inside of `/sites` adjacent to the typically utilized `/default` directory.  Please be sure to work within the directory for the project you installed.  If you have not already, please be sure to check `/admin/config/development/performance` and confirm that everything is turned off.

5. Drop `local.services.yml` and `setting.local.php` into your project's sites directory.  For example, on Aerial these files will be located at `web/sites/aerial/local.services.yml` and `web/sites/aerial/settings.local.php`.

####local.services.yml
```
#
# Local development override configuration feature.
#
# To activate this feature, copy and rename it such that its path plus
# filename is 'sites/default/local.services.yml'. Then, ensure that you have copied
# 'example.local.settngs.php' to 'sites/default/local.settings.php to enable it.

parameters:
  twig.config:
    debug: true
    auto_reload: true
    cache: false
  http.response.debug_cacheability_headers: true
services:
   cache.backend.null:
     class: Drupal\Core\Cache\NullBackendFactory
```

####settings.local.php
```
<?php

// phpcs:ignoreFile

/**
 * @file
 * Local development override configuration feature.
 *
 * To activate this feature, copy and rename it such that its path plus
 * filename is 'sites/default/settings.local.php'. Then, go to the bottom of
 * 'sites/default/settings.php' and uncomment the commented lines that mention
 * 'settings.local.php'.
 *
 * If you are using a site name in the path, such as 'sites/example.com', copy
 * this file to 'sites/example.com/settings.local.php', and uncomment the lines
 * at the bottom of 'sites/example.com/settings.php'.
 */

/**
 * Assertions.
 *
 * The Drupal project primarily uses runtime assertions to enforce the
 * expectations of the API by failing when incorrect calls are made by code
 * under development.
 *
 * @see http://php.net/assert
 * @see https://www.drupal.org/node/2492225
 *
 * It is strongly recommended that you set zend.assertions=1 in the PHP.ini file
 * (It cannot be changed from .htaccess or runtime) on development machines and
 * to 0 or -1 in production.
 */

/**
 * Enable local development services.
 */
$settings['container_yamls'][] = DRUPAL_ROOT . '/sites/aerial/local.services.yml';

/**
 * Show all error messages, with backtrace information.
 *
 * In case the error level could not be fetched from the database, as for
 * example the database connection failed, we rely only on this value.
 */
$config['system.logging']['error_level'] = 'verbose';

/**
 * Disable CSS and JS aggregation.
 */
$config['system.performance']['css']['preprocess'] = FALSE;
$config['system.performance']['js']['preprocess'] = FALSE;

/**
 * Disable the render cache.
 *
 * Note: you should test with the render cache enabled, to ensure the correct
 * cacheability metadata is present. However, in the early stages of
 * development, you may want to disable it.
 *
 * This setting disables the render cache by using the Null cache back-end
 * defined by the development.services.yml file above.
 *
 * Only use this setting once the site has been installed.
 */
$settings['cache']['bins']['render'] = 'cache.backend.null';

/**
 * Disable caching for migrations.
 *
 * Uncomment the code below to only store migrations in memory and not in the
 * database. This makes it easier to develop custom migrations.
 */
# $settings['cache']['bins']['discovery_migration'] = 'cache.backend.memory';

/**
 * Disable Internal Page Cache.
 *
 * Note: you should test with Internal Page Cache enabled, to ensure the correct
 * cacheability metadata is present. However, in the early stages of
 * development, you may want to disable it.
 *
 * This setting disables the page cache by using the Null cache back-end
 * defined by the development.services.yml file above.
 *
 * Only use this setting once the site has been installed.
 */
$settings['cache']['bins']['page'] = 'cache.backend.null';

/**
 * Disable Dynamic Page Cache.
 *
 * Note: you should test with Dynamic Page Cache enabled, to ensure the correct
 * cacheability metadata is present (and hence the expected behavior). However,
 * in the early stages of development, you may want to disable it.
 */
$settings['cache']['bins']['dynamic_page_cache'] = 'cache.backend.null';

/**
 * Allow test modules and themes to be installed.
 *
 * Drupal ignores test modules and themes by default for performance reasons.
 * During development it can be useful to install test extensions for debugging
 * purposes.
 */
# $settings['extension_discovery_scan_tests'] = TRUE;

/**
 * Enable access to rebuild.php.
 *
 * This setting can be enabled to allow Drupal's php and database cached
 * storage to be cleared via the rebuild.php page. Access to this page can also
 * be gained by generating a query string from rebuild_token_calculator.sh and
 * using these parameters in a request to rebuild.php.
 */
$settings['rebuild_access'] = TRUE;

/**
 * Skip file system permissions hardening.
 *
 * The system module will periodically check the permissions of your site's
 * site directory to ensure that it is not writable by the website user. For
 * sites that are managed with a version control system, this can cause problems
 * when files in that directory such as settings.php are updated, because the
 * user pulling in the changes won't have permissions to modify files in the
 * directory.
 */
$settings['skip_permissions_hardening'] = TRUE;

/**
 * Exclude modules from configuration synchronization.
 *
 * On config export sync, no config or dependent config of any excluded module
 * is exported. On config import sync, any config of any installed excluded
 * module is ignored. In the exported configuration, it will be as if the
 * excluded module had never been installed. When syncing configuration, if an
 * excluded module is already installed, it will not be uninstalled by the
 * configuration synchronization, and dependent configuration will remain
 * intact. This affects only configuration synchronization; single import and
 * export of configuration are not affected.
 *
 * Drupal does not validate or sanity check the list of excluded modules. For
 * instance, it is your own responsibility to never exclude required modules,
 * because it would mean that the exported configuration can not be imported
 * anymore.
 *
 * This is an advanced feature and using it means opting out of some of the
 * guarantees the configuration synchronization provides. It is not recommended
 * to use this feature with modules that affect Drupal in a major way such as
 * the language or field module.
 */
# $settings['config_exclude_modules'] = ['devel', 'stage_file_proxy'];
```

6. Update `settings.php` in your site's directory directly under the ddev configuration to include the following (~line 900):

```
$local_settings = __DIR__ . '/settings.local.php';
if (getenv('IS_DDEV_PROJECT') == 'true' && is_readable($local_settings)) {
  require $local_settings;
}
```

7. Flush Drupal's cache and check for theme debug in the markup to confirm everything is configured properly.  You should now be good to go!

## Project Structure

```
project-root/
├── .ddev/                  # DDEV configuration
├── .docksal/               # Docksal configuration
├── recipes/                # FWS installation recipes
├── scripts/               # Installation and utility scripts
├── tests/                 # Test files
│   └── cypress/          # Cypress test suite
└── README.md
```
