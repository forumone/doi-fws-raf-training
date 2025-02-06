<?php

/**
 * @file
 * Creates the Species Group taxonomy vocabulary and its terms.
 */

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\taxonomy\Entity\Term;

// Create the vocabulary if it doesn't exist.
$vocabulary_id = 'species_group';
$vocabulary = Vocabulary::load($vocabulary_id);

if (!$vocabulary) {
  $vocabulary = Vocabulary::create([
    'vid' => $vocabulary_id,
    'name' => 'Species Group',
    'description' => 'Groups of species for aerial videos',
  ]);
  $vocabulary->save();
  print("Created Species Group vocabulary.\n");
}

// Create the Species Group ID field if it doesn't exist.
$field_name = 'field_species_group_id';
$field_storage = FieldStorageConfig::loadByName('taxonomy_term', $field_name);

if (!$field_storage) {
  $field_storage = FieldStorageConfig::create([
    'field_name' => $field_name,
    'entity_type' => 'taxonomy_term',
    'type' => 'integer',
    'settings' => [],
    'cardinality' => 1,
  ]);
  $field_storage->save();
  print("Created Species Group ID field storage.\n");
}

// Create the field instance if it doesn't exist.
$field = FieldConfig::loadByName('taxonomy_term', $vocabulary_id, $field_name);

if (!$field) {
  $field = FieldConfig::create([
    'field_storage' => $field_storage,
    'bundle' => $vocabulary_id,
    'label' => 'Species Group ID',
    'required' => TRUE,
  ]);
  $field->save();
  print("Created Species Group ID field instance.\n");
}

// Configure the form display.
$form_display = \Drupal::entityTypeManager()
  ->getStorage('entity_form_display')
  ->load('taxonomy_term.' . $vocabulary_id . '.default');

if (!$form_display) {
  $form_display = \Drupal::entityTypeManager()
    ->getStorage('entity_form_display')
    ->create([
      'targetEntityType' => 'taxonomy_term',
      'bundle' => $vocabulary_id,
      'mode' => 'default',
      'status' => TRUE,
    ]);
}

$form_display->setComponent($field_name, [
  'type' => 'number',
  'weight' => 1,
])->save();

// Configure the view display.
$view_display = \Drupal::entityTypeManager()
  ->getStorage('entity_view_display')
  ->load('taxonomy_term.' . $vocabulary_id . '.default');

if (!$view_display) {
  $view_display = \Drupal::entityTypeManager()
    ->getStorage('entity_view_display')
    ->create([
      'targetEntityType' => 'taxonomy_term',
      'bundle' => $vocabulary_id,
      'mode' => 'default',
      'status' => TRUE,
    ]);
}

$view_display->setComponent($field_name, [
  'type' => 'number_integer',
  'weight' => 1,
])->save();

// Define the terms.
$terms = [
  ['name' => 'Geese, Swans and Cranes', 'id' => 1],
  ['name' => 'Dabbling Ducks', 'id' => 2],
  ['name' => 'Diving Ducks', 'id' => 3],
  ['name' => 'Sea Ducks', 'id' => 4],
  ['name' => 'Whistling Ducks', 'id' => 5],
  ['name' => 'Loons (video only; not narrated)', 'id' => 6],
  ['name' => 'Other Non-waterfowl (video only; not narrated)', 'id' => 7],
  ['name' => 'ALL species', 'id' => 99],
];

// Create the terms.
foreach ($terms as $term_data) {
  // Check if term already exists by name.
  $existing_terms = \Drupal::entityTypeManager()
    ->getStorage('taxonomy_term')
    ->loadByProperties([
      'vid' => $vocabulary_id,
      'name' => $term_data['name'],
    ]);

  if (empty($existing_terms)) {
    $term = Term::create([
      'vid' => $vocabulary_id,
      'name' => $term_data['name'],
      $field_name => $term_data['id'],
    ]);
    $term->save();
    print("Created term: {$term_data['name']} with ID {$term_data['id']}\n");
  }
  else {
    print("Term already exists: {$term_data['name']}\n");
  }
}

print("Species Group taxonomy setup complete.\n");
