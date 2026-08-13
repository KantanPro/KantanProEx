#!/usr/bin/env bash

# --- 設定 ---
SOURCE_DIR="$(pwd)"
DEST_PARENT_DIR="${KTPWP_RELEASE_DEST_PARENT:-/Users/kantanpro/Desktop}"
DEST_DIR_NAME="${KTPWP_RELEASE_DEST_DIR_NAME:-KantanProEX_TEST_UP}"
# --- 設定ここまで ---

EDITION="${1:-pro}"
GITHUB_REPO=""

case "$EDITION" in
    pro)
        BUILD_DIR_NAME="KantanProEX"
        PLUGIN_NAME="KantanProEX"
        STAFF_LIMIT="0"
        GITHUB_REPO="KantanPro/KantanProEx"
        ;;
    solo)
        BUILD_DIR_NAME="KantanProEXsolo"
        PLUGIN_NAME="KantanProEXsolo"
        STAFF_LIMIT="1"
        GITHUB_REPO="KantanPro/KantanProEx"
        ;;
    team)
        BUILD_DIR_NAME="KantanProEXteam"
        PLUGIN_NAME="KantanProEXteam"
        STAFF_LIMIT="5"
        GITHUB_REPO="KantanPro/KantanProEx"
        ;;
    business)
        BUILD_DIR_NAME="KantanProEXbusiness"
        PLUGIN_NAME="KantanProEXbusiness"
        STAFF_LIMIT="15"
        GITHUB_REPO="KantanPro/KantanProEx"
        ;;
    *)
        echo "❌ 不明なエディション: ${EDITION}"
        echo "使用可能: pro | solo | team | business"
        exit 1
        ;;
esac

DEST_DIR="${DEST_PARENT_DIR}/${DEST_DIR_NAME}"
BUILD_DIR="${DEST_DIR}/${BUILD_DIR_NAME}"

set -e

echo "--------------------------------------------------"
echo "KantanProEX プラグイン配布サイト用ZIPファイル生成スクリプト"
echo "エディション: ${EDITION} (${PLUGIN_NAME})"
echo "--------------------------------------------------"

echo "[1/8] バージョン情報を取得中..."
VERSION_RAW=$(grep -i "Version:" "$SOURCE_DIR/ktpwp.php" | head -n 1)
echo "  - 生のバージョン情報: ${VERSION_RAW}"
VERSION=$(echo "$VERSION_RAW" | sed -E 's/.*Version:[[:space:]]*([0-9]+\.[0-9]+\.[0-9]+)\(?([a-zA-Z0-9]*)\)?.*/\1\2/')
DATE=$(date +%Y%m%d)
ZIP_FILE_NAME="${BUILD_DIR_NAME}_${VERSION}_${DATE}.zip"
FINAL_ZIP_PATH="${DEST_DIR}/${ZIP_FILE_NAME}"

echo "  - バージョン: ${VERSION}"
echo "  - 日付: ${DATE}"
echo "  - ZIPファイル名: ${ZIP_FILE_NAME}"

echo -e "\n[2/8] ビルド環境をクリーンアップ中..."
mkdir -p "${DEST_DIR}"
rm -rf "${BUILD_DIR}"
rm -f "${FINAL_ZIP_PATH}"
echo "  - 完了"

echo -e "\n[3/8] ソースファイルをコピー中..."
EXCLUDE_LIST=(".git" ".cursor" ".vscode" ".idea" "KantanPro_build_temp" "KantanProEX_build_temp" "KantanPro_temp" "KantanProEX_temp" "wp" "node_modules" "vendor" "wp-content" "wp-cli.phar" "wp-cli.yml" "wp-cli.sh" "wp-cli-aliases.sh" "setup-wp-cli.sh" "WP-CLI-README.md" "create_release_zip.sh" "create_dummy_data.php.bak" "run-dummy-data.sh" "test-report-ajax.php" "debug-progress-chart.html" "wp-cli-create-dummy-data.php" "wp-cli-aliases.sh" "QUICK-START.md" "SECURITY.md" "DUMMY-DATA-README.md" "DEVELOPMENT-ENVIRONMENT-SETUP.md" "DEVELOPMENT-ENVIRONMENT-DETECTION-IMPLEMENTATION.md" "DEBUG-SETUP.md" "DEBUG-AJAX-IMPLEMENTATION.md" "AUTO-MIGRATION-ENHANCEMENT-COMPLETE.md" "AUTO-MIGRATION-IMPLEMENTATION-COMPLETE.md" "CACHE-OPTIMIZATION-FOR-DISTRIBUTION-COMPLETE.md" "COMPLETION-DATE-AUTO-SET-IMPLEMENTATION.md" "COMPREHENSIVE-TAX-TEST-RESULTS.md" "DISTRIBUTION-MIGRATION-COMPLETE.md" "DISTRIBUTION-MIGRATION-ENHANCEMENT-COMPLETE.md" "DISTRIBUTION-MIGRATION-ERROR-FIX-COMPLETE.md" "DISTRIBUTION-README.md" "DISTRIBUTION-UPDATE-CHECK-FIX-COMPLETE.md" "DUMMY-DATA-ENHANCEMENT-PROPOSAL.md" "DUMMY-ORDER-CREATION-DATE-FIX-COMPLETE.md" "FIX-DELETED-SKILLS-CACHE-ISSUE.md" "FOOD-SKILL-TAX-RATE-FIX-COMPLETE.md" "IMPLEMENTATION-SUMMARY.md" "INTERNAL-TAX-CALCULATION-FIX-COMPLETE.md" "INVOICE-PREVIEW-TAX-RATE-COLUMN-COMPLETE.md" "INVOICE-TAX-IMPLEMENTATION-COMPLETE.md" "INVOICE-TAX-TEST-CHECKLIST.md" "LICENSE-MANAGEMENT-IMPLEMENTATION-COMPLETE.md" "MULTIPLE-TAX-RATES-IMPLEMENTATION-COMPLETE.md" "ORDER-INVOICE-TAX-CATEGORY-UPDATE-COMPLETE.md" "ORDER-MEMORY-IMPLEMENTATION-COMPLETE.md" "OUTPUT-BUFFERING-FIX-COMPLETE.md" "PAGINATION-IMPLEMENTATION-COMPLETE.md" "PRODUCT-MANAGEMENT-UPDATE.md" "PROFIT-CALCULATION-FIX-COMPLETE.md" "PURCHASE-ORDER-EMAIL-OPTIMIZATION-COMPLETE.md" "QUALIFIED-INVOICE-PROFIT-CALCULATION-IMPLEMENTATION-COMPLETE.md" "REPORT-TAB-IMPLEMENTATION-COMPLETE.md" "SERVICE-TAX-RATE-NULL-IMPLEMENTATION-COMPLETE.md" "SKILLS-PAGINATION-COMPLETE.md" "STAFF-AVATAR-DISPLAY.md" "STAFF-CHAT-AUTO-SCROLL.md" "SUPPLIER-SKILLS-COMPLETE.md" "SUPPLIER-TAX-CALCULATION-IMPLEMENTATION-COMPLETE.md" "SUPPLIER-TAX-RATE-UPDATE-FIX-COMPLETE.md" "TAX-CATEGORY-LABELS-UPDATE-COMPLETE.md" "TAX-INCLUSIVE-SETTING-REMOVAL-COMPLETE.md" "TAX-RATE-NULL-ALLOWED-COMPLETE.md" "TAX-RATE-NULL-FIX-COMPLETE.md" "TAX-RATE-UPDATE-FIX-COMPLETE.md" "TAX-RATE-ZERO-FIX-COMPLETE.md" "UPDATE-NOTIFICATION-VERSION-FIX-COMPLETE.md" "URL-PERMALINK-DYNAMIC-IMPLEMENTATION-COMPLETE.md")
EXCLUDE_OPTS=""
for item in "${EXCLUDE_LIST[@]}"; do
    EXCLUDE_OPTS+="--exclude=${item} "
done
eval rsync -a ${EXCLUDE_OPTS} "\"${SOURCE_DIR}/\"" "\"${BUILD_DIR}/\""
echo "  - 完了"

echo -e "\n[4/8] エディション情報を注入中..."
KTPWP_FILE="${BUILD_DIR}/ktpwp.php"
if [ ! -f "${KTPWP_FILE}" ]; then
    echo "  ❌ ktpwp.php が見つかりません"
    exit 1
fi

perl -pi -e "s/^\s*\*\s*Plugin Name:\s*.*/ * Plugin Name: ${PLUGIN_NAME}/" "${KTPWP_FILE}"
perl -pi -e "s|^\s*\*\s*Update URI:.*| * Update URI: https://github.com/${GITHUB_REPO}|" "${KTPWP_FILE}"
perl -pi -e "s/define\\(\\s*'KTPWP_EDITION'\\s*,\\s*'[^']*'\\s*\\)/define( 'KTPWP_EDITION', '${EDITION}' )/g" "${KTPWP_FILE}"
perl -pi -e "s/define\\(\\s*'KANTANPRO_PLUGIN_NAME'\\s*,\\s*'[^']*'\\s*\\)/define( 'KANTANPRO_PLUGIN_NAME', '${PLUGIN_NAME}' )/g" "${KTPWP_FILE}"
perl -pi -e "s/define\\(\\s*'KANTANPRO_PLUGIN_CANONICAL_DIR'\\s*,\\s*'[^']*'\\s*\\)/define( 'KANTANPRO_PLUGIN_CANONICAL_DIR', '${BUILD_DIR_NAME}' )/g" "${KTPWP_FILE}"

if grep -q "define( 'KTPWP_STAFF_LIMIT'" "${KTPWP_FILE}"; then
    perl -pi -e "s/define\\(\\s*'KTPWP_STAFF_LIMIT'\\s*,\\s*[0-9]+\\s*\\)/define( 'KTPWP_STAFF_LIMIT', ${STAFF_LIMIT} )/g" "${KTPWP_FILE}"
else
    echo "  ❌ KTPWP_STAFF_LIMIT 定数が見つかりません"
    exit 1
fi

echo "  - Plugin Name: ${PLUGIN_NAME}"
echo "  - GitHub Repo: ${GITHUB_REPO}"
echo "  - KTPWP_EDITION: ${EDITION}"
echo "  - KTPWP_STAFF_LIMIT: ${STAFF_LIMIT}"
echo "  - 完了"

echo -e "\n[5/8] Composer依存関係を処理中..."
if [ -f "${BUILD_DIR}/composer.json" ]; then
    rm -f "${BUILD_DIR}/composer.lock"
    echo "  - composer.lock を削除しました（配布サイト用）"
    echo "  - 完了"
else
    echo "  - composer.json が見つからないためスキップしました。"
fi

echo -e "\n[6/8] 不要な開発用ファイルを削除中..."
BEFORE_COUNT=$(find "${BUILD_DIR}" -type f | wc -l)

find "${BUILD_DIR}" -type f -name ".DS_Store" -delete
find "${BUILD_DIR}" -type f -name ".phpcs.xml" -delete
find "${BUILD_DIR}" -type f -name ".editorconfig" -delete
find "${BUILD_DIR}" -type f -name ".cursorrules" -delete
find "${BUILD_DIR}" -type f -name ".gitignore" -delete
find "${BUILD_DIR}" -type f -name "*.log" -delete

find "${BUILD_DIR}" -type f \( -name "test-*.php" -o -name "test_*.php" -o -name "debug-*.php" -o -name "debug_*.php" -o -name "check-*.php" -o -name "check_*.php" -o -name "fix-*.php" -o -name "fix_*.php" -o -name "migrate-*.php" -o -name "migrate_*.php" -o -name "auto-*.php" -o -name "auto_*.php" -o -name "manual-*.php" -o -name "manual_*.php" -o -name "direct-*.php" -o -name "direct_*.php" -o -name "clear-*.php" -o -name "clear_*.php" -o -name "run-*.php" -o -name "run_*.php" -o -name "admin-migrate.php" -o -name "ajax_test.php" -o -name "analyze_debug_log.php" -o -name "create_dummy_data.php.bak" -o -name "test-report-ajax.php" -o -name "wp-cli-create-dummy-data.php" \) -delete

find "${BUILD_DIR}" -type f \( -name "test-*.sh" -o -name "test_*.sh" -o -name "*_test.sh" -o -name "*-test.sh" -o -name "create_release_zip.sh" -o -name "run-dummy-data.sh" -o -name "wp-cli.sh" -o -name "wp-cli-aliases.sh" -o -name "setup-wp-cli.sh" \) -delete

find "${BUILD_DIR}" -type f \( -name "README.md" -o -name "*.md" -o -name "*.html" -o -name "debug-progress-chart.html" \) -delete

# 翻訳ソース（実行時は .mo のみ必要）
find "${BUILD_DIR}/languages" -type f \( -name "*.po" -o -name "*.pot" \) -delete 2>/dev/null || true

# デモデータ生成（開発・検証用。本番配布には不要）
rm -f "${BUILD_DIR}/create_dummy_data.php"

find "${BUILD_DIR}" -type f -name ".local-development" -delete
find "${BUILD_DIR}" -type f -name "DEVELOPMENT-ENVIRONMENT-SETUP.md" -delete
find "${BUILD_DIR}" -type f -name "development-config.php" -delete

find "${BUILD_DIR}" -type f \( -name "*-test.js" -o -name "*-debug.js" -o -name "*-fixed.js" -o -name "*-test.css" -o -name "*-debug.css" -o -name "*-fixed.css" -o -name "test-*.js" -o -name "debug-*.js" -o -name "fix-*.js" -o -name "test-*.css" -o -name "debug-*.css" -o -name "fix-*.css" -o -name "service-fix.*" -o -name "*debug-helper.js" -o -name "cost-toggle-debug-helper.js" -o -name "cost-toggle-debug.js" -o -name "implementation-test.js" -o -name "ktp-calculation-debug.js" -o -name "ktp-calculation-monitor.js" -o -name "ktp-calculation-test.js" -o -name "ktp-cost-toggle-test.js" -o -name "ktp-js-backup-*.js" -o -name "ktp-js-fixed.js" -o -name "ktp-js-working.js" -o -name "ktp-js.js.bak" -o -name "plugin-reference.js" -o -name "progress-select.js" -o -name "service-fix.js" -o -name "test-both-toggles.js" -o -name "test-staff-chat-scroll.js" -o -name "ktp-invoice-items.js.bak" \) -delete

find "${BUILD_DIR}" -type d -name "KantanPro_temp" -exec rm -rf {} + 2>/dev/null || true
find "${BUILD_DIR}" -type d -name "KantanProEX_temp" -exec rm -rf {} + 2>/dev/null || true
find "${BUILD_DIR}" -type d -name "wp" -exec rm -rf {} + 2>/dev/null || true
find "${BUILD_DIR}" -type d -name "wp-content" -exec rm -rf {} + 2>/dev/null || true
if [ -d "${BUILD_DIR}/images/upload" ]; then
    find "${BUILD_DIR}/images/upload" -mindepth 1 -delete 2>/dev/null || true
fi

AFTER_COUNT=$(find "${BUILD_DIR}" -type f | wc -l)
DELETED_COUNT=$((BEFORE_COUNT - AFTER_COUNT))
echo "  - 削除されたファイル数: ${DELETED_COUNT}"
echo "  - 配布版ファイル数: ${AFTER_COUNT}"
echo "  - 完了"

echo -e "\n[7/8] 配布サイト用の最終クリーンアップ中..."
find "${BUILD_DIR}" -type f -name "*.zip" -delete
find "${BUILD_DIR}" -type f -name "*.bak" -delete
find "${BUILD_DIR}" -type f -name "*.tmp" -delete
find "${BUILD_DIR}" -type f -name "*.temp" -delete
find "${BUILD_DIR}" -type f -name "*.old" -delete
find "${BUILD_DIR}" -type f -name "*.orig" -delete
echo "  - 完了"

echo -e "\n[7.5/8] JS/CSS を最小化中（配布ビルドのみ・ソースは変更しません）..."
# 縮小は「安全側」に倒す:
#   - 行コメント(//) はURL(http://)や正規表現を壊しうるため触らない
#   - ブロックコメント(/* */) と行頭・行末の空白、空行のみを削除する
# これだけでも未圧縮の JS 約1.2MB / CSS 約230KB から確実に削れる。
MINIFY_BEFORE=$(find "${BUILD_DIR}" \( -name "*.js" -o -name "*.css" \) -type f -exec cat {} + 2>/dev/null | wc -c | tr -d ' ' || echo 0)

minify_asset_file() {
    local target="$1"
    local tmp="${target}.min.tmp"

    # 空ファイル・空行のみのファイルは対象外（grep が 1 を返し set -e で落ちるため）
    if [ ! -s "$target" ]; then
        return 0
    fi

    # /* ... */ を削除 → 行頭/行末空白を除去 → 空行を除去
    # set -e / pipefail 環境でも中断しないよう、失敗しても続行する
    { perl -0777 -pe 's{/\*.*?\*/}{}gs' "$target" \
        | sed -E 's/^[[:space:]]+//; s/[[:space:]]+$//' \
        | grep -v '^$' > "$tmp" 2>/dev/null; } || true

    # 生成物が空、または元より大きい場合は縮小せず元を残す（安全弁）
    if [ -s "$tmp" ] && [ "$(wc -c < "$tmp")" -le "$(wc -c < "$target")" ]; then
        mv "$tmp" "$target"
    else
        rm -f "$tmp"
    fi

    return 0
}

MINIFIED_COUNT=0
while IFS= read -r -d '' asset; do
    minify_asset_file "$asset"
    MINIFIED_COUNT=$((MINIFIED_COUNT + 1))
done < <(find "${BUILD_DIR}" \( -name "*.js" -o -name "*.css" \) -type f -print0)

MINIFY_AFTER=$(find "${BUILD_DIR}" \( -name "*.js" -o -name "*.css" \) -type f -exec cat {} + 2>/dev/null | wc -c | tr -d ' ' || echo 0)
echo "  - 対象ファイル数: ${MINIFIED_COUNT}"
echo "  - サイズ: ${MINIFY_BEFORE} バイト → ${MINIFY_AFTER} バイト"
echo "  - 完了"

echo -e "\n[8/10] ZIPファイルを作成中..."
(cd "${BUILD_DIR}/.." && zip -r -q "${FINAL_ZIP_PATH}" "${BUILD_DIR_NAME}")

if [ $? -eq 0 ]; then
    echo -e "\n[9/10] 最終検証を実行中..."

    if unzip -t "${FINAL_ZIP_PATH}" > /dev/null 2>&1; then
        echo "  ✅ ZIPファイルの整合性: 正常"
    else
        echo "  ❌ ZIPファイルの整合性: エラー"
        exit 1
    fi

    ZIP_SIZE=$(ls -lh "${FINAL_ZIP_PATH}" | awk '{print $5}')
    ZIP_SIZE_BYTES=$(ls -l "${FINAL_ZIP_PATH}" | awk '{print $5}')
    echo "  ✅ ZIPファイルサイズ: ${ZIP_SIZE}"

    if [ "$ZIP_SIZE_BYTES" -lt 3145728 ]; then
        echo "  ✅ ファイルサイズ: 3MB未満（WordPress標準アップロード上限内）"
    else
        echo "  ❌ ファイルサイズ: 3MB以上（${ZIP_SIZE}）— 一般WordPressではインストール不可の可能性"
        exit 1
    fi

    if [ "$ZIP_SIZE_BYTES" -ge 1048576 ] && [ "$ZIP_SIZE_BYTES" -le 2097152 ]; then
        echo "  ✅ ファイルサイズ: 1-2MBの推奨範囲内"
    else
        echo "  ⚠️  ファイルサイズ: 1-2MBの推奨範囲外（${ZIP_SIZE}）"
    fi

    if unzip -l "${FINAL_ZIP_PATH}" | grep -q "ktpwp.php"; then
        echo "  ✅ メインプラグインファイル: 存在"
    else
        echo "  ❌ メインプラグインファイル: 見つかりません"
        exit 1
    fi

    if unzip -l "${FINAL_ZIP_PATH}" | grep -q "readme.txt"; then
        echo "  ✅ readme.txt: 存在"
    else
        echo "  ❌ readme.txt: 見つかりません"
        exit 1
    fi

    if ! unzip -l "${FINAL_ZIP_PATH}" | grep -q "debug-"; then
        echo "  ✅ デバッグファイル: 適切に除外"
    else
        echo "  ⚠️  デバッグファイル: 一部が残っています"
    fi

    if ! unzip -l "${FINAL_ZIP_PATH}" | grep -q ".local-development"; then
        echo "  ✅ 開発環境マーカー: 適切に除外"
    else
        echo "  ❌ 開発環境マーカー: 含まれています（セキュリティリスク）"
        exit 1
    fi

    if ! unzip -l "${FINAL_ZIP_PATH}" | grep -q "\.md$"; then
        echo "  ✅ ドキュメントファイル: 適切に除外"
    else
        echo "  ⚠️  ドキュメントファイル: 一部が残っています"
    fi

    echo -e "\n[10/10] 配布前の安全チェックを実行中..."
    if unzip -l "${FINAL_ZIP_PATH}" | grep -q "wp-config.php"; then
        echo "  ❌ ZIPに wp-config.php が含まれています。配布禁止。"
        exit 1
    else
        echo "  ✅ ZIPに wp-config.php は含まれていません（推奨）"
    fi

    if grep -RIEq "define\s*\(\s*['\"]KTPWP_DEVELOPMENT_MODE['\"]\s*,\s*true\s*\)" "${BUILD_DIR}"; then
        echo "  ❌ プラグイン内で KTPWP_DEVELOPMENT_MODE を true に定義しています。配布禁止。"
        exit 1
    else
        echo "  ✅ プラグイン内で KTPWP_DEVELOPMENT_MODE を true に定義していません（OK）"
    fi

    if grep -RIEq "8bee1222|\$2y\$10\$92IXUNpkjO0rOQ5byMi\.Ye4oKoEa3Ro9llC/.og/at2\.uheWG/igi" "${BUILD_DIR}"; then
        echo "  ❌ 旧開発者パスワード関連の文字列が残存しています。配布禁止。"
        exit 1
    else
        echo "  ✅ 旧開発者パスワード関連の文字列は検出されませんでした（OK）"
    fi

    if grep -q "define( 'KTPWP_EDITION', '${EDITION}' )" "${BUILD_DIR}/ktpwp.php"; then
        echo "  ✅ KTPWP_EDITION: ${EDITION}"
    else
        echo "  ❌ KTPWP_EDITION の注入に失敗しました"
        exit 1
    fi

    if grep -q "define( 'KTPWP_STAFF_LIMIT', ${STAFF_LIMIT} )" "${BUILD_DIR}/ktpwp.php"; then
        echo "  ✅ KTPWP_STAFF_LIMIT: ${STAFF_LIMIT}"
    else
        echo "  ❌ KTPWP_STAFF_LIMIT の注入に失敗しました"
        exit 1
    fi

    rm -rf "${BUILD_DIR}"
    echo "  ✅ 一時ファイル: クリーンアップ完了"

    echo -e "\n--------------------------------------------------"
    echo "✅ KantanProEX 配布サイト用ビルドプロセスが正常に完了しました！"
    echo "エディション: ${EDITION} (${PLUGIN_NAME})"
    echo "ZIPファイル: ${FINAL_ZIP_PATH}"
    echo "ファイルサイズ: ${ZIP_SIZE}"
    echo "解凍後フォルダ: ${BUILD_DIR_NAME}"
    echo ""
    echo "⚠️  GitHub Release 向けの注意:"
    echo "  - 各エディション ZIP を同一タグの Release assets に添付すると、WordPress からワンクリック更新できます。"
    echo "  - pro のみ asset 未添付時は zipball にフォールバックします（既存ユーザー救済）。"
    echo "  - solo / team / business は対応するエディション ZIP asset が必須です。"
    echo "  - ローカル一括ビルド: ./scripts/build-all-release-zips.sh"
    echo "  - CI 添付: .github/workflows/release-assets.yml（Release 公開時に自動ビルド）"
    echo "--------------------------------------------------"
else
    echo -e "\n❌ ZIPファイルの作成に失敗しました。"
    exit 1
fi
