#!/usr/bin/env php
<?php

/**
 * @file
 * Import taxonomy terms from CSV files.
 */

// Use fully qualified class names to avoid linter errors.
$term_class = '\Drupal\taxonomy\Entity\Term';
$file_class = '\Drupal\file\Entity\File';

// Ensure we have a video directory.
$video_destination = './sites/aerial/files/videos/';
if (!file_exists($video_destination)) {
  mkdir($video_destination, 0777, TRUE);
}

// First, read the video mapping from VIDEO_TRAINING.csv.
$video_mapping = [];
$video_csv = dirname(__FILE__) . '/data/VIDEO_TRAINING.csv';

// Read species-region mappings.
$species_region_mapping = [];
$species_region_csv = dirname(__FILE__) . '/data/REF_SPECIES_REGION.csv';

if (($handle = fopen($species_region_csv, "r")) !== FALSE) {
  // Skip header row.
  fgetcsv($handle);

  while (($row = fgetcsv($handle)) !== FALSE) {
    $species_id = $row[0];
    $region_id = $row[1];
    if (!isset($species_region_mapping[$species_id])) {
      $species_region_mapping[$species_id] = [];
    }
    $species_region_mapping[$species_id][] = $region_id;
  }

  fclose($handle);
}

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
  'species_counting_difficulty' => [
    'file' => 'REF_DIFFICULTY_LEVEL.csv',
    'field' => 'field_difficulty_level',
    // DIFFICULTY_LEVEL column.
    'value_column' => 0,
    // DESCRIPTION column.
    'name_column' => 1,
  ],
  'species_id_difficulty' => [
    'file' => 'REF_VIDEO_DIFFICULTY_LEVEL.csv',
    'field' => 'field_difficulty_level',
    // VIDEO_DIFFICULTY_LEVEL column.
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
      // Add region references.
      'field_region' => [
        'type' => 'reference_multiple',
        'vocabulary' => 'geographic_region',
        'field' => 'field_geographic_region_id',
        'lookup_callback' => function ($data) use ($species_region_mapping) {
          $species_id = $data[0];
          return $species_region_mapping[$species_id] ?? [];
        },
      ],
    ],
  ],
];

// First, let's track which species IDs are in REF_SPECIES.csv.
$regular_species_ids = [];
$regular_species_csv = dirname(__FILE__) . '/data/REF_SPECIES.csv';
if (($handle = fopen($regular_species_csv, "r")) !== FALSE) {
  // Skip header row.
  fgetcsv($handle);

  while (($data = fgetcsv($handle)) !== FALSE) {
    if (count($data) >= 1) {
      $regular_species_ids[] = $data[0];
    }
  }

  fclose($handle);
}

// Now add the test species import configuration.
$imports['species_test'] = [
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
    // Set is_test_species based on whether it's in REF_SPECIES.csv.
    'field_is_test_species' => [
      'type' => 'boolean_callback',
      'callback' => function ($data) use ($regular_species_ids) {
        $species_id = $data[0];
        // If the species is in REF_SPECIES.csv, it's not a test species.
        return in_array($species_id, $regular_species_ids) ? 0 : 1;
      },
    ],
    // Add region references.
    'field_region' => [
      'type' => 'reference_multiple',
      'vocabulary' => 'geographic_region',
      'field' => 'field_geographic_region_id',
      'lookup_callback' => function ($data) use ($species_region_mapping) {
        $species_id = $data[0];
        return $species_region_mapping[$species_id] ?? [];
      },
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
              if (isset($config['type'])) {
                if ($config['type'] === 'reference') {
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
                elseif ($config['type'] === 'reference_multiple') {
                  // Handle multiple reference fields.
                  $ref_ids = $config['lookup_callback']($data);
                  if (!empty($ref_ids)) {
                    $ref_terms = \Drupal::entityTypeManager()
                      ->getStorage('taxonomy_term')
                      ->loadByProperties([
                        'vid' => $config['vocabulary'],
                        $config['field'] => $ref_ids,
                      ]);
                    if (!empty($ref_terms)) {
                      $term_data[$field] = array_map(function ($term) {
                        return ['target_id' => $term->id()];
                      }, array_values($ref_terms));
                    }
                  }
                }
                elseif ($config['type'] === 'boolean') {
                  $term_data[$field] = ['value' => $config['value']];
                }
                elseif ($config['type'] === 'boolean_callback') {
                  $term_data[$field] = ['value' => $config['callback']($data)];
                }
              }
            }
            else {
              $term_data[$field] = $data[$config];
            }
          }
        }

        // For species from REF_SPECIES_FOR_TEST.csv, we need to handle them differently
        // If the species exists in REF_SPECIES.csv, we don't want to update field_is_test_species
        // If it doesn't exist in REF_SPECIES.csv, we create it with field_is_test_species = 1.
        if ($vocabulary_id === 'species_test' && !empty($terms)) {
          $term = reset($terms);

          // For species that exist in both files, always use the label from REF_SPECIES.csv
          // and just update other fields except name and field_is_test_species.
          foreach ($term_data as $field => $field_value) {
            if ($field === 'vid' || $field === 'field_is_test_species' || $field === 'name') {
              continue;
            }
            $term->set($field, $field_value);
          }
          $term->save();
          $updates++;
          echo "Updated test species: " . $term->label() . "\n";
          continue;
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
          $term = $term_class::create($term_data);
          $term->save();
          $count++;
          echo "Created term: $name\n";
        }

        // Add video file for species if available.
        if (($vocabulary_id === 'species' || $vocabulary_id === 'species_test') &&
            isset($video_mapping[$value])) {
          $filename = $video_mapping[$value];
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
            $file = $file_class::create([
              'uri' => $uri,
              'filename' => $filename,
              'filemime' => 'video/mp4',
              'filesize' => file_exists($video_destination . $filename) ? filesize($video_destination . $filename) : 0,
              'status' => 1,
              'uid' => 1,
            ]);
            $file->save();
            echo "Created file entry for: {$filename}\n";

            // Get the term again to make sure we have the latest version.
            $terms = \Drupal::entityTypeManager()
              ->getStorage('taxonomy_term')
              ->loadByProperties([
                'vid' => 'species',
                $import_config['field'] => $value,
              ]);

            if (!empty($terms)) {
              $term = reset($terms);
              $term->set('field_species_video', ['target_id' => $file->id()]);
              $term->save();
              echo "Added video to species: $name\n";
            }
          }
          else {
            $file = reset($files);

            // Get the term again to make sure we have the latest version.
            $terms = \Drupal::entityTypeManager()
              ->getStorage('taxonomy_term')
              ->loadByProperties([
                'vid' => 'species',
                $import_config['field'] => $value,
              ]);

            if (!empty($terms)) {
              $term = reset($terms);
              $term->set('field_species_video', ['target_id' => $file->id()]);
              $term->save();
              echo "Added existing video to species: $name\n";
            }
          }
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
