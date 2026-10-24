<?php
/**
 * 無料版 KantanPro のデータを KantanProEX へ引き継ぐ
 *
 * 無料版は 1.3.49 から、wp.org の審査（接頭辞は 4 文字以上）に合わせて
 * テーブルとオプションの接頭辞を ktp_ から ktpwp_ に変えた。
 *   無料版: wp_ktpwp_order / ktpwp_general_settings
 *   EX    : wp_ktp_order   / ktp_general_settings（従来どおり）
 *
 * 無料版から EX に移ると、EX は wp_ktp_* を読むので無料版のデータが見えなくなる。
 * そこで、無料版のテーブル（wp_ktpwp_*）が残っていれば wp_ktp_* へ名前を戻し、
 * 名前の変わったオプションも ktp_* へコピーする。
 *
 * - テーブルは RENAME TABLE で名前を変えるだけ（コピーしない）。件数が多くても一瞬で終わる。
 * - EX 側の同名テーブルが空（EX を入れた直後）なら、それを消してから名前を戻す。
 * - 両方にデータがある場合は何もしない（上書きで消さない）。管理画面に通知して止める。
 * - 既存の EX ユーザーには wp_ktpwp_* が無いので何も起きない。
 *
 * EX 独自の列は、このあと走る有効化処理（ktpwp_safe_table_setup の dbDelta）が追加する。
 *
 * @package KTPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'KTPWP_EX_Free_Data_Import' ) ) {

	final class KTPWP_EX_Free_Data_Import {

		/** 確認済みの EX バージョン（バージョンが変わったら 1 回だけ確認し直す） */
		const CHECKED_OPTION = 'ktpwp_ex_free_data_import_checked';

		/** 両方にデータがあって引き継がなかったテーブル */
		const CONFLICT_OPTION = 'ktpwp_ex_free_data_import_conflicts';

		/** 引き継いだ結果（管理画面の通知用） */
		const RESULT_TRANSIENT = 'ktpwp_ex_free_data_import_result';

		/**
		 * 無料版 1.3.49 で ktp_ から ktpwp_ に名前が変わったオプション（無料版 tools/ktp-prefix-renamed.json から生成）。
		 * EX ではこの ktp_ の名前で読む。
		 *
		 * @var string[]
		 */
		private static $renamed_options = array(
			'ktp_activation_key', 'ktp_active_edition', 'ktp_atena_label_position', 'ktp_banner_options',
			'ktp_central_banner_settings', 'ktp_cleanup_unused_columns_timestamp',
			'ktp_client_category_migration_done', 'ktp_company_info_migrated',
			'ktp_contract_payment_due_mode_migration_completed', 'ktp_contract_recurring_items_migration_completed',
			'ktp_contract_stripe_subscription_migration_completed', 'ktp_contract_tables_migration_completed',
			'ktp_department_table_version', 'ktp_design_settings', 'ktp_dev_license_enabled',
			'ktp_developer_edition_override', 'ktp_donation_settings', 'ktp_fm_ai_zip_import', 'ktp_fm_import_ai',
			'ktp_fm_import_openai', 'ktp_fm_import_openai_key_enc', 'ktp_fm_import_run', 'ktp_fm_import_upload',
			'ktp_general_settings', 'ktp_invoice_billing_scope_migration_completed', 'ktp_japanpost_api_settings',
			'ktp_license_info', 'ktp_license_key', 'ktp_license_requests', 'ktp_license_site_url',
			'ktp_license_status', 'ktp_license_verified_at', 'ktp_logo_image',
			'ktp_order_contract_fields_migration_completed', 'ktp_order_migration_20250108_invoice_items_completed',
			'ktp_order_migration_20250108_invoice_items_timestamp', 'ktp_order_migration_20250703_completed',
			'ktp_order_migration_20250703_timestamp', 'ktp_order_stripe_subscription_migration_completed',
			'ktp_order_table_version', 'ktp_pdf_document_settings', 'ktp_pdf_issuer_address', 'ktp_pdf_issuer_name',
			'ktp_pdf_layout', 'ktp_pdf_logo_path', 'ktp_pdf_seal_path',
			'ktp_service_contract_billing_cycle_migration_completed', 'ktp_service_initial_fees_migration_completed',
			'ktp_service_is_public_migration_completed', 'ktp_service_public_html_migration_completed',
			'ktp_service_public_instant_purchase_migration_completed',
			'ktp_service_public_quantity_fixed_migration_completed', 'ktp_service_stock_migration_completed', 'ktp_service_stock_managed_migration_completed', 'ktp_service_stock_managed_backfill_completed',
			'ktp_service_tax_rate_migration_completed', 'ktp_settings', 'ktp_settings_migrated', 'ktp_smtp_settings',
			'ktp_strict_cleanup_timestamp', 'ktp_supplier_migration_20250703_completed',
			'ktp_supplier_skills_table_version', 'ktp_sync_production_to_local_structure_timestamp', 'ktp_tax_policy',
			'ktp_ultra_strict_cleanup_unused_columns_timestamp', 'ktp_uninstall_settings',
			'ktp_update_notification_settings',
		);

		/**
		 * plugins_loaded: EX のバージョンが変わったときに 1 回だけ確認する。
		 *
		 * @return void
		 */
		public static function maybe_run() {
			$version = defined( 'KANTANPRO_PLUGIN_VERSION' ) ? (string) KANTANPRO_PLUGIN_VERSION : '';
			if ( get_option( self::CHECKED_OPTION ) === $version ) {
				return;
			}
			// 無料版がまだ有効なら、無料版が wp_ktpwp_* を使っている。EX 側の処理（無料版の停止）を待つ。
			if ( function_exists( 'ktpwp_ex_is_free_plugin_active' ) && ktpwp_ex_is_free_plugin_active() ) {
				return;
			}

			$imported = self::run();
			update_option( self::CHECKED_OPTION, $version, false );

			// 有効化を経ずに名前を戻した場合は、EX 独自の列をここで足す
			if ( $imported > 0 && function_exists( 'ktpwp_safe_table_setup' ) ) {
				ktpwp_safe_table_setup();
			}
		}

		/**
		 * 引き継ぎを実行する。有効化処理（テーブル作成の前）からも呼ぶ。
		 *
		 * @return int 名前を戻したテーブルの数。
		 */
		public static function run() {
			global $wpdb;

			$source_prefix = $wpdb->prefix . 'ktpwp_';
			$target_prefix = $wpdb->prefix . 'ktp_';
			$sources       = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $source_prefix ) . '%' ) );
			if ( empty( $sources ) ) {
				return 0;
			}

			$renamed      = array();
			$conflicts    = array();
			$ex_had_data  = false;

			foreach ( $sources as $source ) {
				$suffix = substr( (string) $source, strlen( $source_prefix ) );
				// 識別子は英小文字・数字・_ のみ受け付ける（下の SQL に埋め込むため）
				if ( $suffix === '' || ! preg_match( '/^[a-z0-9_]+$/', $suffix ) || strpos( $suffix, 'migration_test' ) === 0 ) {
					continue;
				}
				$target = $target_prefix . $suffix;

				$source_rows = self::count_rows( $source );
				if ( self::table_exists( $target ) ) {
					$target_rows = self::count_rows( $target );
					// 利用規約は各製品が既定の文面を入れるテーブル。EX 側にあればそれを使い、引き継ぎ対象にしない
					if ( $target_rows > 0 && $suffix === 'terms_of_service' ) {
						continue;
					}
					if ( $target_rows > 0 ) {
						$ex_had_data = true;
						if ( $source_rows > 0 ) {
							$conflicts[] = $suffix;
						}
						// EX 側にデータがあるテーブルは触らない
						continue;
					}
					// EX を入れた直後の空テーブル。無料版のテーブルと入れ替える
					$wpdb->query( 'DROP TABLE `' . esc_sql( $target ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- 識別子は英数字と _ のみを検証済み。
				}

				$ok = $wpdb->query( 'RENAME TABLE `' . esc_sql( $source ) . '` TO `' . esc_sql( $target ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- 同上。
				if ( $ok !== false ) {
					$renamed[] = $suffix;
				}
			}

			if ( $renamed !== array() ) {
				// EX にまだデータが無かった（＝無料版から移ってきた）なら、無料版の設定を優先する
				self::copy_options( ! $ex_had_data );
				// 無料版のサービス表には「在庫を管理する」列が無い。次の読み込みで列の追加をやり直す
				if ( in_array( 'service', $renamed, true ) ) {
					delete_option( 'ktp_service_stock_managed_migration_completed' );
					delete_option( 'ktp_service_stock_managed_backfill_completed' );
				}
				set_transient( self::RESULT_TRANSIENT, count( $renamed ), DAY_IN_SECONDS );
			}

			if ( $conflicts !== array() ) {
				update_option( self::CONFLICT_OPTION, $conflicts, false );
			}

			return count( $renamed );
		}

		/**
		 * ktpwp_* に名前が変わったオプションを ktp_* へコピーする（元の ktpwp_* は残す）。
		 *
		 * @param bool $overwrite EX 側に同名の値があっても無料版の値で上書きするか。
		 * @return void
		 */
		private static function copy_options( $overwrite ) {
			global $wpdb;

			$names = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
					$wpdb->esc_like( 'ktpwp_' ) . '%'
				)
			);
			$missing = new stdClass();

			foreach ( (array) $names as $name ) {
				$legacy = 'ktp_' . substr( (string) $name, strlen( 'ktpwp_' ) );
				if ( ! self::is_renamed_option( $legacy ) ) {
					continue;
				}
				$current = get_option( $legacy, $missing );
				if ( $current !== $missing && ! $overwrite ) {
					continue;
				}
				update_option( $legacy, get_option( $name ) );
			}
		}

		/**
		 * 無料版で名前が変わったオプションか。
		 *
		 * 名前を組み立てて作るオプション（テーブルごとの版、マイグレーション済みフラグ）は形で判定する。
		 *
		 * @param string $legacy ktp_ で始まる名前。
		 * @return bool
		 */
		private static function is_renamed_option( $legacy ) {
			if ( in_array( $legacy, self::$renamed_options, true ) ) {
				return true;
			}
			return (bool) preg_match( '/^ktp_(?:[a-z0-9_]+_table_version|migration_[0-9a-f]{32}(?:_timestamp)?|[a-z0-9_]+_(?:completed|timestamp|done|migrated)|stripe_setup_url_[a-z0-9_]+)$/', $legacy );
		}

		/**
		 * @param string $table テーブル名。
		 * @return bool
		 */
		private static function table_exists( $table ) {
			global $wpdb;
			return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
		}

		/**
		 * @param string $table テーブル名（英数字と _ のみを検証済み）。
		 * @return int
		 */
		private static function count_rows( $table ) {
			global $wpdb;
			return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . esc_sql( $table ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- 識別子は検証済み。
		}

		/**
		 * 管理画面の通知（引き継ぎ結果 / 引き継げなかったテーブル）。
		 *
		 * @return void
		 */
		public static function admin_notices() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			$count = get_transient( self::RESULT_TRANSIENT );
			if ( $count ) {
				delete_transient( self::RESULT_TRANSIENT );
				echo '<div class="notice notice-success is-dismissible"><p>'
					. esc_html(
						sprintf(
							/* translators: %d: number of tables */
							__( '無料版 KantanPro のデータ（%d テーブル）を KantanProEX に引き継ぎました。', 'ktpwp' ),
							(int) $count
						)
					)
					. '</p></div>';
			}

			$conflicts = get_option( self::CONFLICT_OPTION );
			if ( is_array( $conflicts ) && $conflicts !== array() ) {
				echo '<div class="notice notice-warning"><p>'
					. esc_html__( '無料版 KantanPro のデータが残っていますが、KantanProEX 側にもデータがあるため自動では引き継いでいません（上書きでデータが消えるのを防ぐため）。対象:', 'ktpwp' )
					. ' ' . esc_html( implode( ', ', $conflicts ) )
					. '</p></div>';
			}
		}
	}
}
