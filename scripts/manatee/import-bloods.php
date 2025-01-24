<?php

/**
 * @file
 * Drush script to import L_Bloods.csv data into blood taxonomy terms.
 *
 * Usage: drush scr scripts/import_bloods.php
 */

use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Core\Entity\EntityStorageException;

$csv_file = '../scripts/manatee/data/L_Bloods.csv';
if (!file_exists($csv_file)) {
  exit('CSV file not found at: ' . $csv_file);
}

// Initialize counters
$row_count = 0;
$success_count = 0;
$error_count = 0;

// Get the vocabulary
$vocabulary = 'blood';
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
    // CSV columns from L_Bloods: Bloods,BloodsTaken,Description
    list($bloods, $bloods_taken, $description) = $data;

    // Check if term already exists
    $existing_terms = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadByProperties([
        'vid' => $vocabulary,
        'name' => $bloods,
      ]);

    if (!empty($existing_terms)) {
      print("\nTerm already exists for blood code: $bloods - skipping");
      continue;
    }

    // Create taxonomy term
    $term = Term::create([
      'vid' => $vocabulary,
      'name' => $bloods,
      'field_blood_text' => $bloods_taken,
      'description' => [
        'value' => $description,
        'format' => 'basic_html',
      ],
      'status' => 1,
    ]);

    $term->save();
    $success_count++;
    print("\nImported blood code taxonomy term: $bloods");
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
