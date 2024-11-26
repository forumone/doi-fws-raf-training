<?php

/**
 * @file
 * Drush script to import L_Gear_Type.csv data into gear type taxonomy terms.
 *
 * Usage: drush scr scripts/import_gear_types.php
 */

use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Core\Entity\EntityStorageException;

$csv_file = '../scripts/data/L_Gear_Type.csv';
if (!file_exists($csv_file)) {
  exit('CSV file not found at: ' . $csv_file);
}

// Initialize counters
$row_count = 0;
$success_count = 0;
$error_count = 0;

// Get the vocabulary
$vocabulary = 'gear_type';
$vid = Vocabulary::load($vocabulary);
if (!$vid) {
  exit("Vocabulary '$vocabulary' not found.");
}

// Open CSV file
$handle = fopen($csv_file, 'r');
if (!$handle) {
  exit('Error opening CSV file.');
}

// Skip header row
fgetcsv($handle);

// Process each row
while (($data = fgetcsv($handle)) !== FALSE) {
  $row_count++;

  try {
    // CSV columns from L_Gear_Type: Gear,GearType,Description
    list($gear_code, $gear_type, $description) = $data;

    // Check if term already exists
    $existing_terms = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadByProperties([
        'vid' => $vocabulary,
        'name' => $gear_code,
      ]);

    if (!empty($existing_terms)) {
      print("\nTerm already exists for gear type: $gear_code - skipping");
      continue;
    }

    // Create taxonomy term
    $term = Term::create([
      'vid' => $vocabulary,
      'name' => $gear_code,
      'field_gear_type' => $gear_type,
      'description' => [
        'value' => $description,
        'format' => 'basic_html',
      ],
      'status' => 1,
    ]);

    $term->save();
    $success_count++;
    print("\nImported gear type taxonomy term: $gear_code");
  } catch (EntityStorageException $e) {
    print("\nError on row $row_count: " . $e->getMessage());
    $error_count++;
  } catch (Exception $e) {
    print("\nGeneral error on row $row_count: " . $e->getMessage());
    $error_count++;
  }
}

fclose($handle);

// Print summary
print("\nImport completed:");
print("\nTotal rows processed: $row_count");
print("\nSuccessfully imported: $success_count");
print("\nErrors: $error_count\n");
