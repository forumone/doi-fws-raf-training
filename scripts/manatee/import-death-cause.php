<?php

/**
 * @file
 * Drush script to import L_Death_Cause.csv data into death cause taxonomy terms.
 *
 * Usage: drush scr scripts/import_death_cause.php.
 */

use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Core\Entity\EntityStorageException;

$csv_file = '../scripts/manatee/data/L_Death_Cause.csv';
if (!file_exists($csv_file)) {
  exit('CSV file not found at: ' . $csv_file);
}

// Initialize counters.
$row_count = 0;
$success_count = 0;
$error_count = 0;

// Get the vocabulary.
$vocabulary = 'death_cause';
$vid = Vocabulary::load($vocabulary);
if (!$vid) {
  exit("Vocabulary '$vocabulary' not found.");
}

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
    // CSV columns: CauseID, DeathCause, DeathCauseDetail, Description.
    [$name, $death_cause, $death_cause_detail, $description] = $data;

    // Check if term already exists.
    $existing_terms = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadByProperties([
        'vid' => $vocabulary,
        'name' => $name,
      ]);

    if (!empty($existing_terms)) {
      print("\nTerm already exists for cause ID: $name - skipping");
      continue;
    }

    // Create taxonomy term.
    $term = Term::create([
      'vid' => $vocabulary,
      'name' => $name,
      'field_death_cause' => $death_cause,
      'field_death_cause_detail' => $death_cause_detail,
      'description' => [
        'value' => $description,
        'format' => 'basic_html',
      ],
      'status' => 1,
    ]);

    $term->save();
    $success_count++;
    print("\nImported death cause taxonomy term: $name");
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
