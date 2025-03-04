#!/usr/bin/env php
<?php

/**
 * @file
 * Drush script to import taxonomy terms from CSV files.
 *
 * Usage: drush scr scripts/falcon/import-taxonomies.php.
 */

use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;

// Define the directory where CSV files are located
// Since the script runs from /var/www/html/web, we need to use a relative path from there.
$csv_directory = 'sites/falcon/files/falcon-data/';

// Map CSV files to taxonomy vocabularies.
$taxonomy_mapping = [
  'falc_ref_species_age' => 'age',
  'falc_ref_cause_of_death' => 'cause_of_death',
  'falc_ref_species_color' => 'gyrfalcon_color',
  'falc_ref_release_codes' => 'if_release_or_loss',
  // Special case: maps to two vocabularies.
  'falc_ref_species_band_type' => ['old_band_type', 'new_band_type'],
  'falc_ref_species_source' => 'source',
  'falc_ref_species_sex' => 'sex',
  'falc_ref_species' => 'species_code',
  'falc_ref_permit_status' => 'status',
  'falc_ref_states' => 'trap_state',
  'falc_ref_cap_recap' => 'capture_recapture',
  'falc_ref_acq_dis_types' => 'type_of_acquisition',
  'falc_ref_transaction_codes' => 'type_of_transfer',
  'falc_ref_permit_classes' => 'falconer_classification',
  // New mappings for the taxonomies we just created.
  'falc_ref_access_codes' => 'access_code',
  'falc_ref_authorized_codes' => 'authorized_code',
  'falc_ref_date_permit_issue_expires' => 'permit_duration',
  'falc_ref_permit_types' => 'permit_type',
];

// Initialize counters.
$total_files = 0;
$processed_files = 0;
$skipped_files = 0;
$total_terms = 0;
$imported_terms = 0;
$errors = 0;

// Debug: Print current working directory.
echo "Current working directory: " . getcwd() . "\n";

// Get all CSV files in the directory with the timestamp pattern.
$csv_files = glob($csv_directory . '*.csv');

// Debug: Print the number of files found.
echo "Found " . count($csv_files) . " CSV files\n";

// Debug: Print the first few files found (if any)
if (!empty($csv_files)) {
  echo "First few files:\n";
  for ($i = 0; $i < min(5, count($csv_files)); $i++) {
    echo "  - " . $csv_files[$i] . "\n";
  }
}
else {
  echo "No files found with pattern: " . $csv_directory . "*.csv\n";

  // Check if directory exists.
  if (!is_dir($csv_directory)) {
    echo "Directory does not exist: " . $csv_directory . "\n";
  }
  else {
    echo "Directory exists but no matching files found\n";

    // List all files in the directory.
    echo "Listing all files in directory:\n";
    $all_files = scandir($csv_directory);
    foreach ($all_files as $file) {
      if ($file != '.' && $file != '..') {
        echo "  - " . $file . "\n";
      }
    }
  }
}

// Process each CSV file.
foreach ($csv_files as $csv_file) {
  $total_files++;

  // Extract the base filename without timestamp and extension.
  $filename = basename($csv_file);
  $base_filename = preg_replace('/_\d+\.csv$/', '', $filename);

  // Debug: Print the extracted base filename.
  echo "Processing file: $filename (Base: $base_filename)\n";

  // Check if this file has a taxonomy mapping.
  if (!isset($taxonomy_mapping[$base_filename])) {
    echo "Skipping file $filename: no taxonomy mapping defined\n";
    $skipped_files++;
    continue;
  }

  // Handle the special case for band types (maps to two vocabularies)
  $vocabularies = is_array($taxonomy_mapping[$base_filename])
    ? $taxonomy_mapping[$base_filename]
    : [$taxonomy_mapping[$base_filename]];

  foreach ($vocabularies as $vocabulary_id) {
    // Check if the vocabulary exists.
    $vocabulary = Vocabulary::load($vocabulary_id);
    if (!$vocabulary) {
      echo "Error: Vocabulary '$vocabulary_id' not found for file $filename\n";
      $errors++;
      continue;
    }

    // Open and read the CSV file.
    if (($handle = fopen($csv_file, "r")) !== FALSE) {
      // Read the header row.
      $header = fgetcsv($handle, 1000, ",");

      // Find the column indices for ref_cd and description.
      $ref_cd_index = array_search('ref_cd', $header);
      $description_index = array_search('description', $header);

      // Special case for permit_duration which has different column structure.
      if ($vocabulary_id === 'permit_duration') {
        $ref_cd_index = array_search('st', $header);
        $description_index = array_search('duration', $header);
        $duration_type_index = array_search('duration_type', $header);
      }

      if ($ref_cd_index === FALSE || ($description_index === FALSE && $vocabulary_id !== 'icon')) {
        echo "Error: Required columns not found in $filename\n";
        echo "Available columns: " . implode(', ', $header) . "\n";
        $errors++;
        fclose($handle);
        continue;
      }

      // Process each row.
      while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
        $total_terms++;

        // Get the term name (ref_cd) and description.
        $term_name = $data[$ref_cd_index];

        // Skip empty terms or header rows.
        if (empty($term_name) || $term_name === '--Select a code--' || $term_name === '---Select a falconer ---') {
          continue;
        }

        // Handle special case for permit_duration.
        if ($vocabulary_id === 'permit_duration') {
          $duration = $data[$description_index];
          $duration_type = $data[$duration_type_index];
          $term_description = "Duration: $duration $duration_type";
        }
        // Handle special case for icon.
        elseif ($vocabulary_id === 'icon') {
          $term_description = "Icon: $term_name";
        }
        // Normal case.
        else {
          $term_description = $data[$description_index];
        }

        // Check if the term already exists.
        $existing_terms = \Drupal::entityTypeManager()
          ->getStorage('taxonomy_term')
          ->loadByProperties([
            'vid' => $vocabulary_id,
            'name' => $term_name,
          ]);

        if (!empty($existing_terms)) {
          // Term already exists, skip.
          continue;
        }

        // Create a new term.
        try {
          $term = Term::create([
            'vid' => $vocabulary_id,
            'name' => $term_name,
            'description' => [
              'value' => $term_description,
              'format' => 'basic_html',
            ],
          ]);
          $term->save();
          $imported_terms++;
          echo "Imported term '$term_name' to vocabulary '$vocabulary_id'\n";
        }
        catch (\Exception $e) {
          echo "Error creating term '$term_name' in vocabulary '$vocabulary_id': " . $e->getMessage() . "\n";
          $errors++;
        }
      }

      fclose($handle);
      $processed_files++;
      echo "Processed file $filename for vocabulary '$vocabulary_id'\n";
    }
    else {
      echo "Could not open file $csv_file\n";
      $errors++;
    }
  }
}

// Print summary.
echo "\n=== Import Summary ===\n";
echo "Total files: $total_files\n";
echo "Processed files: $processed_files\n";
echo "Skipped files: $skipped_files\n";
echo "Total terms: $total_terms\n";
echo "Successfully imported terms: $imported_terms\n";
echo "Errors: $errors\n";
