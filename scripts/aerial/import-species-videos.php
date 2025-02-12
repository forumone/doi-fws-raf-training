#!/usr/bin/env php
<?php

/**
 * @file
 * Import species videos from CSV file.
 */

use Drupal\media\Entity\Media;

// Read CSV files.
$csv_file = dirname(__FILE__) . '/data/VIDEO_FILE_METADATA.csv';
$choices_csv = dirname(__FILE__) . '/data/VIDEO_FILE_SPECIES_CHOICE.csv';

if (!file_exists($csv_file)) {
  die("CSV file not found at: $csv_file\n");
}

if (!file_exists($choices_csv)) {
  die("Species choices CSV file not found at: $choices_csv\n");
}

// First, read all species choices into an array.
$species_choices = [];
$choices_handle = fopen($choices_csv, 'r');
if (!$choices_handle) {
  die("Could not open species choices CSV file\n");
}

// Skip header row.
fgetcsv($choices_handle);

// Build array of file_id => [species_ids].
while (($data = fgetcsv($choices_handle)) !== FALSE) {
  $file_id = $data[0];
  $species_id = $data[1];
  if (!isset($species_choices[$file_id])) {
    $species_choices[$file_id] = [];
  }
  $species_choices[$file_id][] = $species_id;
}

fclose($choices_handle);

// Now process the main video metadata file.
$handle = fopen($csv_file, 'r');
if (!$handle) {
  die("Could not open CSV file\n");
}

$count = 0;
$updates = 0;
$errors = [];

// Skip header row.
fgetcsv($handle);

// Process each row.
while (($data = fgetcsv($handle)) !== FALSE) {
  if (count($data) < 6) {
    $errors[] = "Invalid row format: " . implode(',', $data);
    continue;
  }

  $file_id = $data[0];
  $location = trim($data[2]);
  $elapsed_time = (int) $data[3];
  $species_id = $data[4];
  $difficulty_level = $data[5];

  try {
    // Look up the species term.
    $species_terms = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadByProperties([
        'vid' => 'species',
        'field_species_id' => $species_id,
      ]);

    if (empty($species_terms)) {
      $errors[] = "Species not found for ID: $species_id";
      continue;
    }

    // Look up the difficulty level term.
    $difficulty_terms = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadByProperties([
        'vid' => 'species_counting_difficulty',
        'field_difficulty_level' => $difficulty_level,
      ]);

    if (empty($difficulty_terms)) {
      $errors[] = "Difficulty level not found for ID: $difficulty_level";
      continue;
    }

    $species_term = reset($species_terms);
    $difficulty_term = reset($difficulty_terms);

    // Look up species choices if they exist for this file.
    $species_choices_refs = [];
    if (isset($species_choices[$file_id])) {
      foreach ($species_choices[$file_id] as $choice_species_id) {
        $choice_terms = \Drupal::entityTypeManager()
          ->getStorage('taxonomy_term')
          ->loadByProperties([
            'vid' => 'species',
            'field_species_id' => $choice_species_id,
          ]);
        if (!empty($choice_terms)) {
          $choice_term = reset($choice_terms);
          $species_choices_refs[] = ['target_id' => $choice_term->id()];
        }
      }
    }

    // Check if media already exists for this species and difficulty level.
    $existing_media = \Drupal::entityTypeManager()
      ->getStorage('media')
      ->loadByProperties([
        'bundle' => 'species_video',
        'field_species' => $species_term->id(),
        'field_difficulty_level' => $difficulty_term->id(),
        'field_location' => $location,
        'field_elapsed_time' => $elapsed_time,
      ]);

    if (!empty($existing_media)) {
      // Update existing media.
      $media = reset($existing_media);
      $media->set('field_location', $location);
      $media->set('field_elapsed_time', $elapsed_time);
      $media->set('field_species', ['target_id' => $species_term->id()]);
      $media->set('field_difficulty_level', ['target_id' => $difficulty_term->id()]);
      if (!empty($species_choices_refs)) {
        $media->set('field_species_choices', $species_choices_refs);
      }
      $media->save();
      $updates++;
      echo "Updated media for species {$species_term->label()} with difficulty {$difficulty_term->label()}\n";
    }
    else {
      // Create new media entity.
      $media = Media::create([
        'bundle' => 'species_video',
        'name' => $species_term->label() . ' - ' . $difficulty_term->label(),
        'field_location' => $location,
        'field_elapsed_time' => $elapsed_time,
        'field_species' => ['target_id' => $species_term->id()],
        'field_difficulty_level' => ['target_id' => $difficulty_term->id()],
        'field_species_choices' => $species_choices_refs,
        'status' => 1,
      ]);
      $media->save();
      $count++;
      echo "Created media for species {$species_term->label()} with difficulty {$difficulty_term->label()}\n";
    }
  }
  catch (\Exception $e) {
    $errors[] = "Error processing row with species ID $species_id: " . $e->getMessage();
  }
}

fclose($handle);

echo "\nImport completed.\n";
echo "Successfully created $count media entities and updated $updates media entities.\n";

if (!empty($errors)) {
  echo "\nErrors encountered:\n";
  foreach ($errors as $error) {
    echo "- $error\n";
  }
}
