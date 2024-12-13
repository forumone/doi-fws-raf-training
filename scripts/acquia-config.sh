#!/bin/bash

## Description: Installs FWS Manatee.

acli remote:drush doifws.devapps recipe ../recipes/fws-core
acli remote:drush doifws.devapps scr ../scripts/install-cleanup.php
acli remote:drush doifws.devapps cr

acli remote:drush doifws.devapps recipe ../recipes/fws-manatee-taxonomy
acli remote:drush doifws.devapps recipe ../recipes/fws-manatee-node
acli remote:drush doifws.devapps recipe ../recipes/fws-manatee-reports
acli remote:drush doifws.devapps recipe ../recipes/fws-manatee-user
acli remote:drush doifws.devapps recipe ../recipes/fws-manatee-content

acli remote:drush doifws.devapps uli
