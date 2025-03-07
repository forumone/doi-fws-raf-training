(function ($, Drupal, drupalSettings) {
  'use strict';

  Drupal.behaviors.fwsIdTest = {
    attach: function (context, settings) {
      // Log the quiz data to demonstrate it's available
      // if (drupalSettings.fws_id_test && drupalSettings.fws_id_test.quiz) {
      //   console.log('Quiz Data:', drupalSettings.fws_id_test.quiz);

      //   // Log each video's data
      //   drupalSettings.fws_id_test.quiz.videos.forEach((video, index) => {
      //     console.log(`Video ${index + 1}:`, {
      //       url: video.url,
      //       correctSpecies: video.species,
      //       choices: video.choices
      //     });
      //   });
      // }

      // Initialize the quiz
      once('fws-id-test', '.quiz__items', context).forEach(function (element) {
        const $quizContainer = $(element);
        let currentQuestion = 0;
        let correctAnswers = 0;
        const quizData = drupalSettings.fws_id_test.quiz;
        const totalQuestions = quizData.videos.length;
        const resultsNodeId = quizData.resultsNodeId;

        function saveQuizAnswer(questionIndex, userAnswer, correctAnswer) {
          if (!resultsNodeId) {
            console.error('No results node ID found');
            return;
          }

          console.log('Saving answer:', {
            questionIndex,
            userAnswer,
            correctAnswer
          });

          // Save the answer via AJAX
          $.ajax({
            url: '/id-test/save-answer',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
              nodeId: resultsNodeId,
              questionIndex: questionIndex,
              userAnswer: userAnswer,
              correctAnswer: correctAnswer,
            }),
            success: function (response) {
              console.log('Answer saved successfully');
            },
            error: function (xhr, status, error) {
              console.error('Error saving answer:', error);
              console.error('Response:', xhr.responseText);
            }
          });
        }

        function showNextQuestion() {
          $('.quiz__item--' + currentQuestion, $quizContainer).hide();
          currentQuestion++;

          if (currentQuestion >= $('.quiz__item', $quizContainer).length) {
            // Redirect to the results node
            window.location.href = `/node/${resultsNodeId}`;
            return;
          }

          const $currentItem = $('.quiz__item--' + currentQuestion, $quizContainer);
          $currentItem.show();
          $('.quiz__response', $currentItem).hide();

          // Update the quiz tracker
          $('.quiz__tracker').text(`#${currentQuestion + 1}`);

          // Reset video for the new question
          const $video = $currentItem.find('video')[0];
          if ($video) {
            $video.currentTime = 0;
          }
        }

        // Handle video completion
        $('.quiz__item', $quizContainer).each(function (index) {
          const $video = $(this).find('video')[0];
          if ($video) {
            $video.addEventListener('ended', function () {
              const $currentItem = $('.quiz__item--' + index, $quizContainer);
              $currentItem.find('.quiz__prompt').slideUp();
              $currentItem.find('.quiz__response').slideDown();
            });
          }
        });

        // Handle continue button clicks
        $('.quiz__continue', $quizContainer).on('click', function () {
          showNextQuestion();
        });

        // Handle submit button clicks
        $('.quiz__submit', $quizContainer).on('click', function () {
          const $currentItem = $(this).closest('.quiz__item');
          const questionIndex = parseInt($currentItem.data('question')) - 1;
          const selectedSpecies = $currentItem.find('input[type="radio"]:checked').val();
          const correctSpecies = quizData.videos[questionIndex].species;

          // Make sure we have a selection
          if (!selectedSpecies) {
            alert('Please select a species before submitting.');
            return;
          }

          console.log('Selected:', selectedSpecies, 'Correct:', correctSpecies);

          // Update the submission and actual text
          $currentItem.find('.quiz__submission').text(selectedSpecies);
          $currentItem.find('.quiz__actual').text(correctSpecies);

          // Hide all feedback initially
          $currentItem.find('.quiz__feedback').hide();

          // Show appropriate feedback
          if (selectedSpecies === correctSpecies) {
            $currentItem.find('.quiz__correct').show();
            correctAnswers++;
          } else {
            $currentItem.find('.quiz__incorrect').show();
          }

          // Save the answer
          saveQuizAnswer(questionIndex, selectedSpecies, correctSpecies);

          // Switch to answer panel
          $(this).closest('.quiz__guess').slideUp()
            .closest('.quiz__response').find('.quiz__answer').slideDown();
        });

        // Show first question
        $('.quiz__item--0', $quizContainer).show();
        $('.quiz__response', $('.quiz__item--0', $quizContainer)).hide();
      });
    }
  };

})(jQuery, Drupal, drupalSettings);
