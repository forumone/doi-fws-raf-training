#!/usr/bin/env php
<?php

/**
 * @file
 * Import taxonomy terms from CSV files.
 */

use Drupal\taxonomy\Entity\Term;

// Define the mapping of CSV files to vocabularies.
$imports = [
  'species_id_difficulty' => [
    'file' => 'REF_DIFFICULTY_LEVEL.csv',
    'field' => 'field_difficulty_level',
// DIFFICULTY_LEVEL column.
    'value_column' => 0,
// DESCRIPTION column.
    'name_column' => 1,
  ],
];

foreach ($imports as $vocabulary_id => $import_config) {
  try {
    // Check if vocabulary exists.
    $vocabulary = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_vocabulary')
      ->load($vocabulary_id);

    if (!$vocabulary) {
      echo "Error: Vocabulary '$vocabulary_id' does not exist. Please run create-species-id-difficulty.php first.\n";
      continue;
    }

    // Read and import CSV data.
    $csv_file = dirname(__FILE__) . '/data/' . $import_config['file'];
    if (!file_exists($csv_file)) {
      echo "CSV file not found at: $csv_file\n";
      continue;
    }

    $handle = fopen($csv_file, 'r');
    if (!$handle) {
      echo "Could not open CSV file: $csv_file\n";
      continue;
    }

    $count = 0;
    $updates = 0;
    $errors = [];

    echo "\nProcessing $vocabulary_id terms from {$import_config['file']}:\n";

    // Skip header row.
    fgetcsv($handle);

    // Process each row.
    while (($data = fgetcsv($handle)) !== FALSE) {
      if (count($data) < 2) {
        $errors[] = "Invalid row format: " . implode(',', $data);
        continue;
      }

      $value = $data[$import_config['value_column']];
      $name = $data[$import_config['name_column']];

      try {
        // Check if term already exists.
        $terms = \Drupal::entityTypeManager()
          ->getStorage('taxonomy_term')
          ->loadByProperties([
            'vid' => $vocabulary_id,
            'name' => $name,
          ]);

        if (!empty($terms)) {
          $term = reset($terms);
          $term->set($import_config['field'], $value);
          $term->save();
          $updates++;
          echo "Updated term: $name\n";
        }
        else {
          $term = Term::create([
            'vid' => $vocabulary_id,
            'name' => $name,
            $import_config['field'] => $value,
          ]);
          $term->save();
          $count++;
          echo "Created term: $name\n";
        }
      }
      catch (\Exception $e) {
        $errors[] = "Error processing term '$name': " . $e->getMessage();
      }
    }

    fclose($handle);

    echo "\nImport completed for $vocabulary_id:\n";
    echo "- Created: $count terms\n";
    echo "- Updated: $updates terms\n";

    if (!empty($errors)) {
      echo "\nErrors encountered:\n";
      foreach ($errors as $error) {
        echo "- $error\n";
      }
    }
  }
  catch (\Exception $e) {
    echo "Error processing vocabulary $vocabulary_id: " . $e->getMessage() . "\n";
  }
}
