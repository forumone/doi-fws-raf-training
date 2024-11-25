<?php

/**
 * @file
 * Drush script to import data into research_project content type.
 *
 * Usage: drush scr scripts/import_research_project.php.
 */

use Drupal\Core\Entity\EntityStorageException;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;

$csv_file = '../scripts/data/T_Research_Proj.csv';
if (!file_exists($csv_file)) {
  exit('CSV file not found at: ' . $csv_file);
}

// Initialize counters.
$row_count = 0;
$created_count = 0;
$updated_count = 0;
$error_count = 0;

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
    // CSV columns: ProjectID, Title, Subject, PrimResCat, SecResCat, PermitType, PermitNum, ExpireDate, Desc, PIID, FundOrg, BudgetTime, Budget, StartDate, EndDate, Location, CreateBy, CreateDate, UpdateBy, UpdateDate.
    [$project_id, $title, $subject, $prim_res_cat, $sec_res_cat, $permit_type, $permit_num, $expire_date, $desc, $pi_id, $fund_org, $budget_time, $budget, $start_date, $end_date, $location, $create_by, $create_date, $update_by, $update_date] = $data;

    // Check if research_project node already exists.
    $existing_nodes = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadByProperties([
        'type' => 'research_project',
        'field_project_id' => $project_id,
      ]);

    // Prepare node data.
    $node_data = [
      'type' => 'research_project',
      'title' => $title,
      'field_project_id' => $project_id,
      'field_subject' => $subject,
      'field_primary_research' => [
        'target_id' => get_research_category_term_id($prim_res_cat),
      ],
      'field_secondary_research' => [
        'target_id' => get_research_category_term_id($sec_res_cat),
      ],
      'field_permit_type' => $permit_type,
      'field_permit_number' => $permit_num,
      // ISO 8601 format.
      'field_expire_date' => parse_date($expire_date, FALSE),
      'field_description' => $desc,
      'field_pi' => [
        'target_id' => get_pi_node_id($pi_id),
      ],
      'field_fund_org' => $fund_org,
      'field_budget_time' => [
        'target_id' => get_budget_cycle_term_id($budget_time),
      ],
      'field_budget' => $budget,
      // ISO 8601 format.
      'field_start_date' => parse_date($start_date, FALSE),
      // ISO 8601 format.
      'field_end_date' => parse_date($end_date, FALSE),
      'field_location' => $location,
      // UNIX timestamp.
      'created' => parse_date($create_date, TRUE),
      // UNIX timestamp.
      'changed' => parse_date($update_date, TRUE),
      'status' => 1,
    ];

    if (!empty($existing_nodes)) {
      // Update existing node.
      $node = reset($existing_nodes);
      foreach ($node_data as $field => $value) {
        $node->set($field, $value);
      }
      print("\nUpdating existing research_project node: $project_id");
      $updated_count++;
    }
    else {
      // Create new node.
      $node_data['uid'] = get_user_id($create_by);
      $node = Node::create($node_data);
      print("\nCreating new research_project node: $project_id");
      $created_count++;
    }

    $node->save();

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
print("\nNewly created: $created_count");
print("\nUpdated: $updated_count");
print("\nErrors: $error_count\n");

/**
 * Helper function to parse and format date values.
 * Returns UNIX timestamp by default, or ISO 8601 if specified.
 */
function parse_date($date_value, $as_timestamp = TRUE) {
  try {
    if (empty($date_value)) {
      return NULL;
    }
    $date = \DateTime::createFromFormat('Y-m-d H:i:s.u', $date_value);
    if ($date === FALSE) {
      $date = \DateTime::createFromFormat('Y-m-d H:i:s', $date_value);
    }
    if ($date !== FALSE) {
      return $as_timestamp ? $date->getTimestamp() : $date->format('Y-m-d\TH:i:s');
    }
    print("\nError parsing date: $date_value");
    return NULL;
  }
  catch (Exception $e) {
    print("\nException while parsing date: " . $e->getMessage());
    return NULL;
  }
}

/**
 * Helper function to get Research Category taxonomy term ID.
 */
function get_research_category_term_id($category_name) {
  if (empty($category_name)) {
    return NULL;
  }

  $terms = \Drupal::entityTypeManager()
    ->getStorage('taxonomy_term')
    ->loadByProperties([
      'vid' => 'research_cat',
      'name' => $category_name,
    ]);

  if (!empty($terms)) {
    return reset($terms)->id();
  }

  $term = Term::create([
    'vid' => 'research_cat',
    'name' => $category_name,
  ]);
  $term->save();
  return $term->id();
}

/**
 * Helper function to get Principal Investigator (PI) node ID.
 */
function get_pi_node_id($pi_id) {
  $nodes = \Drupal::entityTypeManager()
    ->getStorage('node')
    ->loadByProperties([
      'type' => 'pi',
      'field_pi_id' => $pi_id,
    ]);

  if (!empty($nodes)) {
    return reset($nodes)->id();
  }

  print("\nError: PI node with ID $pi_id not found.");
  return NULL;
}

/**
 * Helper function to get Budget Cycle taxonomy term ID.
 */
function get_budget_cycle_term_id($budget_cycle) {
  if (empty($budget_cycle)) {
    return NULL;
  }

  $terms = \Drupal::entityTypeManager()
    ->getStorage('taxonomy_term')
    ->loadByProperties([
      'vid' => 'budget_time',
      'name' => $budget_cycle,
    ]);

  if (!empty($terms)) {
    return reset($terms)->id();
  }

  $term = Term::create([
    'vid' => 'budget_time',
    'name' => $budget_cycle,
  ]);
  $term->save();
  return $term->id();
}

/**
 * Helper function to get User ID by username.
 */
function get_user_id($username) {
  $users = \Drupal::entityTypeManager()
    ->getStorage('user')
    ->loadByProperties(['name' => $username]);

  if (!empty($users)) {
    return reset($users)->id();
  }
  // Default user ID.
  return 1;
}
