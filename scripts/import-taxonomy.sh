#!/bin/bash

# Array of script paths
scripts=(
"../scripts/import-bloods.php"
"../scripts/import-budget-time.php"
"../scripts/import-conceived.php"
"../scripts/import-conditioning.php"
"../scripts/import-county.php"
"../scripts/import-death-cause.php"
"../scripts/import-death-location.php"
)

# Loop through each script and run ddev drush scr
for script in "${scripts[@]}"; do
echo "Running: ddev drush scr $script"
ddev drush scr "$script"

# Check if the command was successful
if [ $? -eq 0 ]; then
echo "✓ Successfully executed $script"
else
echo "✗ Error executing $script"
exit 1 # Exit on first error
fi

echo "-----------------------------------"
done

echo "All scripts completed!"
