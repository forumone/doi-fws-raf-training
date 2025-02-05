#!/bin/bash

ddev drush --uri=https://aerial.ddev.site/ dcer node 1 --folder=../recipes/fws-aerial-content/content
ddev drush --uri=https://aerial.ddev.site/ dcer node 2 --folder=../recipes/fws-aerial-content/content
ddev drush --uri=https://aerial.ddev.site/ dcer node 3 --folder=../recipes/fws-aerial-content/content
ddev drush --uri=https://aerial.ddev.site/ dcer node 4 --folder=../recipes/fws-aerial-content/content
