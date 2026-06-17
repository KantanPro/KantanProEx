#!/usr/bin/env bash
# 全エディション（pro / solo / team / business）の配布 ZIP を一括生成
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

for edition in pro solo team business; do
  echo "========== ${edition} =========="
  ./create_release_zip.sh "${edition}"
done

DEST="${KTPWP_RELEASE_DEST_PARENT:-/Users/kantanpro/Desktop}/${KTPWP_RELEASE_DEST_DIR_NAME:-KantanProEX_TEST_UP}"
echo ""
echo "完了: ${DEST}/*.zip"
