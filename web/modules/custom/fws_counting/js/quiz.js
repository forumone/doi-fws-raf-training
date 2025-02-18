(function ($, Drupal) {
  'use strict';

  Drupal.behaviors.fwsCountingQuiz = {
    attach: function (context, settings) {
      if (settings.fwsCounting && settings.fwsCounting.quizContext) {
        console.log('Quiz Context Data:');
        console.dir(settings.fwsCounting.quizContext);
      }
    }
  };

})(jQuery, Drupal);
