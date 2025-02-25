<?php

/**
 * @file
 * Script to import menu structure for the Falcon project.
 *
 * This script creates the menu structure for the Falcon project.
 * Run with: drush scr scripts/falcon/import-menus.php.
 */

use Drupal\Core\Entity\EntityStorageException;
use Drupal\menu_link_content\Entity\MenuLinkContent;

/**
 * Main function to recreate menus.
 */
function recreate_falcon_menus() {
  // Delete existing menu links.
  delete_existing_menu_links();

  // Create main menu items.
  create_main_menu_links();
}

/**
 * Delete existing menu links from the main menu.
 */
function delete_existing_menu_links() {
  $storage = \Drupal::entityTypeManager()->getStorage('menu_link_content');
  $menu_links = $storage->loadByProperties([
    'menu_name' => ['main'],
  ]);

  foreach ($menu_links as $menu_link) {
    try {
      $menu_link->delete();
    }
    catch (EntityStorageException $e) {
      \Drupal::logger('menu_setup')->error('Failed to delete menu link: @message', ['@message' => $e->getMessage()]);
    }
  }

  \Drupal::messenger()->addMessage('Existing menu links deleted.');
}

/**
 * Create main menu links.
 */
function create_main_menu_links() {
  $main_menu_items = [
    'home' => [
      'title' => 'Home',
      'weight' => -50,
      'uri' => 'internal:/',
    ],
    'profile' => [
      'title' => 'Profile',
      'weight' => -40,
      'uri' => 'internal:/user',
      'children' => [
        'falconry-home' => [
          'title' => 'Falconry Home',
          'weight' => -10,
          'uri' => 'internal:/',
        ],
        'report-a-move' => [
          'title' => 'Report a Move',
          'weight' => -9,
          'uri' => 'internal:/report-move',
        ],
      ],
    ],
    'help' => [
      'title' => 'Help',
      'weight' => -30,
      'uri' => 'internal:/help',
      'children' => [
        'quick-tips' => [
          'title' => 'Quick Tips',
          'weight' => -10,
          'uri' => 'internal:/help/quick-tips',
        ],
        'help-how-query-3-186a-works' => [
          'title' => 'Help How Query 3-186A works',
          'weight' => -9,
          'uri' => 'internal:/help/query-3-186a',
        ],
        'frequently-asked-questions' => [
          'title' => 'Frequently Asked Questions',
          'weight' => -8,
          'uri' => 'internal:/help/faq',
        ],
        'quick-start-guide-for-falconers' => [
          'title' => 'Quick Start Guide for Falconers',
          'weight' => -7,
          'uri' => 'internal:/help/quick-start-falconers',
        ],
        'quick-start-guide-for-others' => [
          'title' => 'Quick Start Guide for Others',
          'weight' => -6,
          'uri' => 'internal:/help/quick-start-others',
        ],
        'state-falconry-regulations' => [
          'title' => 'State Falconry Regulations',
          'weight' => -5,
          'uri' => 'internal:/help/state-regulations',
        ],
        'fillable-federal-form-3-186a' => [
          'title' => 'Fillable Federal form 3-186A (Free)',
          'weight' => -4,
          'uri' => 'internal:/help/form-3-186a',
        ],
        'form-3-186a-instruction' => [
          'title' => 'Form 3-186A Instruction',
          'weight' => -3,
          'uri' => 'internal:/help/form-3-186a-instructions',
        ],
        'step-by-step-procedure' => [
          'title' => 'Step by step procedure to apply USFWS zip-tie...',
          'weight' => -2,
          'uri' => 'internal:/help/zip-tie-procedure',
        ],
        'multi-factor-authentication' => [
          'title' => 'Multi Factor Authentication User\'s Guide',
          'weight' => -1,
          'uri' => 'internal:/help/mfa-guide',
        ],
        'about' => [
          'title' => 'About',
          'weight' => 0,
          'uri' => 'internal:/about',
        ],
      ],
    ],
    'contact' => [
      'title' => 'Contact',
      'weight' => -20,
      'uri' => 'internal:/contact',
    ],
    'logout' => [
      'title' => 'Log out',
      'weight' => -10,
      'uri' => 'internal:/user/logout',
    ],
    'accessibility' => [
      'title' => 'Accessibility',
      'weight' => 0,
      'uri' => 'internal:/accessibility',
    ],
  ];

  create_menu_structure('main', $main_menu_items);

  \Drupal::messenger()->addMessage('Menu links created successfully.');
}

/**
 * Helper function to create menu structure.
 */
function create_menu_structure(string $menu_name, array $items, string $parent = '') {
  foreach ($items as $key => $item) {
    try {
      $menu_link = MenuLinkContent::create([
        'title' => $item['title'],
        'link' => ['uri' => $item['uri'] ?? 'internal:/' . str_replace('_', '-', $key)],
        'menu_name' => $menu_name,
        'weight' => $item['weight'] ?? 0,
        'expanded' => !empty($item['children']),
      ]);

      if ($parent) {
        $menu_link->set('parent', $parent);
      }

      $menu_link->save();

      \Drupal::messenger()->addMessage(t('Created menu link: @title', ['@title' => $item['title']]));

      // Recursively create children if they exist.
      if (!empty($item['children'])) {
        create_menu_structure($menu_name, $item['children'], 'menu_link_content:' . $menu_link->uuid());
      }
    }
    catch (EntityStorageException $e) {
      \Drupal::logger('menu_setup')->error('Failed to create menu link "@title": @message', [
        '@title' => $item['title'],
        '@message' => $e->getMessage(),
      ]);
      \Drupal::messenger()->addError(t('Failed to create menu link: @title', ['@title' => $item['title']]));
    }
  }
}

// Execute the menu recreation.
recreate_falcon_menus();
