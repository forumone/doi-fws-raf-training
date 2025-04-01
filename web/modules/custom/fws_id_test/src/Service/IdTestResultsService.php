<?php

namespace Drupal\fws_id_test\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\taxonomy\TermInterface;

/**
 * Service for managing species identification test results.
 */
class IdTestResultsService {

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
   * Constructs a new IdTestResultsService object.
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
   * Creates a new species ID results node.
   *
   * @param \Drupal\taxonomy\TermInterface $difficulty
   *   The difficulty level term.
   * @param \Drupal\taxonomy\TermInterface $region
   *   The region term.
   * @param \Drupal\taxonomy\TermInterface $species_group
   *   The species group term.
   * @param array $questions
   *   The questions used in the quiz, each containing:
   *   - media_id: The media entity ID.
   *
   * @return \Drupal\node\NodeInterface
   *   The created node.
   */
  public function createResultsNode(
    TermInterface $difficulty,
    TermInterface $region,
    TermInterface $species_group,
    array $questions,
  ) {
    try {
      // Log input parameters.
      \Drupal::logger('fws_id_test')->notice('Creating results node with: difficulty=@diff, region=@reg, species_group=@sp, questions=@q', [
        '@diff' => $difficulty->label(),
        '@reg' => $region->label(),
        '@sp' => $species_group->label(),
        '@q' => print_r($questions, TRUE),
      ]);

      // Create a new node.
      $node = $this->entityTypeManager->getStorage('node')->create([
        'type' => 'species_id_results',
        'title' => $this->generateTitle($difficulty),
        'uid' => $this->currentUser->id(),
        'status' => TRUE,
      ]);

      // Log node creation.
      \Drupal::logger('fws_id_test')->notice('Created node with title: @title', [
        '@title' => $node->getTitle(),
      ]);

      // Set the fields.
      $node->set('field_id_difficulty', $difficulty->id());
      $node->set('field_region', $region->id());
      $node->set('field_species_group', $species_group->id());

      // Create paragraph items for each question.
      $paragraphs = [];
      foreach ($questions as $index => $question) {
        try {
          // Load the media entity to get the species reference.
          $media = $this->entityTypeManager->getStorage('media')->load($question['media_id']);
          if (!$media) {
            \Drupal::logger('fws_id_test')->error('Failed to load media entity @id for question @index', [
              '@id' => $question['media_id'],
              '@index' => $index,
            ]);
            continue;
          }

          \Drupal::logger('fws_id_test')->notice('Creating paragraph for media @id', [
            '@id' => $media->id(),
          ]);

          $paragraph = Paragraph::create([
            'type' => 'species_id_question',
            'field_media_reference' => $question['media_id'],
          ]);

          if (!$paragraph->save()) {
            \Drupal::logger('fws_id_test')->error('Failed to save paragraph for question @index', [
              '@index' => $index,
            ]);
            continue;
          }

          \Drupal::logger('fws_id_test')->notice('Created paragraph @pid for question @index', [
            '@pid' => $paragraph->id(),
            '@index' => $index,
          ]);

          $paragraphs[] = [
            'target_id' => $paragraph->id(),
            'target_revision_id' => $paragraph->getRevisionId(),
          ];
        }
        catch (\Exception $e) {
          \Drupal::logger('fws_id_test')->error('Error creating paragraph for question @index: @error', [
            '@index' => $index,
            '@error' => $e->getMessage(),
          ]);
        }
      }

      if (empty($paragraphs)) {
        \Drupal::logger('fws_id_test')->error('No valid paragraphs were created for the quiz');
        return NULL;
      }

      \Drupal::logger('fws_id_test')->notice('Setting @count paragraphs to node', [
        '@count' => count($paragraphs),
      ]);

      $node->set('field_id_questions', $paragraphs);

      try {
        $node->save();
        \Drupal::logger('fws_id_test')->notice('Successfully saved node @nid', [
          '@nid' => $node->id(),
        ]);
      }
      catch (\Exception $e) {
        \Drupal::logger('fws_id_test')->error('Failed to save results node: @error', [
          '@error' => $e->getMessage(),
        ]);
        return NULL;
      }

      return $node;
    }
    catch (\Exception $e) {
      \Drupal::logger('fws_id_test')->error('Error creating results node: @error', [
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Updates a question in the results node with the user's answer.
   *
   * @param int $node_id
   *   The node ID.
   * @param int $question_index
   *   The question index (0-based).
   * @param string $user_answer
   *   The user's answer (species name).
   * @param string $correct_answer
   *   The correct answer (species name).
   *
   * @return bool
   *   TRUE if successful, FALSE otherwise.
   */
  public function updateQuestionResult($node_id, $question_index, $user_answer, $correct_answer) {
    // Load the node.
    $node = $this->entityTypeManager->getStorage('node')->load($node_id);
    if (!$node || $node->bundle() !== 'species_id_results') {
      return FALSE;
    }

    // Get the paragraphs.
    $paragraphs = $node->get('field_id_questions')->referencedEntities();
    if (!isset($paragraphs[$question_index])) {
      return FALSE;
    }

    // Find the species term by name.
    $species_terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties([
      'vid' => 'species',
      'name' => $user_answer,
    ]);

    $species_term = reset($species_terms);
    if (!$species_term) {
      // If we can't find the exact species, try to find it by case-insensitive search.
      $query = $this->entityTypeManager->getStorage('taxonomy_term')->getQuery()
        ->accessCheck(TRUE)
        ->condition('vid', 'species')
        ->condition('name', $user_answer, 'CONTAINS');
      $term_ids = $query->execute();

      if (!empty($term_ids)) {
        $species_term = $this->entityTypeManager->getStorage('taxonomy_term')->load(reset($term_ids));
      }
      else {
        // If we still can't find it, log an error and return false.
        \Drupal::logger('fws_id_test')->error('Could not find species term for @species', [
          '@species' => $user_answer,
        ]);
        return FALSE;
      }
    }

    // Update the paragraph.
    $paragraph = $paragraphs[$question_index];
    $paragraph->set('field_user_species_selection', $species_term->id());

    // Get the media entity to determine the correct species.
    $media_reference = $paragraph->get('field_media_reference')->entity;
    if ($media_reference && $media_reference->hasField('field_species')) {
      $correct_species = $media_reference->get('field_species')->entity;
      if ($correct_species) {
        // Set is_correct based on whether the user's selection matches the media's species.
        $is_correct = ($species_term->id() === $correct_species->id());
        $paragraph->set('field_is_correct', $is_correct);
      }
    }

    $paragraph->save();

    // Update the test score.
    $this->updateTestScore($node);

    return TRUE;
  }

  /**
   * Updates the test score for a results node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to update.
   */
  protected function updateTestScore(NodeInterface $node) {
    $paragraphs = $node->get('field_id_questions')->referencedEntities();
    $total_questions = count($paragraphs);
    $correct_answers = 0;

    foreach ($paragraphs as $paragraph) {
      if ($paragraph->hasField('field_is_correct') &&
          !$paragraph->get('field_is_correct')->isEmpty() &&
          $paragraph->get('field_is_correct')->value) {
        $correct_answers++;
      }
    }

    if ($total_questions > 0) {
      $score = ($correct_answers / $total_questions) * 100;
      $node->set('field_test_score', $score);
      $node->save();
    }
  }

  /**
   * Generates a title for the results node.
   *
   * @param \Drupal\taxonomy\TermInterface $difficulty
   *   The difficulty level term.
   *
   * @return string
   *   The generated title.
   */
  protected function generateTitle(TermInterface $difficulty) {
    $title = 'Species ID Test';
    $title .= ' - ' . $difficulty->label();
    $title .= ' - ' . date('Y-m-d H:i:s');

    return $title;
  }

}
