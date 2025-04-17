<?php

/**
 * @file
 * Drush script to import L_Death_Cause.csv data into rescue_cause taxonomy terms.
 *
 * This script skips terms where the name (CauseID) already exists in rescue_cause.
 *
 * Usage: drush scr scripts/manatee/import-rescue-cause.php.
 */

use Drupal\Core\Entity\EntityStorageException;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;

$csv_file = '../scripts/manatee/data/L_Death_Cause.csv';
if (!file_exists($csv_file)) {
  exit('CSV file not found at: ' . $csv_file);
}

// Initialize counters.
$row_count = 0;
$success_count = 0;
$error_count = 0;

// Get the target vocabulary.
$vocabulary = 'rescue_cause';
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
    // CSV columns from L_Death_Cause.csv: CauseID, DeathCause, DeathCauseDetail, Description.
    [$name, $death_cause, $death_cause_detail, $description] = $data;

    // Trim whitespace from name/ID.
    $name = trim($name);

    // Check if a term with this name already exists in the rescue_cause vocabulary.
    $existing_terms = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadByProperties([
        'vid' => $vocabulary,
        'name' => $name,
      ]);

    if (!empty($existing_terms)) {
      print("\nTerm already exists in rescue_cause for name: $name - skipping");
      continue;
    }

    // Create taxonomy term in rescue_cause vocabulary.
    $term = Term::create([
      'vid' => $vocabulary,
      'name' => $name,
      // Map DeathCause fields to RescueCause fields.
      'field_rescue_cause' => $death_cause,
      'field_rescue_cause_detail' => $death_cause_detail,
      'description' => [
        'value' => $description,
        'format' => 'basic_html',
      ],
      'status' => 1,
    ]);
    $term->save();

    $success_count++;
    print("\nImported death cause \"$name\" into rescue cause taxonomy term.");
  }
  catch (EntityStorageException $e) {
    print("\nError processing death cause $name (row $row_count): " . $e->getMessage());
    $error_count++;
  }
  catch (Exception $e) {
    print("\nGeneral error processing death cause $name (row $row_count): " . $e->getMessage());
    $error_count++;
  }
}

fclose($handle);

// Print summary.
print("\nImport completed:");
print("\nTotal death cause rows processed: $row_count");
print("\nSuccessfully imported into rescue_cause: $success_count");
print("\nErrors: $error_count\n");
