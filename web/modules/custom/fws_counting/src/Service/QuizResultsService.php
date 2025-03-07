<?php

namespace Drupal\fws_counting\Service;

use Drupal\taxonomy\TermInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;

/**
 * Service for managing species counting quiz results.
 */
class QuizResultsService {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * Constructs a new QuizResultsService object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    AccountProxyInterface $current_user,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
  }

  /**
   * Creates a new species counting results node.
   *
   * @param \Drupal\taxonomy\TermInterface $experience_level
   *   The experience level term.
   * @param array $size_ranges
   *   The size range terms.
   * @param array $images
   *   The images used in the quiz.
   *
   * @return \Drupal\node\NodeInterface
   *   The created node.
   */
  public function createResultsNode(TermInterface $experience_level, array $size_ranges, array $images) {
    // Create a new node.
    $node = $this->entityTypeManager->getStorage('node')->create([
      'type' => 'species_counting_results',
      'title' => $this->generateTitle($experience_level),
      'uid' => $this->currentUser->id(),
      'status' => TRUE,
    ]);

    // Set the difficulty level.
    if ($experience_level instanceof TermInterface) {
      $node->set('field_count_difficulty', $experience_level->id());
    }

    // Set the size ranges.
    if (!empty($size_ranges)) {
      $size_range_ids = [];
      foreach ($size_ranges as $term) {
        if ($term instanceof TermInterface) {
          $size_range_ids[] = $term->id();
        }
      }
      $node->set('field_size_range', $size_range_ids);
    }

    // Create paragraph items for each question.
    $paragraphs = [];
    foreach ($images as $image) {
      $paragraph = Paragraph::create([
        'type' => 'species_count_question',
        'field_count_media_reference' => $image['media_id'],
      ]);
      $paragraph->save();
      $paragraphs[] = [
        'target_id' => $paragraph->id(),
        'target_revision_id' => $paragraph->getRevisionId(),
      ];
    }

    $node->set('field_count_questions', $paragraphs);
    $node->save();

    return $node;
  }

  /**
   * Updates a question in the results node.
   *
   * @param int $node_id
   *   The node ID.
   * @param int $question_index
   *   The question index (0-based).
   * @param int $user_count
   *   The user's count.
   * @param int $actual_count
   *   The actual count.
   *
   * @return bool
   *   TRUE if successful, FALSE otherwise.
   */
  public function updateQuestionResult($node_id, $question_index, $user_count, $actual_count) {
    // Load the node.
    $node = $this->entityTypeManager->getStorage('node')->load($node_id);
    if (!$node || $node->bundle() !== 'species_counting_results') {
      return FALSE;
    }

    // Get the paragraphs.
    $paragraphs = $node->get('field_count_questions')->referencedEntities();
    if (!isset($paragraphs[$question_index])) {
      return FALSE;
    }

    // Update the paragraph.
    $paragraph = $paragraphs[$question_index];
    $paragraph->set('field_user_count', $user_count);

    // Calculate accuracy.
    if ($actual_count > 0) {
      $accuracy = (($user_count - $actual_count) / $actual_count) * 100;
      $paragraph->set('field_count_accuracy', $accuracy);
    }

    $paragraph->save();

    // Update the average accuracy.
    $this->updateAverageAccuracy($node);

    return TRUE;
  }

  /**
   * Updates the average accuracy for a results node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to update.
   */
  protected function updateAverageAccuracy(NodeInterface $node) {
    $paragraphs = $node->get('field_count_questions')->referencedEntities();
    $total_accuracy = 0;
    $count = 0;

    foreach ($paragraphs as $paragraph) {
      if ($paragraph->hasField('field_count_accuracy') && !$paragraph->get('field_count_accuracy')->isEmpty()) {
        $total_accuracy += $paragraph->get('field_count_accuracy')->value;
        $count++;
      }
    }

    if ($count > 0) {
      $average_accuracy = $total_accuracy / $count;
      $node->set('field_average_count_accuracy', $average_accuracy);
      $node->save();
    }
  }

  /**
   * Generates a title for the results node.
   *
   * @param mixed $experience_level
   *   The experience level term.
   *
   * @return string
   *   The generated title.
   */
  protected function generateTitle($experience_level) {
    $title = 'Counting Test';

    if ($experience_level instanceof TermInterface) {
      $title .= ' - ' . $experience_level->label();
    }

    $title .= ' - ' . date('Y-m-d H:i:s');

    return $title;
  }

}
