#!/bin/bash

for i in {1..57}
do
  ddev drush dcer menu_link_content $i --folder=../recipes/fws-manatee/content
done
