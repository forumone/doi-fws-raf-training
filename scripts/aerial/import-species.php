#!/usr/bin/env drush

<?php

/**
 * @file
 * Imports species from a CSV file into the Species taxonomy.
 */

use Drupal\taxonomy\Entity\Term;

// Path to the CSV file relative to the Drupal root.
$csv_file = dirname(__FILE__) . '/species.csv';

if (!file_exists($csv_file)) {
  print("Error: CSV file not found at {$csv_file}\n");
  exit(1);
}

// Read the CSV file.
$handle = fopen($csv_file, 'r');
if (!$handle) {
  print("Error: Could not open CSV file\n");
  exit(1);
}

// Skip the header row.
$header = fgetcsv($handle);

// Load all species group terms indexed by their ID for faster lookups.
$species_groups = [];
$group_terms = \Drupal::entityTypeManager()
  ->getStorage('taxonomy_term')
  ->loadByProperties(['vid' => 'species_group']);

foreach ($group_terms as $term) {
  $group_id = $term->get('field_species_group_id')->value;
  $species_groups[$group_id] = $term;
}

// Process each row.
$row_number = 1;
while (($row = fgetcsv($handle)) !== FALSE) {
  $row_number++;

  // Map CSV columns to variables.
  $species_id = $row[0];
  $species_code = $row[1];
  $species_group_id = $row[2];
  $description = $row[3];

  // Skip if any required field is empty.
  if (empty($species_id) || empty($species_code) || empty($species_group_id) || empty($description)) {
    print("Warning: Skipping row {$row_number} due to missing required fields\n");
    continue;
  }

  // Look up the species group term.
  if (!isset($species_groups[$species_group_id])) {
    print("Warning: Species group ID {$species_group_id} not found for species {$description}\n");
    continue;
  }

  // Check if species already exists by Species ID.
  $existing_terms = \Drupal::entityTypeManager()
    ->getStorage('taxonomy_term')
    ->loadByProperties([
      'vid' => 'species',
      'field_species_id' => $species_id,
    ]);

  if (!empty($existing_terms)) {
    $term = reset($existing_terms);
    print("Updating existing species: {$description}\n");
  }
  else {
    // Use the description as both the term name and description field.
    $term = Term::create([
      'vid' => 'species',
      'name' => $description,
    ]);
    print("Creating new species: {$description}\n");
  }

  // Set/update the field values.
  $term->set('field_species_id', $species_id);
  $term->set('field_species_code', $species_code);
  $term->set('field_species_group', ['target_id' => $species_groups[$species_group_id]->id()]);
  $term->save();
}

fclose($handle);
print("\nSpecies import complete.\n");
