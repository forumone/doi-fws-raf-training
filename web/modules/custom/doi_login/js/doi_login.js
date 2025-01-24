(function ($, Drupal) {

    'use strict';

    Drupal.behaviors.doi_login = {
        attach: function (context) {
            

            function preventLogin() {
                $('.saml-login-button').addClass('disabled');
                $('.saml-login-button').attr('title', 'You must agree to the terms to login');
                $('.saml-login-button').on('click', function(e) {
                    e.preventDefault();
                });
            } 

            preventLogin();

            $('.fws-terms-conditions-checkbox').on('click', function() {
                if($(this).is(":checked")) {
                    $('.saml-login-button').removeClass('disabled');
                    $('.saml-login-button').attr('title', 'Login with PIV Card / DOI Credentials');
                    $('.saml-login-button').off('click');
                }
                else {
                    preventLogin();
                }
            })
        }
    }

})(jQuery, Drupal);