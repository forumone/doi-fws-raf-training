<?php

/**
 * @file
 * Import test data from CSV files into Drupal nodes.
 */

use Drupal\node\Entity\Node;
use Drupal\field\Entity\FieldConfig;
use Drupal\file\Entity\File;
use Drush\Drush;
use Drupal\paragraphs\Entity\Paragraph;

/**
 * Update parse_csv_file to include error handling.
 */
function parse_csv_file($filepath) {
  if (!file_exists($filepath)) {
    echo "Error: File not found at {$filepath}\n";
    return [];
  }

  try {
    $rows = array_map('str_getcsv', file($filepath));
    if (empty($rows)) {
      echo "Warning: No data found in {$filepath}\n";
      return [];
    }

    $header = array_shift($rows);
    echo "Found " . count($rows) . " rows in {$filepath}\n";
    $data = [];
    foreach ($rows as $row) {
      $data[] = array_combine($header, $row);
    }
    return $data;
  }
  catch (Exception $e) {
    echo "Error parsing CSV: " . $e->getMessage() . "\n";
    return [];
  }
}

/**
 * Function to get all parameters for a test ID.
 */
function get_test_parameters($params_data, $test_id) {
  $test_params = [];
  $param_count = 0;
  foreach ($params_data as $param) {
    if ($param['TEST_ID'] == $test_id) {
      $param_count++;
      echo "DEBUG: Found parameter for Test {$test_id}: {$param['PARAM_NAME']} = {$param['PARAM_VALUE']}\n";
      $param_name = $param['PARAM_NAME'];
      $param_value = $param['PARAM_VALUE'];

      // Handle multiple values for same parameter.
      if (!isset($test_params[$param_name])) {
        $test_params[$param_name] = [];
      }
      $test_params[$param_name][] = $param_value;
    }
  }
  echo "DEBUG: Total parameters found for Test {$test_id}: {$param_count}\n";
  return $test_params;
}

/**
 * Function to get test data from TEST.csv.
 */
function get_test_answers($test_file, $detail_file) {
  $answers = [];

  // First get basic test info.
  $test_data = parse_csv_file($test_file);
  foreach ($test_data as $test) {
    $test_id = $test['TEST_ID'];
    $answers[$test_id] = [
      'test_type' => $test['TEST_TYPE'],
      'test_date' => $test['TEST_DATE'],
      'user_id' => $test['USER_ID'],
      'details' => [],
    ];
  }

  // Then get detailed answers.
  $detail_data = parse_csv_file($detail_file);
  $detail_counts = [];
  foreach ($detail_data as $detail) {
    $test_id = $detail['TEST_ID'];
    if (isset($answers[$test_id])) {
      if (!isset($detail_counts[$test_id])) {
        $detail_counts[$test_id] = 0;
      }
      $detail_counts[$test_id]++;

      if ($test_id == 19) {
        echo "DEBUG: Found detail for Test 19: param={$detail['TEST_PARAM']}, expected={$detail['EXPECTED_VALUE']}, answer={$detail['ANSWER_VALUE']}, file={$detail['FILE_ID']}\n";
      }

      $answers[$test_id]['details'][] = [
        'file_id' => $detail['FILE_ID'],
        'test_param' => $detail['TEST_PARAM'],
        'expected_value' => $detail['EXPECTED_VALUE'],
        'answer_value' => $detail['ANSWER_VALUE'],
      ];
    }
  }

  foreach ($detail_counts as $test_id => $count) {
    echo "DEBUG: Test {$test_id} has {$count} details in TEST_DETAIL.csv\n";
  }

  return $answers;
}

/**
 * Function to get taxonomy term ID by name and vocabulary.
 */
function get_term_id_by_name($name, $vocabulary) {
  $terms = \Drupal::entityTypeManager()
    ->getStorage('taxonomy_term')
    ->loadByProperties([
      'name' => $name,
      'vid' => $vocabulary,
    ]);

  if (!empty($terms)) {
    return reset($terms)->id();
  }

  return NULL;
}

/**
 * Function to map difficulty level to taxonomy term ID.
 */
function map_difficulty_level($level, $is_counting = TRUE) {
  $map = [
    'Beginner/Refresher (10 seconds to view image)' => 1,
    'Beginner/Refresher (10 seconds video)' => 1,
    'Moderate (6 seconds to view image)' => 2,
    'Moderate (5 seconds video)' => 2,
    'Challenging (3 seconds to view image)' => 3,
    'Challenging (3 seconds video)' => 3,
  ];

  $difficulty_id = $map[$level] ?? NULL;
  if ($difficulty_id) {
    $vocabulary = $is_counting ? 'species_counting_difficulty' : 'species_id_difficulty';
    $terms = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadByProperties([
        'vid' => $vocabulary,
        'field_difficulty_level' => $difficulty_id,
      ]);
    if (!empty($terms)) {
      return reset($terms)->id();
    }
  }
  return NULL;
}

/**
 * Function to map range to taxonomy term ID.
 */
function map_range($range) {
  $map = [
    '<100' => 'Under 100',
    '100-500' => '100-500',
    '500-3,000' => '500-3000',
    '>3,000 (images always displayed for 15 secs)' => 'Over 3000',
    'ANY' => 'Any',
  ];

  $term_name = $map[$range] ?? '';
  if ($term_name) {
    return get_term_id_by_name($term_name, 'count_ranges');
  }
  return NULL;
}

/**
 * Function to validate configurations.
 */
function validate_configurations() {
  $required_content_types = [
    'species_counting_results' => [
      'fields' => [
        'field_count_difficulty',
        'field_count_questions',
        'field_average_count_accuracy',
      ],
    ],
    'species_id_results' => [
      'fields' => [
        'field_id_difficulty',
        'field_id_questions',
        'field_species_group',
        'field_region',
        'field_test_score',
      ],
    ],
  ];

  $required_taxonomies = [
    'species_counting_difficulty' => [
      1 => 'Beginner',
      2 => 'Moderate',
      3 => 'Challenging',
    ],
    'species_id_difficulty' => [
      1 => 'Beginner',
      2 => 'Moderate',
      3 => 'Challenging',
    ],
    'species_group' => [
      'Dabbling Ducks',
      'Diving Ducks',
      'Sea Ducks',
      'Geese, Swans and Cranes',
      'Whistling Ducks',
      'Other Non-waterfowl (video only; not narrated)',
      'ALL species',
    ],
    'geographic_region' => [
      'Alaska',
      'Arctic Tundra',
      'Boreal and Taiga',
      'Prairies and Parklands',
      'Pacific Coastal',
      'Intermountain West',
      'NE U.S. and Eastern Canada',
      'Atlantic Coastal',
      'Southeast U.S.',
      'ALL Regions and Habitats',
    ],
  ];

  $errors = [];

  // Check content types and their fields.
  foreach ($required_content_types as $type => $config) {
    if (!\Drupal::entityTypeManager()->getStorage('node_type')->load($type)) {
      $errors[] = "Missing content type: $type";
      continue;
    }

    foreach ($config['fields'] as $field) {
      if (!FieldConfig::loadByName('node', $type, $field)) {
        $errors[] = "Missing field $field on content type $type";
      }
    }
  }

  // Check taxonomies and their terms.
  $term_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
  foreach ($required_taxonomies as $vocab => $terms) {
    if (!\Drupal::entityTypeManager()->getStorage('taxonomy_vocabulary')->load($vocab)) {
      $errors[] = "Missing vocabulary: $vocab";
      continue;
    }

    if ($vocab === 'species_counting_difficulty' || $vocab === 'species_id_difficulty') {
      // For difficulty vocabularies, check by difficulty level ID.
      foreach ($terms as $id => $term) {
        if (!$term_storage->loadByProperties(['vid' => $vocab, 'field_difficulty_level' => $id])) {
          $errors[] = "Missing difficulty level {$id} ({$term}) in vocabulary '{$vocab}'";
        }
      }
    }
    else {
      // For other vocabularies, check by name.
      foreach ($terms as $term) {
        if (!get_term_id_by_name($term, $vocab)) {
          $errors[] = "Missing term '$term' in vocabulary '$vocab'";
        }
      }
    }
  }

  return $errors;
}

/**
 * Function to get file metadata.
 */
function get_file_metadata($type = 'photo') {
  $metadata = [];
  $filepath = __DIR__ . '/data/' . ($type === 'photo' ? 'PHOTO_FILE_METADATA.csv' : 'VIDEO_FILE_METADATA.csv');

  if (!file_exists($filepath)) {
    echo "Warning: Metadata file not found: {$filepath}\n";
    return [];
  }

  $data = parse_csv_file($filepath);
  foreach ($data as $row) {
    $file_id = $row['FILE_ID'];
    $metadata[$file_id] = $row;
  }

  return $metadata;
}

/**
 * Function to get media entity by filename.
 *
 * @param string $file_id
 *   The file ID to look up.
 * @param string $type
 *   The type of media entity ('image' or 'video').
 * @param array $metadata
 *   Optional metadata array for video lookups.
 *
 * @return int|null
 *   The media entity ID if found, NULL otherwise.
 */
function get_media_entity($file_id, $type = 'image', array $metadata = []) {
  $media_storage = \Drupal::entityTypeManager()->getStorage('media');

  if ($type === 'video') {
    // Look up the actual filename from metadata.
    if (!isset($metadata[$file_id])) {
      echo "Warning: No metadata found for video file ID {$file_id}\n";
      return NULL;
    }

    // Get the filename from metadata and construct the full filename.
    $base_filename = $metadata[$file_id]['FILE_NAME'];
    if (!$base_filename) {
      echo "Warning: No filename found in metadata for file ID {$file_id}\n";
      return NULL;
    }

    $filename = $base_filename . '_2030kbps.mp4';
    $uri = 'public://videos/test/' . $filename;

    echo "DEBUG: Looking for video file with URI: " . $uri . "\n";

    $files = \Drupal::entityTypeManager()
      ->getStorage('file')
      ->loadByProperties(['uri' => $uri]);

    if (empty($files)) {
      \Drupal::logger('aerial_import')->warning('No file entity found with URI ' . $uri);
      return NULL;
    }

    $file = reset($files);
    $file_id = $file->id();

    echo "DEBUG: Found file entity with ID: " . $file_id . "\n";

    // Now find the media entity that references this file.
    $query = \Drupal::entityQuery('media')
      ->condition('bundle', 'species_video')
      ->condition('field_video_file.target_id', $file_id)
      ->accessCheck(FALSE);

    $media_ids = $query->execute();

    if (empty($media_ids)) {
      \Drupal::logger('aerial_import')->warning('No media entity found referencing file ' . $filename);
      return NULL;
    }

    $media_id = reset($media_ids);
    echo "DEBUG: Found media entity with ID: " . $media_id . "\n";
    return $media_id;
  }
  else {
    // Photos use direct file ID lookup from metadata.
    $query = $media_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('bundle', $type)
      ->condition('name', $file_id)
      ->range(0, 1);
  }

  $media_ids = $query->execute();
  if (!empty($media_ids)) {
    $media_id = reset($media_ids);
    echo "DEBUG: Found existing media entity {$media_id} for file ID {$file_id}\n";
    return $media_id;
  }

  echo "Warning: No media entity found for file ID {$file_id}\n";
  return NULL;
}

/**
 * Function to find existing test node by test ID.
 */
function find_existing_test_node($test_id, $content_type) {
  $query = \Drupal::entityTypeManager()
    ->getStorage('node')
    ->getQuery()
    ->accessCheck(FALSE)
    ->condition('type', $content_type)
    ->condition('title', 'Test ' . $test_id)
    ->range(0, 1);

  $nids = $query->execute();
  if (!empty($nids)) {
    $nid = reset($nids);
    echo "Found existing node {$nid} for Test {$test_id}\n";
    return Node::load($nid);
  }

  return NULL;
}

/**
 * Main import logic.
 *
 * @param int|null $limit
 *   Optional maximum number of tests to import.
 */
function import_test_data($limit = NULL) {
  if ($limit !== NULL) {
    echo "Import will be limited to {$limit} tests\n";
  }

  echo "Starting configuration validation...\n";

  // Validate configurations first.
  $errors = validate_configurations();
  if (!empty($errors)) {
    echo "Configuration errors found:\n";
    foreach ($errors as $error) {
      echo "- $error\n";
    }
    echo "Please fix these issues before running the import.\n";
    return;
  }

  echo "Configuration valid.\n";

  // Load CSV files with absolute paths.
  $script_dir = dirname(__FILE__);
  $param_file = $script_dir . '/data/TEST_PARAM.csv';
  $test_file = $script_dir . '/data/TEST.csv';
  $detail_file = $script_dir . '/data/TEST_DETAIL.csv';

  echo "Looking for parameter file at: {$param_file}\n";
  echo "Looking for test file at: {$test_file}\n";

  $params_data = parse_csv_file($param_file);
  if (empty($params_data)) {
    echo "Error: No parameter data found. Aborting import.\n";
    return;
  }

  $test_answers = get_test_answers($test_file, $detail_file);
  if (empty($test_answers)) {
    echo "Error: No test data found. Aborting import.\n";
    return;
  }

  echo "Found " . count($test_answers) . " tests with details\n";

  // Load file metadata.
  $photo_metadata = get_file_metadata('photo');
  $video_metadata = get_file_metadata('video');

  // Get test IDs directly from TEST.csv.
  $test_data = parse_csv_file($test_file);
  $test_ids = array_values(array_unique(array_column($test_data, 'TEST_ID')));
  echo "Found " . count($test_ids) . " unique test IDs\n";

  // Limit test IDs if needed.
  if ($limit !== NULL) {
    $test_ids = array_slice($test_ids, 0, $limit);
    echo "Processing exactly {$limit} tests (including skipped tests)\n";
  }

  // Process each test.
  $processed_count = 0;
  $successful_imports = 0;

  foreach ($test_ids as $test_id) {
    $params = get_test_parameters($params_data, $test_id);
    $answer_data = $test_answers[$test_id] ?? NULL;

    // Enhanced logging for skipped tests.
    $skip_reasons = [];
    if (empty($params)) {
      $skip_reasons[] = "no parameters found in TEST_PARAM.csv";
      // Check if test_id exists in TEST_PARAM.csv at all.
      $param_exists = FALSE;
      foreach ($params_data as $param) {
        if ($param['TEST_ID'] == $test_id) {
          $param_exists = TRUE;
          break;
        }
      }
      if (!$param_exists) {
        echo "  Note: Test ID {$test_id} not found in TEST_PARAM.csv at all\n";
      }
      else {
        echo "  Note: Test ID {$test_id} exists in TEST_PARAM.csv but get_test_parameters() returned empty\n";
      }
    }
    else {
      echo "  Found parameters in TEST_PARAM.csv: " . implode(", ", array_keys($params)) . "\n";
    }

    if (empty($answer_data)) {
      $skip_reasons[] = "no test data found in TEST.csv";
      // Check if test_id exists in TEST.csv.
      $test_exists = FALSE;
      foreach ($test_data as $test) {
        if ($test['TEST_ID'] == $test_id) {
          $test_exists = TRUE;
          echo "  Found in TEST.csv with type: {$test['TEST_TYPE']}, date: {$test['TEST_DATE']}\n";
          break;
        }
      }
      if (!$test_exists) {
        echo "  Note: Test ID {$test_id} not found in TEST.csv at all\n";
      }
    }
    else {
      echo "  Found in TEST.csv - type: {$answer_data['test_type']}, date: {$answer_data['test_date']}, user: {$answer_data['user_id']}\n";
    }

    if (empty($answer_data['details'])) {
      $skip_reasons[] = "no test details found in TEST_DETAIL.csv";
      // Count details in TEST_DETAIL.csv for this test.
      $detail_count = 0;
      foreach ($detail_data as $detail) {
        if ($detail['TEST_ID'] == $test_id) {
          $detail_count++;
          echo "  Found detail: param={$detail['TEST_PARAM']}, expected={$detail['EXPECTED_VALUE']}, answer={$detail['ANSWER_VALUE']}\n";
        }
      }
      echo "  Total details found in TEST_DETAIL.csv: {$detail_count}\n";
    }
    else {
      echo "  Found " . count($answer_data['details']) . " details in TEST_DETAIL.csv\n";
      foreach ($answer_data['details'] as $idx => $detail) {
        echo "  Detail " . ($idx + 1) . ": param={$detail['test_param']}, expected={$detail['expected_value']}, answer={$detail['answer_value']}\n";
      }
    }

    if (!empty($skip_reasons)) {
      echo "Skipping test {$test_id} - " . implode(", ", $skip_reasons) . "\n";
      echo "----------------------------------------\n";
      $processed_count++;
      continue;
    }

    echo "Processing test {$test_id} - {$answer_data['test_type']}\n";

    // Determine content type based on test type.
    $is_counting = ($answer_data['test_type'] === 'PHOTO');
    $content_type = $is_counting ? 'species_counting_results' : 'species_id_results';

    // Try to find existing node.
    $node = find_existing_test_node($test_id, $content_type);
    if (!$node) {
      // Create new node if none exists.
      $node = Node::create([
        'type' => $content_type,
        'title' => 'Test ' . $test_id,
        'status' => 1,
      ]);
      echo "Creating new node for Test {$test_id}\n";
    }
    else {
      echo "Updating existing node for Test {$test_id}\n";
      // Clear existing field values.
      if ($is_counting) {
        $node->set('field_count_questions', []);
      }
      else {
        $node->set('field_id_questions', []);
        $node->set('field_species_group', []);
        $node->set('field_region', []);
      }
    }

    // Set difficulty level using correct field.
    if (isset($params['LEVEL'][0])) {
      $difficulty_tid = map_difficulty_level($params['LEVEL'][0], $is_counting);
      if ($difficulty_tid) {
        $field_name = $is_counting ? 'field_count_difficulty' : 'field_id_difficulty';
        $node->set($field_name, ['target_id' => $difficulty_tid]);
      }
    }

    if ($is_counting) {
      // Set count ranges.
      $range_tids = [];
      foreach ($params['RANGE'] as $range) {
        $range_tid = map_range($range);
        if ($range_tid) {
          $range_tids[] = ['target_id' => $range_tid];
        }
      }

      // Process count questions.
      $count_questions = [];
      $total_count_accuracy = 0;
      $question_count = 0;
      foreach ($answer_data['details'] as $detail) {
        if ($detail['test_param'] === 'COUNT') {
          $expected = (float) $detail['expected_value'];
          $actual = (float) $detail['answer_value'];

          // Calculate accuracy as a percentage difference from expected
          // Negative means undercount, positive means overcount.
          $accuracy = 0;
          if ($expected > 0) {
            $accuracy = (($actual - $expected) / $expected) * 100;
          }

          echo "DEBUG: Creating count question for Test {$test_id}: file={$detail['file_id']}, expected={$expected}, answer={$actual}, accuracy={$accuracy}%\n";

          // Create a paragraph entity for each count question.
          $paragraph = Paragraph::create([
            'type' => 'species_count_question',
            'field_count_media_reference' => ['target_id' => $detail['file_id']],
            'field_expected_count' => $expected,
            'field_user_count' => $actual,
            'field_count_accuracy' => $accuracy,
          ]);
          $paragraph->save();

          $count_questions[] = [
            'target_id' => $paragraph->id(),
            'target_revision_id' => $paragraph->getRevisionId(),
          ];

          $total_count_accuracy += $accuracy;
          $question_count++;
        }
      }

      // Set count questions and average accuracy.
      if (!empty($count_questions)) {
        echo "DEBUG: Setting " . count($count_questions) . " count questions for Test {$test_id}\n";
        $node->set('field_count_questions', $count_questions);

        // Calculate and set average accuracy.
        if ($question_count > 0) {
          $average_accuracy = $total_count_accuracy / $question_count;
          echo "DEBUG: Setting average count accuracy for Test {$test_id}: {$average_accuracy}%\n";
          $node->set('field_average_count_accuracy', $average_accuracy);
        }
      }

    }
    else {
      // Set species groups and regions.
      if (isset($params['GROUP'])) {
        $group_terms = [];
        foreach ($params['GROUP'] as $group) {
          $tid = get_term_id_by_name($group, 'species_group');
          if ($tid) {
            $group_terms[] = ['target_id' => $tid];
          }
        }
        if (!empty($group_terms)) {
          $node->set('field_species_group', $group_terms);
        }
      }

      if (isset($params['REGION'])) {
        $region_terms = [];
        foreach ($params['REGION'] as $region) {
          $tid = get_term_id_by_name($region, 'geographic_region');
          if ($tid) {
            $region_terms[] = ['target_id' => $tid];
          }
        }
        if (!empty($region_terms)) {
          $node->set('field_region', $region_terms);
        }
      }

      // Link to existing species video using file ID.
      if (!empty($answer_data['video_path'])) {
        $file_id = basename($answer_data['video_path']);
        if (isset($video_metadata[$file_id])) {
          $media_id = get_media_entity($file_id, 'video', $video_metadata);
          if ($media_id) {
            $node->set('field_species_video', ['target_id' => $media_id]);
          }
        }
      }

      // Process species identification questions.
      $id_questions = [];
      foreach ($answer_data['details'] as $detail) {
        if ($detail['test_param'] === 'SPECIES') {
          $expected_species_tid = get_term_id_by_name($detail['expected_value'], 'species');
          $answer_species_tid = get_term_id_by_name($detail['answer_value'], 'species');

          echo "DEBUG: Creating species ID question for Test {$test_id}: file={$detail['file_id']}, expected={$detail['expected_value']}, answer={$detail['answer_value']}\n";

          // Get media entity for video file.
          $media_id = get_media_entity($detail['file_id'], 'video', $video_metadata);

          // Create a paragraph entity for each species ID question.
          $paragraph = Paragraph::create([
            'type' => 'species_id_question',
            'field_media_reference' => ['target_id' => $media_id],
            'field_user_species_selection' => ['target_id' => $answer_species_tid],
            'field_expected_species' => ['target_id' => $expected_species_tid],
          ]);
          $paragraph->save();

          $id_questions[] = [
            'target_id' => $paragraph->id(),
            'target_revision_id' => $paragraph->getRevisionId(),
          ];
        }
      }

      if (!empty($id_questions)) {
        echo "DEBUG: Setting " . count($id_questions) . " ID questions for Test {$test_id}\n";
        $node->set('field_id_questions', $id_questions);
      }
    }

    try {
      $node->save();
      $action = $node->isNew() ? "Created" : "Updated";
      echo "{$action} {$content_type} node for Test {$test_id}\n";
      $processed_count++;
      $successful_imports++;
    }
    catch (Exception $e) {
      echo "Error saving node for Test {$test_id}: " . $e->getMessage() . "\n";
      $processed_count++;
    }
  }

  echo "Processed {$processed_count} tests total ({$successful_imports} successful, " . ($processed_count - $successful_imports) . " skipped)\n";
}

// Get the limit from command line argument.
$limit = NULL;
$input = Drush::input();
$args = $input->getArguments();

// In DDEV environment, the limit will be in the 'extra' array.
if (isset($args['extra']) && count($args['extra']) > 1) {
  $limit = (int) $args['extra'][1];
  if ($limit <= 0) {
    echo "Warning: Invalid limit value. Will import all tests.\n";
    $limit = NULL;
  }
  else {
    echo "Will import up to {$limit} tests.\n";
  }
}

try {
  echo "Starting import process...\n";
  import_test_data($limit);
  echo "Import complete!\n";
}
catch (Exception $e) {
  echo "Fatal error during import: " . $e->getMessage() . "\n";
  echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
