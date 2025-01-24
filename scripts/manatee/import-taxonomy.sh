#!/bin/bash

# Array of script paths
scripts=(
"../scripts/manatee/import-bloods.php"
"../scripts/manatee/import-budget-time.php"
"../scripts/manatee/import-conceived.php"
"../scripts/manatee/import-conditioning.php"
"../scripts/manatee/import-county.php"
"../scripts/manatee/import-death-cause.php"
"../scripts/manatee/import-death-location.php"
"../scripts/manatee/import-events.php"
"../scripts/manatee/import-gear-type.php"
"../scripts/manatee/import-group.php"
"../scripts/manatee/import-health.php"
"../scripts/manatee/import-id-type.php"
"../scripts/manatee/import-months.php"
"../scripts/manatee/import-org.php"
"../scripts/manatee/import-permit-type.php"
"../scripts/manatee/import-pot.php"
"../scripts/manatee/import-pregnant.php"
"../scripts/manatee/import-rearing.php"
"../scripts/manatee/import-release-status.php"
"../scripts/manatee/import-release-time.php"
"../scripts/manatee/import-rescue-cause.php"
"../scripts/manatee/import-rescue-type.php"
"../scripts/manatee/import-research-cat.php"
"../scripts/manatee/import-sex.php"
"../scripts/manatee/import-state.php"
"../scripts/manatee/import-status-month.php"
"../scripts/manatee/import-system.php"
"../scripts/manatee/import-tag-type.php"
"../scripts/manatee/import-trans-reason.php"
"../scripts/manatee/import-water.php"
)

# Color codes for output
GREEN='\033[0;32m'
RED='\033[0;31m'
NC='\033[0m' # No Color

# Counter for successful and failed executions
success_count=0
failed_count=0

# Log file setup
log_file="import_script_$(date +%Y%m%d_%H%M%S).log"
echo "Script execution started at $(date)" > "$log_file"

# Function to log messages both to console and file
log_message() {
echo -e "$1"
echo "$1" | sed 's/\x1b\[[0-9;]*m//g' >> "$log_file"
}

# Loop through each script and run ddev drush scr
for script in "${scripts[@]}"; do
log_message "\nRunning: ddev drush scr $script"

if output=$(ddev drush scr "$script" 2>&1); then
log_message "${GREEN}✓ Successfully executed $script${NC}"
log_message "$output"
((success_count++))
else
log_message "${RED}✗ Error executing $script${NC}"
log_message "Error details:"
log_message "$output"
((failed_count++))

# Ask user if they want to continue despite the error
read -p "Do you want to continue with the remaining scripts? (y/n) " -n 1 -r
echo
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
log_message "${RED}Script execution aborted by user after error${NC}"
break
fi
fi

log_message "-----------------------------------"
done

# Summary
log_message "\nExecution Summary:"
log_message "Total scripts: ${#scripts[@]}"
log_message "${GREEN}Successful: $success_count${NC}"
log_message "${RED}Failed: $failed_count${NC}"
log_message "Log file created: $log_file"

if [ $failed_count -eq 0 ]; then
    log_message "${GREEN}All scripts completed successfully!${NC}"
    exit 0
else
    log_message "${RED}Some scripts failed. Check the log file for details.${NC}"
    exit 1
fi
