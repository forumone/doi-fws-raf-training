(function ($, Drupal) {
  'use strict';

  Drupal.behaviors.fwsCountingQuiz = {
    attach: function (context, settings) {
      if (settings.fwsCounting && settings.fwsCounting.quizContext) {
        console.log('Quiz Context Data:');
        console.dir(settings.fwsCounting.quizContext);
      }

      // Use once() with the newer Drupal 9+ syntax
      once('fws-counting-quiz', '.quiz__items', context).forEach(function (element) {
        const $quizContainer = $(element);
        const timer = parseInt($quizContainer.data('timer')); // Keep as seconds for countdown
        let currentQuestion = 0;
        let timerInstance = null;
        let countdownInterval = null;

        function updateTimerDisplay(secondsLeft) {
          const $timer = $('.quiz__timer');
          $timer.html(`<div class="timer-circle">
            <div class="timer-number">${secondsLeft}</div>
            <svg class="timer-svg">
              <circle r="24" cx="26" cy="26"></circle>
              <circle r="24" cx="26" cy="26"
                style="stroke-dashoffset: ${(secondsLeft / timer) * 151}px">
              </circle>
            </svg>
          </div>`);
        }

        function startTimer() {
          let timeLeft = timer;
          updateTimerDisplay(timeLeft);

          // Clear any existing intervals
          if (countdownInterval) {
            clearInterval(countdownInterval);
          }

          countdownInterval = setInterval(() => {
            timeLeft--;
            updateTimerDisplay(timeLeft);

            if (timeLeft <= 0) {
              clearInterval(countdownInterval);
              $('.quiz__answer', $('.quiz__item--' + currentQuestion, $quizContainer)).slideDown();
            }
          }, 1000);

          // Set the timeout for showing the answer
          timerInstance = setTimeout(function() {
            clearInterval(countdownInterval);
          }, timer * 1000);
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
            console.log('Quiz complete!');
            $('.quiz__timer', $quizContainer).hide();
            return;
          }

          const $currentItem = $('.quiz__item--' + currentQuestion, $quizContainer);
          $currentItem.show();
          $('.quiz__answer', $currentItem).hide();

          startTimer();
        }

        // Start first timer
        startTimer();

        // Handle continue button clicks
        $('.quiz__continue', $quizContainer).on('click', function() {
          showNextQuestion();
        });
      });
    }
  };

})(jQuery, Drupal);
