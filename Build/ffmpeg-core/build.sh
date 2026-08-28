#!/bin/bash
# Reproducible build of the custom @ffmpeg/core-mt used by this extension.
#
# The stock @ffmpeg/core-mt from npm has a fixed (non-growable) 1 GiB WASM heap,
# which OOMs when transcoding large/4K videos. This builds the same core with the
# heap raised to 2 GiB, following the official build docs:
# https://ffmpegwasm.netlify.app/docs/contribution/core/
#
# Requirements: Docker 23+ with buildx, make, git. Takes ~1h on a cold cache.
#
# Usage:
#   bash Build/ffmpeg-core/build.sh
#
# Output is copied into Resources/Public/node_modules/@ffmpeg/core-mt/dist/esm/.

set -euo pipefail

# ffmpegwasm/ffmpeg.wasm commit this build is pinned to (builds core-mt 0.12.10)
UPSTREAM_REPO="https://github.com/ffmpegwasm/ffmpeg.wasm.git"
UPSTREAM_REF="f876f907c7e9b9bf51d4ed0b913a855a63ae63fc"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
EXTENSION_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
TARGET_DIR="$EXTENSION_DIR/Resources/Public/node_modules/@ffmpeg/core-mt/dist/esm"
PATCH_FILE="$SCRIPT_DIR/0001-raise-core-mt-memory-to-2GB.patch"

WORK_DIR="$(mktemp -d "${TMPDIR:-/tmp}/ffmpeg-core-build.XXXXXX")"
trap 'rm -rf "$WORK_DIR"' EXIT

echo "==> Fetching ffmpeg.wasm @ $UPSTREAM_REF"
git -C "$WORK_DIR" init -q
git -C "$WORK_DIR" remote add origin "$UPSTREAM_REPO"
git -C "$WORK_DIR" fetch -q --depth 1 origin "$UPSTREAM_REF"
git -C "$WORK_DIR" checkout -q FETCH_HEAD

echo "==> Applying 2 GiB memory patch"
git -C "$WORK_DIR" apply --verbose "$PATCH_FILE"

# The local-directory output (-o) used by the Makefile requires a buildx builder
# with the docker-container driver; the default "docker" driver cannot do it.
if ! docker buildx inspect ffmpeg-core-builder >/dev/null 2>&1; then
    echo "==> Creating buildx builder (docker-container driver)"
    docker buildx create --name ffmpeg-core-builder --driver docker-container >/dev/null
fi
export BUILDX_BUILDER=ffmpeg-core-builder

echo "==> Building (make prd-mt) — this can take ~1h without layer cache"
make -C "$WORK_DIR" prd-mt

echo "==> Installing into $TARGET_DIR"
SRC="$WORK_DIR/packages/core-mt/dist/esm"
cp "$SRC/ffmpeg-core.js" "$SRC/ffmpeg-core.wasm" "$SRC/ffmpeg-core.worker.js" "$TARGET_DIR/"

echo "==> Done"
