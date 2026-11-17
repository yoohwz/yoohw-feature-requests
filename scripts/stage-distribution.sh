#!/usr/bin/env bash
set -euo pipefail
SOURCE="$(cd "${1:?source required}" && pwd -P)"
DESTINATION="${2:?fresh destination required}"
test "$(git -C "$SOURCE" rev-parse --show-toplevel)" = "$SOURCE"
test -f "$SOURCE/yoohw-feature-requests.php"
test ! -e "$DESTINATION" && test ! -L "$DESTINATION"
mkdir -p "$(dirname "$DESTINATION")"
PARENT="$(cd "$(dirname "$DESTINATION")" && pwd -P)"
case "$PARENT/$(basename "$DESTINATION")/" in "$SOURCE/"*) echo 'Destination must be outside source' >&2; exit 1;; esac
mkdir "$DESTINATION"
git -C "$SOURCE" ls-files -z | while IFS= read -r -d '' path; do
  case "$path" in
    yoohw-feature-requests.php|uninstall.php|readme.txt|license.txt|includes/*.php|templates/*.php|assets/*.css)
      test ! -L "$SOURCE/$path"
      mkdir -p "$DESTINATION/$(dirname "$path")"
      cp "$SOURCE/$path" "$DESTINATION/$path"
      ;;
  esac
done
for required in yoohw-feature-requests.php uninstall.php readme.txt license.txt includes/class-requests.php includes/class-admin.php includes/class-frontend.php includes/class-privacy.php templates/form.php assets/requests.css; do
  test -f "$DESTINATION/$required"
done
echo 'Distribution staged: product files only.'
