<?php

/**
 * @file
 * Drush script to import L_Org.csv data into organization taxonomy terms.
 *
 * Usage: drush scr scripts/import_organizations.php.
 */

use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Core\Entity\EntityStorageException;

$csv_file = '../scripts/manatee/data/L_Org.csv';
if (!file_exists($csv_file)) {
  exit('CSV file not found at: ' . $csv_file);
}

// Initialize counters.
$row_count = 0;
$success_count = 0;
$error_count = 0;

// Get the vocabulary.
$vocabulary = 'org';
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
    // CSV columns from L_Org: Org,Organization,Active,HouseAnimals,Transporter,Comments.
    [$org_code, $organization, $active, $house_species, $transporter, $comments] = $data;

    // Check if term already exists.
    $existing_terms = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadByProperties([
        'vid' => $vocabulary,
        'name' => $org_code,
      ]);

    if (!empty($existing_terms)) {
      print("\nTerm already exists for organization: $org_code - skipping");
      continue;
    }

    // Convert numeric values to boolean for boolean fields.
    $active = (bool) $active;
    $house_species = (bool) $house_species;
    $transporter = (bool) $transporter;

    // Create taxonomy term.
    $term = Term::create([
      'vid' => $vocabulary,
      'name' => $org_code,
      'field_organization' => $organization,
      'field_active' => $active,
      'field_house_species' => $house_species,
      'field_transporter' => $transporter,
      'field_org_comments' => $comments,
      'status' => 1,
    ]);

    $term->save();
    $success_count++;
    print("\nImported organization taxonomy term: $org_code");
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
