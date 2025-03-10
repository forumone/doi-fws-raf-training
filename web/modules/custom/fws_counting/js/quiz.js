(function ($, Drupal) {
  'use strict';

  Drupal.behaviors.fwsCountingQuiz = {
    attach: function (context, settings) {
      // if (settings.fwsCounting && settings.fwsCounting.quizContext) {
      //   console.log('Quiz Context Data:');
      //   console.dir(settings.fwsCounting.quizContext);
      // }

      once('fws-counting-quiz', '.quiz__items', context).forEach(function (element) {
        const $quizContainer = $(element);
        const defaultTimer = parseInt($quizContainer.data('timer')); // Default timer value
        const resultsNodeId = $quizContainer.data('results-node-id'); // Results node ID
        let currentQuestion = 0;
        let correctAnswers = 0;
        const totalQuestions = $('.quiz__item', $quizContainer).length;
        let timerInstance = null;
        let countdownInterval = null;

        function getTimerDuration() {
          const $currentItem = $('.quiz__item--' + currentQuestion, $quizContainer);
          const birdCount = parseInt($currentItem.find('.quiz__actual').text());
          return birdCount > 3000 ? 15 : defaultTimer;
        }

        function updateTimerDisplay(secondsLeft, totalSeconds) {
          const $timer = $('.quiz__timer');
          $timer.html(`<div class="timer-circle">
            <div class="timer-number">${secondsLeft}</div>
            <svg class="timer-svg">
              <circle r="24" cx="26" cy="26"></circle>
              <circle r="24" cx="26" cy="26"
                style="stroke-dashoffset: ${(secondsLeft / totalSeconds) * 151}px">
              </circle>
            </svg>
          </div>`);
        }

        function startTimer() {
          const totalSeconds = getTimerDuration();
          let timeLeft = totalSeconds;
          updateTimerDisplay(timeLeft, totalSeconds);

          // Clear any existing intervals
          if (countdownInterval) {
            clearInterval(countdownInterval);
          }

          countdownInterval = setInterval(() => {
            timeLeft--;
            updateTimerDisplay(timeLeft, totalSeconds);

            if (timeLeft <= 0) {
              clearInterval(countdownInterval);
              $('.quiz__item--' + currentQuestion, $quizContainer).find('.quiz__prompt').slideUp();
              $('.quiz__response', $('.quiz__item--' + currentQuestion, $quizContainer)).slideDown();
              focusOnInput(); // Focus on the input field when the response is shown
            }
          }, 1000);

          // Set the timeout for showing the response
          timerInstance = setTimeout(function () {
            clearInterval(countdownInterval);
          }, totalSeconds * 1000);
        }

        function saveQuizAnswer(questionIndex, userCount, actualCount, isCorrect) {
          if (!resultsNodeId) {
            console.error('No results node ID found');
            return;
          }

          // Save the answer via AJAX
          $.ajax({
            url: '/test-your-counting-skills/save-answer',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
              nodeId: resultsNodeId,
              questionIndex: questionIndex,
              userCount: userCount,
              actualCount: actualCount,
              isCorrect: isCorrect
            }),
            success: function (response) {
              console.log('Answer saved successfully');
            },
            error: function (xhr, status, error) {
              console.error('Error saving answer:', error);
            }
          });
        }

        function showNextQuestion() {
          if (timerInstance) {
            clearTimeout(timerInstance);
          }
          if (countdownInterval) {
            clearInterval(countdownInterval);
          }

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

          startTimer();

          // Set up a timer to focus on the input field when the timer ends
          const questionTimer = getTimerDuration();
          setTimeout(function() {
            focusOnInput();
          }, questionTimer * 1000);
        }

        // When timer ends, focus on the input field
        function focusOnInput() {
          const $currentItem = $('.quiz__item--' + currentQuestion, $quizContainer);
          const $input = $currentItem.find('.form-text');
          if ($input.length) {
            setTimeout(function() {
              $input.focus();
            }, 100); // Short delay to ensure the element is visible
          }
        }

        // Focus on the Continue button when the answer panel is shown
        function focusOnContinueButton() {
          const $currentItem = $('.quiz__item--' + currentQuestion, $quizContainer);
          const $continueButton = $currentItem.find('.quiz__continue');
          if ($continueButton.length) {
            setTimeout(function() {
              $continueButton.focus();
            }, 100); // Short delay to ensure the element is visible
          }
        }

        // Start first timer
        startTimer();

        // For the first question, set up a handler to focus on the input when the timer ends
        if ($('.quiz__item--0', $quizContainer).length) {
          const firstQuestionTimer = getTimerDuration();
          setTimeout(function() {
            // This will run after the timer for the first question ends
            focusOnInput();
          }, firstQuestionTimer * 1000);
        }

        // Add keyboard accessibility - handle Enter key press in input field
        $('.form-text', $quizContainer).on('keypress', function(e) {
          if (e.which === 13) { // Enter key
            e.preventDefault();
            $(this).closest('.quiz__guess').find('.quiz__submit').click();
          }
        });

        // Handle form submission
        $('.quiz-form', $quizContainer).on('submit', function(e) {
          e.preventDefault();
          $(this).find('.quiz__submit').click();
        });

        // Handle continue form submission
        $('.continue-form', $quizContainer).on('submit', function(e) {
          e.preventDefault();
          $(this).find('.quiz__continue').click();
        });

        // Add keyboard accessibility - handle Enter key press for Continue button
        $quizContainer.on('keypress', '.quiz__answer', function(e) {
          if (e.which === 13) { // Enter key
            e.preventDefault();
            $(this).find('.quiz__continue').click();
          }
        });

        // Handle continue button clicks
        $('.quiz__continue', $quizContainer).on('click', function (e) {
          e.preventDefault(); // Prevent form submission if inside a form
          if (currentQuestion + 1 >= totalQuestions) {
            const scorePercentage = Math.round((correctAnswers / totalQuestions) * 100);
            $('.quiz__score').text(scorePercentage);
          }
          showNextQuestion();
        });

        // Handle button click to update the closest .quiz__submission and check for correctness
        $('.quiz__submit', $quizContainer).on('click', function(e) {
          e.preventDefault(); // Prevent form submission if inside a form
          const $form = $(this).closest('form');
          const $response = $(this).closest('.quiz__response');
          const userInput = $form ? $form.find('.form-text').val() : $response.find('.form-text').val();
          const actualCount = $response.find('.quiz__actual').text();
          let isCorrect = false;

          // Update the submission text
          $response.find('.quiz__submission').text(userInput);

          // Ensure that the feedback text for the user guess is hidden
          $('.quiz__feedback').hide();

          // Display the appropriate error message relative to the user submission
          if (userInput === actualCount) {
            $response.find('.quiz__correct').show();
            correctAnswers++;
            isCorrect = true;
          }
          else {
            $response.find('.quiz__incorrect').show();
          }

          // Save the answer
          saveQuizAnswer(currentQuestion, parseInt(userInput), parseInt(actualCount), isCorrect);

          // Switch panels to display the answer information
          $(this).closest('.quiz__guess').slideUp().next('.quiz__answer').slideDown(function() {
            // Focus on the Continue button after the answer panel is shown
            focusOnContinueButton();
          });
        });
      });
    }
  };

})(jQuery, Drupal);
