<?php

/**
 * @file
 * Import species groups and species taxonomy terms, along with video files.
 */

// Ensure we have a video directory.
$video_destination = './sites/aerial/files/videos/';
if (!file_exists($video_destination)) {
  mkdir($video_destination, 0777, TRUE);
}

// First, import species groups.
$species_groups = [];
$group_csv = dirname(__FILE__) . '/data/REF_SPECIES_GROUP.csv';

if (($handle = fopen($group_csv, "r")) !== FALSE) {
  // Skip header row.
  fgetcsv($handle);

  while (($row = fgetcsv($handle)) !== FALSE) {
    $group_id = $row[0];
    $name = $row[1];

    // Create or load the species group term.
    $terms = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadByProperties([
        'vid' => 'species_group',
        'field_species_group_id' => $group_id,
      ]);

    if (empty($terms)) {
      $term = \Drupal::entityTypeManager()
        ->getStorage('taxonomy_term')
        ->create([
          'vid' => 'species_group',
          'name' => $name,
          'field_species_group_id' => $group_id,
        ]);
      $term->save();
      print("Created species group: {$name} (ID: {$group_id})\n");
    }
    else {
      $term = reset($terms);
      print("Found existing species group: {$name} (ID: {$group_id})\n");
    }

    $species_groups[$group_id] = $term;
  }

  fclose($handle);
}

// Next, read the video mapping from VIDEO_TRAINING.csv.
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

// Finally, import species terms and link videos.
$species_csv = dirname(__FILE__) . '/data/REF_SPECIES.csv';
$missing_files = [];

if (($handle = fopen($species_csv, "r")) !== FALSE) {
  // Skip header row.
  fgetcsv($handle);

  while (($row = fgetcsv($handle)) !== FALSE) {
    $species_id = $row[0];
    $code = $row[1];
    $group_id = $row[2];
    $name = $row[3];

    // Skip if no group found.
    if (!isset($species_groups[$group_id])) {
      print("Warning: No group found for species {$name} (ID: {$species_id})\n");
      continue;
    }

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
        $file = \Drupal::entityTypeManager()
          ->getStorage('file')
          ->create([
            'uri' => $uri,
            'filename' => $filename,
            'filemime' => 'video/mp4',
            'filesize' => file_exists($video_destination . $filename) ? filesize($video_destination . $filename) : 0,
            'status' => 1,
            'uid' => 1,
          ]);
        $file->save();
        print("Created file entry for: {$filename}\n");
      }
      else {
        $file = reset($files);
        print("Found existing file: {$filename}\n");
      }
    }

    // Create or update species term.
    $terms = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadByProperties([
        'vid' => 'species',
        'field_species_id' => $species_id,
      ]);

    if (empty($terms)) {
      $term = \Drupal::entityTypeManager()
        ->getStorage('taxonomy_term')
        ->create([
          'vid' => 'species',
          'name' => $name,
          'field_species_id' => $species_id,
          'field_species_code' => $code,
          'field_species_group' => ['target_id' => $species_groups[$group_id]->id()],
        ]);

      if ($file) {
        $term->set('field_species_video', ['target_id' => $file->id()]);
      }

      $term->save();
      print("Created species term: {$name} (ID: {$species_id})\n");
    }
    else {
      $term = reset($terms);
      $term->set('name', $name);
      $term->set('field_species_code', $code);
      $term->set('field_species_group', ['target_id' => $species_groups[$group_id]->id()]);

      if ($file) {
        $term->set('field_species_video', ['target_id' => $file->id()]);
      }

      $term->save();
      print("Updated species term: {$name} (ID: {$species_id})\n");
    }
  }

  fclose($handle);
}

// Report missing files if any.
if (!empty($missing_files)) {
  print("\nWARNING: The following video files need to be downloaded:\n");
  foreach ($missing_files as $file) {
    print("  - {$file}\n");
  }
  print("\nPlease download these files to web/sites/aerial/files/videos/ when ready.\n");
  print("Total files to download: " . count($missing_files) . "\n");
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

print("\nImport complete:\n");
print("- Species Groups: {$group_count}\n");
print("- Species: {$species_count}\n");
print("- Video Files: {$file_count}\n");
