<?php

/**
 * @file
 * Drush script to import L_Rescue_Cause.csv data into rescue_cause taxonomy terms.
 *
 * Usage: drush scr scripts/import_rescue_cause.php.
 */

use Drupal\Core\Entity\EntityStorageException;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;

$csv_file = '../scripts/data/L_Rescue_Cause.csv';
if (!file_exists($csv_file)) {
  exit('CSV file not found at: ' . $csv_file);
}

// Initialize counters.
$row_count = 0;
$success_count = 0;
$error_count = 0;

// Get the vocabulary.
$vocabulary = 'rescue_cause';
$vid = Vocabulary::load($vocabulary);
if (!$vid) {
  exit("Vocabulary '$vocabulary' not found.");
}

// Create array to store parent terms for hierarchy.
$parent_terms = [];

// Open CSV file.
$handle = fopen($csv_file, 'r');
if (!$handle) {
  exit('Error opening CSV file.');
}

// Skip header row.
fgetcsv($handle);

// Process each row.
while (($data = fgetcsv($handle)) !== FALSE) {
  $row_count++;

  try {
    // CSV columns: CauseID,RescueCause,RescueCauseDetail,Description.
    [$cause_id, $rescue_cause, $rescue_cause_detail, $description] = $data;

    // Trim whitespace from IDs.
    $cause_id = trim($cause_id);

    // Check if term already exists.
    $existing_terms = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadByProperties([
        'vid' => $vocabulary,
        'name' => $cause_id,
      ]);

    if (!empty($existing_terms)) {
      print("\nTerm already exists for rescue cause: $cause_id - skipping");
      continue;
    }

    // Create taxonomy term.
    $term = Term::create([
      'vid' => $vocabulary,
      'name' => $cause_id,
      'field_rescue_cause' => $rescue_cause,
      'field_rescue_cause_detail' => $rescue_cause_detail,
      'description' => [
        'value' => $description,
        'format' => 'basic_html',
      ],
      'status' => 1,
    ]);
    $term->save();

    $success_count++;
    print("\nImported rescue cause taxonomy term: $cause_id");
  }
  catch (EntityStorageException $e) {
    print("\nError on row $row_count: " . $e->getMessage());
    $error_count++;
  }
  catch (Exception $e) {
    print("\nGeneral error on row $row_count: " . $e->getMessage());
    $error_count++;
  }
}

fclose($handle);

// Print summary.
print("\nImport completed:");
print("\nTotal rows processed: $row_count");
print("\nSuccessfully imported: $success_count");
print("\nErrors: $error_count\n");
