#!/usr/bin/env bash
set -euo pipefail

# Usage:
#   ./scripts/build-images-from-package.sh /path/to/rjournaler_web-src-YYYYMMDD-HHMMSS.tar.gz 1.0.1
#
# Output images:
#   rjournaler-web-app:<tag>
#   rjournaler-web-worker:<tag>

if [[ $# -lt 1 ]]; then
  echo "Usage: $0 <source-tar.gz> [tag]" >&2
  exit 1
fi

PACKAGE_PATH="$1"
TAG="${2:-1.0.1}"
WORK_DIR="${PWD}/.build-tmp/rjournaler_web_${TAG}"

if [[ ! -f "$PACKAGE_PATH" ]]; then
  echo "Package not found: $PACKAGE_PATH" >&2
  exit 1
fi

rm -rf "$WORK_DIR"
mkdir -p "$WORK_DIR"

tar -xzf "$PACKAGE_PATH" -C "$WORK_DIR"

pushd "$WORK_DIR" >/dev/null

docker buildx build --load -f docker/php/Dockerfile -t "rjournaler-web-app:${TAG}" .
docker buildx build --load -f docker/worker/Dockerfile -t "rjournaler-web-worker:${TAG}" .

popd >/dev/null

echo "Built images:"
echo "  rjournaler-web-app:${TAG}"
echo "  rjournaler-web-worker:${TAG}"
