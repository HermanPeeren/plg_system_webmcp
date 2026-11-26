#!/usr/bin/env bash
#
# Fetch the J2Commerce 6 source into .deps/j2commerce (git-ignored), at a fixed commit.
#
#   tools/fetch-j2commerce.sh            # the commit below
#   tools/fetch-j2commerce.sh <sha>      # another one
#
# PHPStan reads J2Commerce's classes from there (the shop adapter calls its models and
# helpers), and the development site installs the package built from it:
#
#   php .deps/j2commerce/build/build_package.php --release=6.6.6 --no-minify
#   php joomla/cli/joomla.php extension:install --path=.deps/j2commerce/docs/packages/pkg_j2commerce_6-6-6.zip
#
# J2Commerce publishes its packages on j2commerce.com, not as GitHub releases; the commit
# is the one that set the version to 6.6.6.

set -euo pipefail

COMMIT="${1:-76f71e789d8724368c44de6d27f4d202472d760a}"
TARGET="$(dirname "$0")/../.deps/j2commerce"

if [ ! -d "$TARGET/.git" ]; then
  git init -q "$TARGET"
  git -C "$TARGET" remote add origin https://github.com/j2commerce/j2commerce.git
fi

git -C "$TARGET" fetch -q --depth 1 origin "$COMMIT"
git -C "$TARGET" checkout -q FETCH_HEAD
echo "J2Commerce $(git -C "$TARGET" rev-parse --short HEAD) in .deps/j2commerce"
