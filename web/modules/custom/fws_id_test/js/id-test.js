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
        // const totalQuestions = quizData.videos.length;
        const resultsNodeId = quizData.resultsNodeId;

        // Autoplay the first video when the quiz loads
        const $firstVideo = $('.quiz__item--0', $quizContainer).find('video')[0];
        if ($firstVideo) {
          $firstVideo.play().catch(error => {
            console.warn('Initial autoplay failed:', error);
            // Show a play button if autoplay is blocked
            const $videoContainer = $($firstVideo).closest('.video-container');
            if (!$videoContainer.find('.manual-play-button').length) {
              const $playButton = $('<button class="manual-play-button btn btn-primary">Play Video</button>');
              $videoContainer.append($playButton);
              $playButton.on('click', function () {
                $firstVideo.play();
                $(this).hide();
              });
            }
          });
        }

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
            // Redirect to the results node using Drupal's base path
            const basePath = drupalSettings.path.baseUrl || '/';
            window.location.href = `${basePath}node/${resultsNodeId}`;
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
            // Ensure video autoplays
            $video.play().catch(error => {
              console.warn('Autoplay failed:', error);
              // Show a play button or message if autoplay is blocked
              const $videoContainer = $($video).closest('.video-container');
              if (!$videoContainer.find('.manual-play-button').length) {
                const $playButton = $('<button class="manual-play-button btn btn-primary">Play Video</button>');
                $videoContainer.append($playButton);
                $playButton.on('click', function () {
                  $video.play();
                  $(this).hide();
                });
              }
            });
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

        // Focus on the Continue button when the answer panel is shown
        function focusOnContinueButton() {
          const $currentItem = $('.quiz__item--' + currentQuestion, $quizContainer);
          const $continueButton = $currentItem.find('.quiz__continue');
          if ($continueButton.length) {
            setTimeout(function () {
              $continueButton.focus();
            }, 100); // Short delay to ensure the element is visible
          }
        }

        // Add keyboard accessibility - handle Enter key press for radio buttons
        $quizContainer.on('keypress', '.form-radio, .quiz__guess', function (e) {
          if (e.which === 13) { // Enter key
            e.preventDefault();
            const $currentItem = $(this).closest('.quiz__item');
            const $radioChecked = $currentItem.find('input[type="radio"]:checked');
            if ($radioChecked.length) {
              $currentItem.find('.quiz__submit').click();
            } else {
              alert('Please select a species before submitting.');
            }
          }
        });

        // Handle form submission
        $('.quiz-form', $quizContainer).on('submit', function (e) {
          e.preventDefault();
          const $currentItem = $(this).closest('.quiz__item');
          const $radioChecked = $currentItem.find('input[type="radio"]:checked');
          if ($radioChecked.length) {
            $(this).find('.quiz__submit').click();
          } else {
            alert('Please select a species before submitting.');
          }
        });

        // Handle continue form submission
        $('.continue-form', $quizContainer).on('submit', function (e) {
          e.preventDefault();
          $(this).find('.quiz__continue').click();
        });

        // Add keyboard accessibility - handle Enter key press for Continue button
        $quizContainer.on('keypress', '.quiz__answer', function (e) {
          if (e.which === 13) { // Enter key
            e.preventDefault();
            $(this).find('.quiz__continue').click();
          }
        });

        // Handle continue button clicks
        $('.quiz__continue', $quizContainer).on('click', function (e) {
          e.preventDefault(); // Prevent form submission if inside a form
          showNextQuestion();
        });

        // Handle submit button clicks
        $('.quiz__submit', $quizContainer).on('click', function (e) {
          e.preventDefault(); // Prevent form submission if inside a form
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
            .closest('.quiz__response').find('.quiz__answer').slideDown(function () {
              // Focus on the Continue button after the answer panel is shown
              focusOnContinueButton();
            });
        });

        // Show first question
        $('.quiz__item--0', $quizContainer).show();
        $('.quiz__response', $('.quiz__item--0', $quizContainer)).hide();
      });
    }
  };

})(jQuery, Drupal, drupalSettings);
