<?php

/**
 * @file
 * Drush script to import status month data into status_month taxonomy terms.
 *
 * Usage: drush scr scripts/import_status_months.php
 */

use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Core\Entity\EntityStorageException;

$csv_file = '../scripts/manatee/data/L_Status_Rpt_Mont.csv';
if (!file_exists($csv_file)) {
  exit('CSV file not found at: ' . $csv_file);
}

// Initialize counters
$row_count = 0;
$success_count = 0;
$error_count = 0;

// Get the vocabulary
$vocabulary = 'status_month';
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
    // CSV columns: Month,MonthName
    list($month_code, $month_name) = $data;

    // Check if term already exists
    $existing_terms = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadByProperties([
        'vid' => $vocabulary,
        'name' => $month_code,
      ]);

    if (!empty($existing_terms)) {
      print("\nTerm already exists for month code: $month_code - skipping");
      continue;
    }

    // Create taxonomy term
    $term = Term::create([
      'vid' => $vocabulary,
      'name' => $month_code,
      'field_month_name' => $month_name,
      'status' => 1,
    ]);

    $term->save();
    $success_count++;
    print("\nImported status month taxonomy term: $month_code ($month_name)");
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
