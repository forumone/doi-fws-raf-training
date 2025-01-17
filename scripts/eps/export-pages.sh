#!/bin/bash

ddev drush --uri=https://eps.ddev.site/ dcer node 3 --folder=../recipes/fws-eps-content/content
ddev drush --uri=https://eps.ddev.site/ dcer node 4 --folder=../recipes/fws-eps-content/content
ddev drush --uri=https://eps.ddev.site/ dcer node 5 --folder=../recipes/fws-eps-content/content
