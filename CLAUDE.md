# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

**KantanProEX** — the **paid** edition of the KTPWP WordPress plugin (business management: clients, orders/invoices, services, contracts/recurring billing, suppliers, reports, staff chat). Installed on a page via the `[ktpwp_all_tab]` shortcode (also `[kantanpro_ex]`).

This is one of **three separate products** in this workspace — never conflate them:

| Name | Type | Local path | GitHub |
|---|---|---|---|
| **KantanProEX (WP)** — this repo | WordPress plugin, **paid** | `wordpress/wp-content/plugins/KantanProEX` | `KantanPro/KantanProEx` |
| **KantanPro (WP)** | WordPress plugin, **free** | `wordpress/wp-content/plugins/KantanPro` | `KantanPro/KantanPro` |
| **KantanBiz** | Laravel SaaS | `kantanpro-saas/` | separate product, not WordPress |

Version numbers are on separate tracks per product (e.g. free `1.2.x`, this repo currently `1.4.x`). Don't describe a release as "KantanPro" alone — always say "無料版" or "有料版(EX)" explicitly, since folder/plugin names are easy to mix up.

## Editions within this repo

A single codebase produces **4 build variants**, gated by constants defined in `ktpwp.php` (`KTPWP_EDITION`, `KTPWP_STAFF_LIMIT`, `KANTANPRO_PLUGIN_NAME`) per `includes/class-ktpwp-edition.php`:

| slug | plugin_name | staff_limit |
|---|---|---|
| `pro` | `KantanProEX` | 0 (unlimited) |
| `business` | `KantanProEXbusiness` | 15 |
| `team` | `KantanProEXteam` | 5 |
| `solo` | `KantanProEXsolo` | 1 |

When changing edition-gating logic, all 4 variants need to stay correct — check `KTPWP_Edition` before assuming a feature is universally available.

## Commands

No automated PHPUnit/test suite in this repo — verify changes by running the plugin against a local WordPress + WP-CLI environment (`wp-cli.phar`, aliases in `wp-cli-aliases.sh`, see `QUICK-START.md`). `wp-cli.yml` and the aliases file reference a local dev path that may be stale for your machine — check before relying on it. `create_dummy_data.php` / `wp-cli-create-dummy-data.php` seed sample data for manual testing.

```bash
composer install                    # dev deps: PHPCS + WPCS
composer phpcs                      # WordPress Coding Standards check (phpcs --standard=./.phpcs.xml)

./scripts/build-all-release-zips.sh # builds all 4 edition ZIPs → ~/Desktop/KantanProEX_TEST_UP/
```

`.phpcs.xml` excludes `vendor/`, `tests/`, `js/`, `css/`, docs, and various debug/fix scripts from linting.

## Architecture

- **`ktpwp.php`** (~7500 lines) is the plugin bootstrap: header/version, edition constant definitions, activation/upgrade hooks, admin notices, and a fair amount of business logic. It's large — when hunting for behavior, also check `includes/` before assuming it's only in `ktpwp.php`.
- **`includes/class-ktpwp-*.php`** (~100 files) holds the actual feature classes, one class per concern, mostly singletons. Naming maps directly to feature area, e.g.:
  - Client: `class-ktpwp-client*.php` | Order: `class-ktpwp-order*.php` | Service: `class-ktpwp-service*.php` | Supplier: `class-ktpwp-supplier*.php`
  - Contracts/recurring billing: `class-ktpwp-contract*.php` (billing cycle, recurring items, invoice mail, reminder mail)
  - PDF/branding/print: `class-ktpwp-pdf-*.php`, `class-ktpwp-print.php`
  - Stripe: `class-ktpwp-stripe-billing.php`, `class-ktpwp-stripe-subscription.php`
  - Public-facing (non-logged-in) purchase flow: `class-ktpwp-public-product-order.php`, `class-ktpwp-public-purchase-thank-you.php`
  - Cross-cutting: `class-ktpwp-security.php`, `class-ktpwp-nonce-manager.php`, `class-ktpwp-cache.php` / `class-ktpwp-schema-cache.php`, `class-ktpwp-hook-manager.php`, `class-ktpwp-loader.php`
- **`includes/ajax-*.php`** and AJAX handling in `class-ktpwp-ajax.php` back the tab UI's dynamic interactions (admin-ajax based, not REST).
- **DB / migrations**: custom lightweight migration system, not a framework ORM. `includes/migrations/*.php` are dated one-off migration scripts; `includes/ktp-migration-cli.php` registers WP-CLI commands (`wp ktp migrate_table`, `wp ktpwp migrate_all`) that `glob()` and run them in filename (date) order. Table creation lives in `class-ktpwp-database.php` (`table_classes` maps table → owning class, e.g. `client` → `KTPWP_Client_Class`). New schema changes: add a new dated file under `includes/migrations/`, don't edit old ones.
- **Frontend**: plain jQuery-style JS per feature in `js/ktp-*.js` (no bundler/build step — enqueued directly via `class-ktpwp-assets.php`, which conditionally enqueues per-screen to avoid loading everything everywhere). CSS similarly per-feature under `css/`.
- **Print/PDF**: `ktp-atena-print.js`, `ktp-bulk-invoice-print.js` etc. handle browser print and html2canvas/jsPDF-based PDF capture for envelopes/bulk invoices — mirrors the same "PC keeps HTML/browser print, mobile/iPad gets PDF share" split used in KantanBiz's print rules; check existing print JS for the established pattern for a screen before changing it.

## Conventions

**Trimmed decimal display** — never show a trailing `.00`/`.50` unless the user actually entered a fraction (`50000.00` → `50000`/`50,000円`, `10.5` stays `10.5`):
- PHP: `KTPWP_Settings::format_money()` (money), `format_decimal_trimmed()` (rates/quantities/unit prices), `format_number_field_value()` (`<input type="number">` value). Never use raw `number_format($x, 2)` or cast a DB `DECIMAL` straight to string for display.
- JS: `KTPNumberFormat.decimal(value)` / `formatDecimalDisplay(value)` from `js/ktp-number-format.js`. Never hardcode `.toFixed(2)` for display.
- Exceptions: file sizes, chart percentages, other deliberately-fixed-decimal UI. Internal calculations/DB storage stay float/decimal — only the display layer trims.

**WordPress conventions**: favor hooks (actions/filters) over touching core; sanitize input / escape output; use `$wpdb->prepare()` for queries; nonce-verify form submissions and AJAX handlers; use `wp_enqueue_script`/`wp_enqueue_style` (never inline `<script>`/`<link>` for plugin assets); schema changes go through the migration system above, not ad-hoc `dbDelta()` calls scattered around.

**Docker/local env**: don't modify Docker, MySQL, or `wp-config.php` settings as a side effect of a feature change.

## Releases

This repo ships as **4 edition ZIPs** (pro/solo/team/business) via `./scripts/build-all-release-zips.sh`, released to GitHub `KantanPro/KantanProEx`. Full step-by-step (version bump locations, ZIP accept criteria, GitHub Release asset naming, required completion report format) is in `.cursor/rules/release-workflow-bundle.mdc` — read it before doing a release rather than improvising the steps. Key points:
- A **release request often covers both products** (free `KantanPro` ZIP + this repo's 4 ZIPs) unless the user says "EX のみ" (EX only) — don't silently drop the free-version half of a bundled release.
- Version bump locations here: `ktpwp.php` `Version` header, `readme.txt` `Stable tag` + Japanese changelog.
- ZIP must not include `.md` files, `vendor/`, `create_dummy_data.php`, `*.po`/`*.pot`, or nested `*.zip`; must be under 3MB; edition constants (`KTPWP_EDITION`/`KTPWP_STAFF_LIMIT`/`KANTANPRO_PLUGIN_NAME`) must match the variant.
- Never release without the required "✅ リリース完了" / "❌ リリース未実施" summary line the rule specifies.

### One-line release triggers

When the user types one of these **alone as their whole message**, treat it as the full release request — read `.cursor/rules/release-workflow-bundle.mdc` and run that section end-to-end without asking for the details again. Don't ask "which version number?" — derive it (see below) and proceed.

| 入力 | 意味 | 実施範囲 |
|---|---|---|
| `リリースA` / `リリースEX` | 手順書の **A. EX のみ GitHub Release** | 有料版のみ。無料版は対象外 |
| `リリースB` / `リリース一式` | 手順書の **B. フリー版 ZIP + EX Release まとめて（配布一式）** | 無料版 + 有料版の両方 |

Repos and absolute paths (the rule body's older relative paths are wrong — use these):

| 版 | ローカルパス | GitHub | asset |
|---|---|---|---|
| 無料版 KantanPro | `/Users/kantanpro/Desktop/KantanPro-free-test/wp-content/plugins/KantanPro` | `KantanPro/KantanPro` | ZIP 1件 |
| 有料版 KantanProEX | `/Users/kantanpro/Desktop/KantanPro/wordpress/wp-content/plugins/KantanProEX` | `KantanPro/KantanProEx` | ZIP 4件 |

Fixed behavior for both triggers, so a one-liner is enough:
- **Version number**: bump patch from the current `ktpwp.php` `Version`. If the local `Version` is already ahead of the newest GitHub Release (an unreleased bump), don't reuse that number — bump past it. 無料 1.3.x / 有料 1.4.x は別系統。
- **Version bump locations**: 無料は `ktpwp.php` / `readme.txt`（Stable tag + changelog）/ `plugin_config.json` の `default_version` の3ファイル。有料は前2つ。
- **Changelog / release notes**: 日本語。エンドユーザー向けに「何が直ったか」を書く（内部関数名は書かない）。
- **Commit**: 日本語メッセージ + `git push origin main`、その後 `git tag v{version} && git push origin v{version}`。
- **Verify before reporting**: ZIP 検証（`unzip -t` / 3MB未満 / 同梱 Version 一致 / 除外ファイル / エディション定数）を実行し、有料版は公開後の `release-assets.yml` が success か `gh run list` で確認する。
- **Report**: 手順書の「回答形式（必須）」どおり。冒頭に ✅/❌、両版の URL・zipballUrl・asset・ローカル ZIP パス・解凍後フォルダ名・コミットハッシュ。

Only stop and ask if a genuine blocker appears (build fails, acceptance check fails, working tree has unrelated uncommitted changes).

## Commit messages

Always write commit messages in Japanese, concise form like `〇〇を追加` / `〇〇を修正` / `〇〇のバグを修正` — never English one-liners (`fix: foo`). Applies to Cursor SCM/agent-generated messages too.

## Workflow

For multi-step implementation work (a roadmap, continuing a previously discussed design, through tests/docs/commit), keep going through the reasonable full sequence without stopping to ask "should I continue?" — only pause to confirm on a genuine fork (mutually exclusive choices, or a large design change).
