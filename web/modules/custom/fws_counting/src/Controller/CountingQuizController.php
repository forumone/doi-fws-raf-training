<?php

namespace Drupal\fws_counting\Controller;

use Drupal\Core\Controller\ControllerBase;

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

    // Check if ANY size range is selected
    foreach ($size_terms as $term) {
      if ($term->get('field_size_range_id')->value == 5) {
        $valid_ranges = TRUE;
        $has_any_range = TRUE;
        break;
      }
    }

    if (!$has_any_range) {
      // If ANY wasn't selected, process normal range conditions
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

          // Only add max condition if it exists
          if ($max) {
            $range_group->condition('field_bird_count', $max, '<=');
          }

          $group->condition($range_group);
        }
      }

      // Only add the group condition if we have valid ranges
      if ($valid_ranges) {
        $query->condition($group);
      }
    }

    if (!$valid_ranges) {
      return [
        '#markup' => $this->t('No valid size ranges found with min and max values.'),
      ];
    }

    // Execute query and load media entities.
    $media_ids = $query->execute();

    // Debug: Log the query conditions and results.
    \Drupal::logger('fws_counting')->notice('Query found @count media items. Media IDs: @ids', [
      '@count' => count($media_ids),
      '@ids' => print_r($media_ids, TRUE),
    ]);

    $media_entities = $this->entityTypeManager()
      ->getStorage('media')
      ->loadMultiple($media_ids);

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
            '#style_name' => 'medium',
            '#uri' => $media->field_image->entity->getFileUri(),
          ],
          'bird_count' => $media->get('field_bird_count')->value,
        ];
      }
    }

    // Debug: Log the final number of images.
    \Drupal::logger('fws_counting')->notice('Final number of processed images: @count', [
      '@count' => count($images),
    ]);

    // Return the themed output.
    return [
      '#theme' => 'counting_quiz',
      '#experience_level' => $experience_term->label(),
      '#size_ranges' => array_map(function ($term) {
        return $term->label();
      }, $size_terms),
      '#images' => $images,
    ];
  }

}
