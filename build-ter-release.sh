#!/bin/bash

# Simple TER release zip builder using git archive

# Get extension key and version
EXTKEY=$(grep '"extension-key"' composer.json | cut -d'"' -f4)
VERSION=$(grep "'version'" ext_emconf.php | sed -E "s/.*'version'.*=>.*'([^']+)'.*/\1/")
ZIP_NAME="${EXTKEY}_${VERSION}.zip"

echo "Building TER release: $ZIP_NAME"

# Use git archive which respects .gitattributes export-ignore
git archive -o "$ZIP_NAME" HEAD

echo "Created: $ZIP_NAME ($(du -h "$ZIP_NAME" | cut -f1))"