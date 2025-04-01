<?php

namespace Drupal\fws_counting\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for the counting quiz page.
 */
class CountingQuizController extends ControllerBase {

  /**
   * Displays the quiz page.
   *
   * @return array
   *   A render array for the debug page.
   */
  public function display($experience_level, $size_range) {
    // The experience_level parameter is already a loaded term entity due to the route parameter conversion.
    $experience_term = $experience_level;

    // Convert the size_range string parameter back to an array and load terms.
    $size_range_ids = explode(',', $size_range);
    $size_terms = $this->entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadMultiple($size_range_ids);

    // Build the query conditions for the size ranges.
    $query = $this->entityTypeManager()->getStorage('media')->getQuery()
      ->accessCheck(TRUE)
      ->condition('bundle', 'species_image')
      ->condition('status', 1)
      ->range(0, 10);

    // Validate we have valid size terms.
    if (empty($size_terms)) {
      return [
        '#markup' => $this->t('No valid size ranges provided.'),
      ];
    }

    // Build the size range conditions.
    $valid_ranges = FALSE;
    $has_any_range = FALSE;

    // Check if ANY size range is selected.
    foreach ($size_terms as $term) {
      if ($term->get('field_size_range_id')->value == 5) {
        $valid_ranges = TRUE;
        $has_any_range = TRUE;
        break;
      }
    }

    if (!$has_any_range) {
      // If ANY wasn't selected, process normal range conditions.
      $group = $query->orConditionGroup();
      foreach ($size_terms as $term) {
        $min = $term->get('field_size_range_min')->value;
        $max = $term->get('field_size_range_max')->value;

        // Debug: Log size range information.
        \Drupal::logger('fws_counting')->notice('Processing size range term @id: min=@min, max=@max', [
          '@id' => $term->id(),
          '@min' => $min,
          '@max' => $max,
        ]);

        if (isset($min)) {
          $valid_ranges = TRUE;
          $range_group = $query->andConditionGroup()
            ->condition('field_bird_count', $min, '>=');

          // Only add max condition if it exists.
          if ($max) {
            $range_group->condition('field_bird_count', $max, '<=');
          }

          $group->condition($range_group);
        }
      }

      // Only add the group condition if we have valid ranges.
      if ($valid_ranges) {
        $query->condition($group);
      }
    }

    if (!$valid_ranges) {
      return [
        '#markup' => $this->t('No valid size ranges found with min and max values.'),
      ];
    }

    if ($has_any_range) {
      // For ANY range, just get random items.
      $query->range(0, 10);
      $media_ids = $query->execute();
      $media_entities = $this->entityTypeManager()
        ->getStorage('media')
        ->loadMultiple($media_ids);
    }
    else {
      // For multiple ranges, get items from each range.
      $media_by_range = [];
      $total_needed = 10;

      foreach ($size_terms as $term) {
        // Skip if not a valid range term.
        if (!isset($term->get('field_size_range_min')->value)) {
          continue;
        }

        // Create a query for this specific range.
        $range_query = $this->entityTypeManager()->getStorage('media')->getQuery()
          ->accessCheck(TRUE)
          ->condition('bundle', 'species_image')
          ->condition('status', 1);

        $min = $term->get('field_size_range_min')->value;
        $max = $term->get('field_size_range_max')->value;

        $range_group = $range_query->andConditionGroup()
          ->condition('field_bird_count', $min, '>=');
        if ($max) {
          $range_group->condition('field_bird_count', $max, '<=');
        }
        $range_query->condition($range_group);

        // Get all media IDs for this range.
        $range_media_ids = $range_query->execute();
        if (!empty($range_media_ids)) {
          $media_by_range[$term->id()] = $range_media_ids;
        }
      }

      // Calculate how many items we need from each range.
      $num_ranges = count($media_by_range);
      if ($num_ranges > 0) {
        $items_per_range = floor($total_needed / $num_ranges);
        $remainder = $total_needed % $num_ranges;

        // Get random items from each range.
        $selected_media_ids = [];
        foreach ($media_by_range as $term_id => $range_ids) {
          $num_to_get = $items_per_range + ($remainder > 0 ? 1 : 0);
          $remainder--;

          // Randomly select items from this range.
          $range_ids_array = array_values($range_ids);
          shuffle($range_ids_array);
          $selected_media_ids = array_merge(
            $selected_media_ids,
            array_slice($range_ids_array, 0, min($num_to_get, count($range_ids_array)))
          );
        }

        // If we still need more items, get them from any range.
        if (count($selected_media_ids) < $total_needed) {
          $all_remaining_ids = [];
          foreach ($media_by_range as $range_ids) {
            $all_remaining_ids = array_merge($all_remaining_ids, array_values($range_ids));
          }
          $all_remaining_ids = array_diff($all_remaining_ids, $selected_media_ids);
          shuffle($all_remaining_ids);
          $selected_media_ids = array_merge(
            $selected_media_ids,
            array_slice($all_remaining_ids, 0, $total_needed - count($selected_media_ids))
          );
        }

        // Shuffle the final selection.
        shuffle($selected_media_ids);

        // Debug: Log the selected media IDs.
        \Drupal::logger('fws_counting')->notice('Selected @count media items. Media IDs: @ids', [
          '@count' => count($selected_media_ids),
          '@ids' => print_r($selected_media_ids, TRUE),
        ]);

        $media_entities = $this->entityTypeManager()
          ->getStorage('media')
          ->loadMultiple($selected_media_ids);
      }
      else {
        $media_entities = [];
      }
    }

    // Prepare images for the template.
    $images = [];
    foreach ($media_entities as $media) {
      // Debug: Log media entity information.
      \Drupal::logger('fws_counting')->notice('Processing media @id: has_image=@has_image, has_file=@has_file, has_count=@has_count', [
        '@id' => $media->id(),
        '@has_image' => $media->hasField('field_image') ? 'yes' : 'no',
        '@has_file' => ($media->hasField('field_image') && $media->field_image->entity) ? 'yes' : 'no',
        '@has_count' => $media->hasField('field_bird_count') ? 'yes' : 'no',
      ]);

      // Check if the media entity has an image field and file.
      if ($media->hasField('field_image') &&
          $media->field_image->entity &&
          $media->hasField('field_bird_count')) {
        $images[] = [
          'rendered' => [
            '#theme' => 'image_style',
            '#style_name' => 'i43large',
            '#uri' => $media->field_image->entity->getFileUri(),
          ],
          'bird_count' => $media->get('field_bird_count')->value,
          'media_id' => $media->id(),
        ];
      }
    }

    // Debug: Log the final number of images.
    \Drupal::logger('fws_counting')->notice('Final number of processed images: @count', [
      '@count' => count($images),
    ]);

    // Return the themed output.
    // Prepare quiz context data.
    $quiz_context = array_map(function ($image) {
      return [
        'mediaId' => $image['media_id'],
        'birdCount' => (int) $image['bird_count'],
      ];
    }, $images);

    // Get the viewing time based on the difficulty level.
    $difficulty_level = $experience_term->get('field_difficulty_level')->value;
    $viewing_time = match ((int) $difficulty_level) {
      1 => 10,
      2 => 6,
      3 => 3,
      // Default to 6 seconds if level is not set.
      default => 6,
    };

    // Create a results node to track the quiz progress.
    $quiz_results_service = \Drupal::service('fws_counting.quiz_results');
    $results_node = $quiz_results_service->createResultsNode($experience_term, $size_terms, $images);

    return [
      '#theme' => 'counting_quiz',
      '#experience_level' => $experience_term->label(),
      '#size_ranges' => array_map(function ($term) {
        return $term->label();
      }, $size_terms),
      '#images' => $images,
      '#viewing_time' => $viewing_time,
      '#results_node_id' => $results_node->id(),
      '#base_path' => base_path(),
      '#attached' => [
        'library' => [
          'fws_counting/quiz',
        ],
        'drupalSettings' => [
          'fwsCounting' => [
            'quizContext' => $quiz_context,
            'resultsNodeId' => $results_node->id(),
            'paths' => [
              'saveAnswer' => base_path() . 'test-your-counting-skills/save-answer',
              'getResults' => base_path() . 'test-your-counting-skills/get-results/',
            ],
          ],
        ],
      ],
    ];
  }

  /**
   * Saves a quiz answer.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response.
   */
  public function saveAnswer(Request $request) {
    $content = $request->getContent();
    $data = json_decode($content, TRUE);

    // Validate required fields.
    if (empty($data['nodeId']) ||
        !isset($data['questionIndex']) ||
        !isset($data['userCount']) ||
        !isset($data['actualCount'])) {
      return new JsonResponse(
        ['success' => FALSE, 'message' => 'Missing required fields.'],
        400
      );
    }

    // Save the answer.
    $quiz_results_service = \Drupal::service('fws_counting.quiz_results');
    $success = $quiz_results_service->updateQuestionResult(
      $data['nodeId'],
      $data['questionIndex'],
      $data['userCount'],
      $data['actualCount']
    );

    if ($success) {
      return new JsonResponse(['success' => TRUE]);
    }
    else {
      return new JsonResponse(
        ['success' => FALSE, 'message' => 'Failed to save answer.'],
        500
      );
    }
  }

  /**
   * Gets the quiz results.
   *
   * @param int $node_id
   *   The node ID.
   *
   * @return array
   *   A render array for the results.
   */
  public function getResults($node_id) {
    $quiz_results_service = \Drupal::service('fws_counting.quiz_results');
    $node = $this->entityTypeManager()->getStorage('node')->load($node_id);

    if (!$node || $node->bundle() !== 'species_counting_results') {
      return [
        '#markup' => $this->t('Results not found.'),
        '#prefix' => '<div class="alert alert-warning">',
        '#suffix' => '</div>',
      ];
    }

    // Build the view of the node.
    $view_builder = $this->entityTypeManager()->getViewBuilder('node');
    $view = $view_builder->view($node, 'default');

    return $view;
  }

}
