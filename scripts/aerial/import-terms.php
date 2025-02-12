#!/usr/bin/env php
<?php

/**
 * @file
 * Import taxonomy terms from CSV files.
 */

use Drupal\taxonomy\Entity\Term;
use Drupal\file\Entity\File;

// Ensure we have a video directory.
$video_destination = './sites/aerial/files/videos/';
if (!file_exists($video_destination)) {
  mkdir($video_destination, 0777, TRUE);
}

// First, read the video mapping from VIDEO_TRAINING.csv.
$video_mapping = [];
$video_csv = dirname(__FILE__) . '/data/VIDEO_TRAINING.csv';

if (($handle = fopen($video_csv, "r")) !== FALSE) {
  // Skip header row.
  fgetcsv($handle);

  while (($row = fgetcsv($handle)) !== FALSE) {
    $file_name = $row[1];
    $species_id = $row[2];
    $resolution = $row[4];

    // Only store HIGH resolution videos.
    if ($resolution === 'HIGH') {
      $video_mapping[$species_id] = $file_name . '.mp4';
    }
  }

  fclose($handle);
}

print("\nFound " . count($video_mapping) . " HIGH resolution videos in CSV.\n");

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
  'geographic_region' => [
    'file' => 'REF_GEOGRAPHIC_REGION.csv',
    'field' => 'field_geographic_region_id',
    // GEOGRAPHIC_REGION column.
    'value_column' => 0,
    // DESCRIPTION column.
    'name_column' => 1,
  ],
  'size_range' => [
    'file' => 'REF_SIZE_RANGE.csv',
    'field' => 'field_size_range_id',
    // SIZE_RANGE column.
    'value_column' => 0,
    // DESCRIPTION column.
    'name_column' => 1,
    // Additional fields specific to size range.
    'additional_fields' => [
      // MIN_SIZE column.
      'field_size_range_min' => 2,
      // MAX_SIZE column.
      'field_size_range_max' => 3,
    ],
  ],
  'species_group' => [
    'file' => 'REF_SPECIES_GROUP.csv',
    'field' => 'field_species_group_id',
    // SPECIES_GROUP column.
    'value_column' => 0,
    // DESCRIPTION column.
    'name_column' => 1,
  ],
  'species' => [
    'file' => 'REF_SPECIES.csv',
    'field' => 'field_species_id',
    // SPECIES column.
    'value_column' => 0,
    // DESCRIPTION column.
    'name_column' => 3,
    // Additional fields specific to species.
    'additional_fields' => [
      // SPECIES_CODE column.
      'field_species_code' => 1,
      // SPECIES_GROUP column.
      'field_species_group' => [
        'type' => 'reference',
        'vocabulary' => 'species_group',
        'field' => 'field_species_group_id',
        'column' => 2,
      ],
      // Set is_test_species to 0 for base species.
      'field_is_test_species' => [
        'type' => 'boolean',
        'value' => 0,
      ],
    ],
  ],
  'species_test' => [
    'file' => 'REF_SPECIES_FOR_TEST.csv',
    'field' => 'field_species_id',
    // SPECIES column.
    'value_column' => 0,
    // DESCRIPTION column.
    'name_column' => 3,
    // Additional fields specific to species.
    'additional_fields' => [
      // SPECIES_CODE column.
      'field_species_code' => 1,
      // SPECIES_GROUP column.
      'field_species_group' => [
        'type' => 'reference',
        'vocabulary' => 'species_group',
        'field' => 'field_species_group_id',
        'column' => 2,
      ],
      // Set is_test_species to 1 for test species.
      'field_is_test_species' => [
        'type' => 'boolean',
        'value' => 1,
      ],
    ],
  ],
];

// Import the basic vocabularies first.
foreach ($imports as $vocabulary_id => $import_config) {
  try {
    // For both species imports, use the species vocabulary.
    $actual_vocabulary_id = $vocabulary_id === 'species_test' ? 'species' : $vocabulary_id;

    // Check if vocabulary exists.
    $vocabulary = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_vocabulary')
      ->load($actual_vocabulary_id);

    if (!$vocabulary) {
      echo "Error: Vocabulary '$actual_vocabulary_id' does not exist.\n";
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

    echo "\nProcessing $actual_vocabulary_id terms from {$import_config['file']}:\n";

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
            'vid' => $actual_vocabulary_id,
            $import_config['field'] => $value,
          ]);

        // Prepare term data.
        $term_data = [
          'vid' => $actual_vocabulary_id,
          'name' => $name,
          $import_config['field'] => $value,
        ];

        // Add additional fields if configured.
        if (isset($import_config['additional_fields'])) {
          foreach ($import_config['additional_fields'] as $field => $config) {
            if (is_array($config)) {
              if (isset($config['type']) && $config['type'] === 'reference') {
                // Handle reference fields.
                $ref_terms = \Drupal::entityTypeManager()
                  ->getStorage('taxonomy_term')
                  ->loadByProperties([
                    'vid' => $config['vocabulary'],
                    $config['field'] => $data[$config['column']],
                  ]);
                if (!empty($ref_terms)) {
                  $ref_term = reset($ref_terms);
                  $term_data[$field] = ['target_id' => $ref_term->id()];
                }
              }
              elseif (isset($config['type']) && $config['type'] === 'boolean') {
                $term_data[$field] = ['value' => $config['value']];
              }
            }
            else {
              $term_data[$field] = $data[$config];
            }
          }
        }

        if (!empty($terms)) {
          $term = reset($terms);
          // Update all configured fields.
          foreach ($term_data as $field => $field_value) {
            if ($field === 'vid') {
              continue;
            }
            $term->set($field, $field_value);
          }
          $term->save();
          $updates++;
          echo "Updated term: $name\n";
        }
        else {
          // Convert simple field values to proper format for creation.
          foreach ($term_data as $field => $field_value) {
            if ($field !== 'vid' && !is_array($field_value)) {
              $term_data[$field] = ['value' => $field_value];
            }
          }
          $term = Term::create($term_data);
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

    echo "\nImport completed for $actual_vocabulary_id:\n";
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
    echo "Error processing vocabulary $actual_vocabulary_id: " . $e->getMessage() . "\n";
  }
}

// Now import species terms.
$species_csv = dirname(__FILE__) . '/data/REF_SPECIES_FOR_TEST.csv';
$missing_files = [];

if (($handle = fopen($species_csv, "r")) !== FALSE) {
  // Skip header row.
  fgetcsv($handle);

  $count = 0;
  $updates = 0;
  $errors = [];

  echo "\nProcessing species terms from REF_SPECIES_FOR_TEST.csv:\n";

  while (($data = fgetcsv($handle)) !== FALSE) {
    if (count($data) < 4) {
      $errors[] = "Invalid row format: " . implode(',', $data);
      continue;
    }

    $species_id = $data[0];
    $code = $data[1];
    $group_id = $data[2];
    $name = $data[3];

    try {
      // Look up the species group term.
      $group_terms = \Drupal::entityTypeManager()
        ->getStorage('taxonomy_term')
        ->loadByProperties([
          'vid' => 'species_group',
          'field_species_group_id' => $group_id,
        ]);

      if (empty($group_terms)) {
        $errors[] = "Species group not found for ID: $group_id";
        continue;
      }

      $group_term = reset($group_terms);

      // Prepare video file if exists.
      $file = NULL;
      if (isset($video_mapping[$species_id])) {
        $filename = $video_mapping[$species_id];
        $uri = 'public://videos/' . $filename;

        // Check if file exists locally.
        if (!file_exists($video_destination . $filename)) {
          $missing_files[] = $filename;
        }

        // Create managed file entry.
        $files = \Drupal::entityTypeManager()
          ->getStorage('file')
          ->loadByProperties(['uri' => $uri]);

        if (empty($files)) {
          $file = File::create([
            'uri' => $uri,
            'filename' => $filename,
            'filemime' => 'video/mp4',
            'filesize' => file_exists($video_destination . $filename) ? filesize($video_destination . $filename) : 0,
            'status' => 1,
            'uid' => 1,
          ]);
          $file->save();
          echo "Created file entry for: {$filename}\n";
        }
        else {
          $file = reset($files);
          echo "Found existing file: {$filename}\n";
        }
      }

      // Check if species term already exists.
      $terms = \Drupal::entityTypeManager()
        ->getStorage('taxonomy_term')
        ->loadByProperties([
          'vid' => 'species',
          'field_species_id' => $species_id,
        ]);

      if (empty($terms)) {
        $term = Term::create([
          'vid' => 'species',
          'name' => $name,
          'field_species_id' => ['value' => $species_id],
          'field_species_code' => ['value' => $code],
          'field_species_group' => ['target_id' => $group_term->id()],
          'field_is_test_species' => ['value' => 0],
        ]);

        if ($file) {
          $term->set('field_species_video', ['target_id' => $file->id()]);
        }

        $term->save();
        $count++;
        echo "Created species term: $name\n";
      }
      else {
        $term = reset($terms);
        $term->set('name', $name);
        $term->set('field_species_code', ['value' => $code]);
        $term->set('field_species_group', ['target_id' => $group_term->id()]);
        $term->set('field_is_test_species', ['value' => 0]);

        if ($file) {
          $term->set('field_species_video', ['target_id' => $file->id()]);
        }

        $term->save();
        $updates++;
        echo "Updated species term: $name\n";
      }
    }
    catch (\Exception $e) {
      $errors[] = "Error processing species '$name': " . $e->getMessage();
    }
  }

  fclose($handle);

  echo "\nImport completed for species:\n";
  echo "- Created: $count terms\n";
  echo "- Updated: $updates terms\n";

  if (!empty($errors)) {
    echo "\nErrors encountered:\n";
    foreach ($errors as $error) {
      echo "- $error\n";
    }
  }
}

// Report missing files if any.
if (!empty($missing_files)) {
  echo "\nWARNING: The following video files need to be downloaded:\n";
  foreach ($missing_files as $file) {
    echo "  - {$file}\n";
  }
  echo "\nPlease download these files to web/sites/aerial/files/videos/ when ready.\n";
  echo "Total files to download: " . count($missing_files) . "\n";
}

// Report totals.
$term_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$group_count = $term_storage->getQuery()
  ->accessCheck(FALSE)
  ->condition('vid', 'species_group')
  ->count()
  ->execute();

$species_count = $term_storage->getQuery()
  ->accessCheck(FALSE)
  ->condition('vid', 'species')
  ->count()
  ->execute();

$file_count = \Drupal::entityTypeManager()
  ->getStorage('file')
  ->getQuery()
  ->accessCheck(FALSE)
  ->condition('filemime', 'video/mp4')
  ->count()
  ->execute();

echo "\nImport complete:\n";
echo "- Species Groups: {$group_count}\n";
echo "- Species: {$species_count}\n";
echo "- Video Files: {$file_count}\n";
