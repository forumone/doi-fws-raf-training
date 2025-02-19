<?php

/**
 * @file
 */

use Drupal\node\Entity\Node;
use Drupal\field\Entity\FieldConfig;
use Drupal\file\Entity\File;

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
  foreach ($params_data as $param) {
    if ($param['TEST_ID'] == $test_id) {
      $param_name = $param['PARAM_NAME'];
      $param_value = $param['PARAM_VALUE'];

      // Handle multiple values for same parameter.
      if (!isset($test_params[$param_name])) {
        $test_params[$param_name] = [];
      }
      $test_params[$param_name][] = $param_value;
    }
  }
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
  foreach ($detail_data as $detail) {
    $test_id = $detail['TEST_ID'];
    if (isset($answers[$test_id])) {
      $answers[$test_id]['details'][] = [
        'file_id' => $detail['FILE_ID'],
        'test_param' => $detail['TEST_PARAM'],
        'expected_value' => $detail['EXPECTED_VALUE'],
        'answer_value' => $detail['ANSWER_VALUE'],
      ];
    }
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
      ],
    ],
    'species_id_results' => [
      'fields' => [
        'field_id_difficulty',
        'field_id_questions',
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
      'Other Non-waterfowl',
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
  $filepath = __DIR__ . '/data/' . ($type === 'photo' ? 'PHOTO_FILE_METADATA.csv' : 'VIDEO_FILE_SPECIES_CHOICE.csv');

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
 */
function get_media_entity($file_id, $type = 'image') {
  $media_storage = \Drupal::entityTypeManager()->getStorage('media');

  if ($type === 'video') {
    // Video files follow pattern: [FILE_ID]_2030kbps.mp4.
    $filename_pattern = $file_id . '_2030kbps.mp4';
    $query = $media_storage->getQuery()
      ->condition('bundle', $type)
      ->condition('name', '%' . $filename_pattern, 'LIKE')
      ->range(0, 1);
  }
  else {
    // Photos use direct file ID lookup from metadata.
    $query = $media_storage->getQuery()
      ->condition('bundle', $type)
      ->condition('name', $file_id)
      ->range(0, 1);
  }

  $media_ids = $query->execute();
  if (!empty($media_ids)) {
    $media_id = reset($media_ids);
    echo "Found existing media entity {$media_id} for file ID {$file_id}\n";
    return $media_id;
  }

  echo "Warning: No media entity found for file ID {$file_id}\n";
  return NULL;
}

/**
 * Main import logic.
 */
function import_test_data($limit = NULL) {
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

  // Get unique test IDs.
  $test_ids = array_unique(array_column($params_data, 'TEST_ID'));
  echo "Found " . count($test_ids) . " unique test IDs\n";

  // Limit test IDs if needed
  if ($limit !== NULL) {
    $test_ids = array_slice($test_ids, 0, $limit);
    echo "Limited to {$limit} test IDs\n";
  }

  // Process each test.
  $processed_count = 0;
  foreach ($test_ids as $test_id) {
    if ($limit !== NULL && $processed_count >= $limit) {
      echo "Reached import limit of {$limit} tests\n";
      break;
    }
    $params = get_test_parameters($params_data, $test_id);
    $answer_data = $test_answers[$test_id] ?? NULL;

    // Skip if no parameters or details found.
    if (empty($params) || empty($answer_data) || empty($answer_data['details'])) {
      echo "Skipping test {$test_id} - missing data\n";
      continue;
    }

    echo "Processing test {$test_id} - {$answer_data['test_type']}\n";

    // Determine content type based on test type.
    $is_counting = ($answer_data['test_type'] === 'PHOTO');
    $content_type = $is_counting ? 'species_counting_results' : 'species_id_results';

    // Create node.
    $node = Node::create([
      'type' => $content_type,
      'title' => 'Test ' . $test_id,
      'status' => 1,
    ]);

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
      if (!empty($range_tids)) {
        $node->set('field_count_ranges', $range_tids);
      }

      // Process count questions.
      $count_questions = [];
      foreach ($answer_data['details'] as $detail) {
        if ($detail['test_param'] === 'COUNT') {
          $count_questions[] = [
            'target_id' => $detail['file_id'],
            'expected_count' => $detail['expected_value'],
            'user_count' => $detail['answer_value'],
          ];
        }
      }

      // Set count questions.
      if (!empty($count_questions)) {
        $node->set('field_count_questions', $count_questions);
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
          $media_id = get_media_entity($file_id, 'video');
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

          $id_questions[] = [
            'target_id' => $detail['file_id'],
            'expected_species' => $expected_species_tid,
            'user_species' => $answer_species_tid,
          ];
        }
      }

      if (!empty($id_questions)) {
        $node->set('field_id_questions', $id_questions);
      }
    }

    try {
      $node->save();
      echo "Created {$content_type} node for Test {$test_id}\n";
      $processed_count++;
    }
    catch (Exception $e) {
      echo "Error creating node for Test {$test_id}: " . $e->getMessage() . "\n";
    }
  }

  echo "Processed {$processed_count} tests\n";
}

// Execute import with error handling.
// Get the limit from command line argument.
$limit = NULL;
$input = \Drush\Drush::input();
$args = $input->getArguments();
if (isset($args['args']) && !empty($args['args'])) {
  $limit = (int) $args['args'][0];
  echo "Will import up to {$limit} tests\n";
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
