<?php

$databases['default']['default'] = array(
  'database' => 'default',
  'username' => 'user',
  'password' => 'user',
  'prefix' => '',
  'host' => 'db',
  'port' => 3306,
  'isolation_level' => 'READ COMMITTED',
  'driver' => 'mysql',
  'namespace' => 'Drupal\\mysql\\Driver\\Database\\mysql',
  'autoload' => 'core/modules/mysql/src/Driver/Database/mysql/',
);

// File system paths for Docksal.
$settings['file_public_path'] = 'sites/default/files';
$settings['file_private_path'] = '/var/www/private-files';
