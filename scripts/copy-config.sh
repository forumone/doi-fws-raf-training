#!/bin/bash

# Define source and destination directories
SOURCE_DIR="./config/sync/"
DEST_DIR="./recipes/fws-tracking-node/config/"

# Define file to ignore
IGNORE_FILE="core.entity_form_display.node.page.default.yml"

# Check if the source directory exists
if [ ! -d "$SOURCE_DIR" ]; then
  echo "Source directory $SOURCE_DIR does not exist."
  exit 1
fi

# Check if the destination directory exists
if [ ! -d "$DEST_DIR" ]; then
  echo "Destination directory $DEST_DIR does not exist."
  exit 1
fi

# Function to check if the only difference is the default_config_hash or uuid
is_only_hash_change() {
  local file1=$1
  local file2=$2
  diff_output=$(diff <(grep -Ev '^(default_config_hash:|uuid:)' "$file1") <(grep -Ev '^(default_config_hash:|uuid:)' "$file2"))
  if [ -z "$diff_output" ]; then
    return 0 # True: Only hash or uuid changed
  else
    return 1 # False: Other changes present
  fi
}

# Process all files in source directory
for source_file in "$SOURCE_DIR"*; do
  if [ -f "$source_file" ]; then
    filename=$(basename "$source_file")
    dest_file="$DEST_DIR$filename"

    # Skip if destination file doesn't exist
    if [ ! -f "$dest_file" ]; then
      echo "Skipping $filename - not present in destination"
      continue
    fi

    # Skip the ignored file
    if [ "$filename" = "$IGNORE_FILE" ]; then
      echo "Skipping ignored file: $filename"
      continue
    fi

    # Check for hash-only changes
    if is_only_hash_change "$source_file" "$dest_file"; then
      echo "Ignoring $filename as only default_config_hash or uuid has changed."
      continue
    fi

    echo "Copying $filename to destination"
    cp "$source_file" "$DEST_DIR"
  fi
done

echo "Sync complete."
