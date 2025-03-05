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

        function showNextQuestion() {
          $('.quiz__item--' + currentQuestion, $quizContainer).hide();
          currentQuestion++;

          if (currentQuestion >= $('.quiz__item', $quizContainer).length) {
            $('.quiz__container').hide();
            const scorePercentage = Math.round((correctAnswers / totalQuestions) * 100) || 0;
            $('.quiz__score').text(scorePercentage);
            $('.quiz__complete').slideDown();
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
        $('.quiz__item', $quizContainer).each(function(index) {
          const $video = $(this).find('video')[0];
          if ($video) {
            $video.addEventListener('ended', function() {
              const $currentItem = $('.quiz__item--' + index, $quizContainer);
              $currentItem.find('.quiz__prompt').slideUp();
              $currentItem.find('.quiz__response').slideDown();
            });
          }
        });

        // Handle continue button clicks
        $('.quiz__continue', $quizContainer).on('click', function() {
          if (currentQuestion + 1 >= totalQuestions) {
            const scorePercentage = Math.round((correctAnswers / totalQuestions) * 100);
            $('.quiz__score').text(scorePercentage);
          }
          showNextQuestion();
        });

        // Handle submit button clicks
        $('.quiz__submit', $quizContainer).on('click', function() {
          const $currentItem = $(this).closest('.quiz__item');
          const questionIndex = parseInt($currentItem.data('question')) - 1;
          const selectedSpecies = $currentItem.find('input[type="radio"]:checked').val();
          const correctSpecies = quizData.videos[questionIndex].species;

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
