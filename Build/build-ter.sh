#!/bin/bash
#
# Build script for creating a TER-ready extension zip.
# Bundles the @ffmpeg npm packages so non-composer installations work.
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
EXTENSION_KEY="video"

VERSION=$(php -r "
    \$_EXTKEY = '$EXTENSION_KEY';
    include '$PROJECT_DIR/ext_emconf.php';
    echo \$EM_CONF[\$_EXTKEY]['version'];
")

if [ -z "$VERSION" ]; then
    echo "ERROR: Could not determine version from ext_emconf.php" >&2
    exit 1
fi

BUILD_DIR="$PROJECT_DIR/.build"
DIST_DIR="$PROJECT_DIR/dist"
EXTENSION_DIR="$BUILD_DIR/$EXTENSION_KEY"

echo "Building TER package for $EXTENSION_KEY version $VERSION"

rm -rf "$BUILD_DIR"
mkdir -p "$BUILD_DIR" "$DIST_DIR" "$EXTENSION_DIR"

echo "Copying extension files..."

for dir in Classes Configuration Documentation Resources; do
    if [ -d "$PROJECT_DIR/$dir" ]; then
        cp -R "$PROJECT_DIR/$dir" "$EXTENSION_DIR/"
    fi
done

for file in ext_emconf.php ext_localconf.php ext_tables.php ext_tables.sql composer.json README.md LICENSE; do
    if [ -f "$PROJECT_DIR/$file" ]; then
        cp "$PROJECT_DIR/$file" "$EXTENSION_DIR/"
    fi
done

# Make sure the ffmpeg npm packages are present (required at runtime).
# If they were not committed, install them fresh.
NODE_DIR="$EXTENSION_DIR/Resources/Public/node_modules"
if [ ! -f "$NODE_DIR/@ffmpeg/ffmpeg/dist/esm/index.js" ] \
   || [ ! -f "$NODE_DIR/@ffmpeg/core-mt/dist/esm/ffmpeg-core.wasm" ]; then
    echo "Installing @ffmpeg npm packages..."
    (cd "$EXTENSION_DIR/Resources/Public" && npm install --omit=dev --no-audit --no-fund --silent)
fi

# Drop the parts we don't ship.
rm -rf "$NODE_DIR/@ffmpeg/core-mt/dist/umd" 2>/dev/null || true
rm -rf "$NODE_DIR/@ffmpeg/ffmpeg/dist/umd" 2>/dev/null || true
rm -f  "$EXTENSION_DIR/Resources/Public/package.json" \
       "$EXTENSION_DIR/Resources/Public/package-lock.json" 2>/dev/null || true
find "$EXTENSION_DIR" -name '.DS_Store' -delete 2>/dev/null || true

cd "$EXTENSION_DIR"

ZIP_FILE="$DIST_DIR/${EXTENSION_KEY}_${VERSION}.zip"
rm -f "$ZIP_FILE"
echo "Creating zip file: $ZIP_FILE"
zip -r "$ZIP_FILE" . -x "*.git*" -x "*.DS_Store" >/dev/null

echo ""
echo "TER package created successfully!"
echo "  File: $ZIP_FILE"
echo "  Size: $(du -h "$ZIP_FILE" | cut -f1)"
