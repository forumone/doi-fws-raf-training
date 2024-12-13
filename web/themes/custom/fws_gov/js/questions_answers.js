(function ($, Drupal) {

    /**
     * Check/Uncheck all checkboxes.
     */
    Drupal.behaviors.questions_answers = {
        attach: function (context, settings) {

            //toggle open class when clicking on question titles
            $(once('questions-toggle', '.paragraph.question-answer .question', context)).on('click', function () {
                $(this).parent().toggleClass('open', 1000);
            });
        }
    };

})(jQuery, Drupal);