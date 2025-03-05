(function ($, Drupal, drupalSettings) {
  'use strict';

  Drupal.behaviors.fwsIdTest = {
    attach: function (context, settings) {
      // Log the quiz data to demonstrate it's available
      if (drupalSettings.fws_id_test && drupalSettings.fws_id_test.quiz) {
        console.log('Quiz Data:', drupalSettings.fws_id_test.quiz);

        // Log each video's data
        drupalSettings.fws_id_test.quiz.videos.forEach((video, index) => {
          console.log(`Video ${index + 1}:`, {
            url: video.url,
            correctSpecies: video.species,
            choices: video.choices
          });
        });
      }

      // Initialize the quiz
    }
  };

})(jQuery, Drupal, drupalSettings);
