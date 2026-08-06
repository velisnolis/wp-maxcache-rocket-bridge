#!/usr/bin/env bash
#
# Builds the distributable plugin zip.
#
# Files are picked from an allowlist, never by excluding what we happen to know
# about today: a denylist silently ships whatever gets added tomorrow, and this
# repository now carries a test suite and a vendor directory that must never
# reach a WordPress install.
#
# Usage: ./build.sh [output-directory]

set -euo pipefail

SLUG="wp-maxcache-rocket-bridge"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OUT_DIR="${1:-$ROOT/release}"

# Everything that belongs in a running plugin, and nothing else.
ALLOW=(
	"$SLUG.php"
	"README.md"
	"LICENSE"
	"includes"
	"languages"
)

VERSION="$(sed -n 's/^ \* Version: *\(.*\)$/\1/p' "$ROOT/$SLUG.php" | head -1)"
CONST_VERSION="$(sed -n "s/^define( 'WMRB_VERSION', '\(.*\)' );$/\1/p" "$ROOT/$SLUG.php" | head -1)"

if [ -z "$VERSION" ]; then
	echo "error: could not read Version from the plugin header" >&2
	exit 1
fi

if [ "$VERSION" != "$CONST_VERSION" ]; then
	echo "error: plugin header says $VERSION but WMRB_VERSION says $CONST_VERSION" >&2
	exit 1
fi

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

mkdir -p "$STAGE/$SLUG"
for item in "${ALLOW[@]}"; do
	if [ ! -e "$ROOT/$item" ]; then
		echo "error: expected $item to exist" >&2
		exit 1
	fi
	cp -R "$ROOT/$item" "$STAGE/$SLUG/"
done

# Editor and OS debris can hide inside the copied directories.
find "$STAGE/$SLUG" \( -name '.DS_Store' -o -name '*.orig' -o -name '*.rej' \) -delete

mkdir -p "$OUT_DIR"
ZIP="$OUT_DIR/$SLUG.zip"
rm -f "$ZIP"
( cd "$STAGE" && zip -qr "$ZIP" "$SLUG" )

# The allowlist should make this impossible; assert it anyway, because shipping
# vendor/ or a test suite to a live site is not a mistake worth risking twice.
FORBIDDEN='(^|/)(vendor|tests|node_modules|\.git|\.github|\.remember|\.dist|release)/|composer\.(json|lock)|phpunit|\.sh$|SPEC\.md'
if unzip -Z1 "$ZIP" | grep -Eq "$FORBIDDEN"; then
	echo "error: the archive contains files that must not ship:" >&2
	unzip -Z1 "$ZIP" | grep -E "$FORBIDDEN" >&2
	rm -f "$ZIP"
	exit 1
fi

echo "built $ZIP (version $VERSION, $(unzip -Z1 "$ZIP" | wc -l | tr -d ' ') entries)"
