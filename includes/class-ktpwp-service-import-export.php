<?php
/**
 * サービス（商品）のインポート／エクスポート
 *
 * @package KantanProEX
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KTPWP_Service_Import_Export
 */
final class KTPWP_Service_Import_Export {

	public const NONCE_EXPORT = 'ktp_service_export';
	public const NONCE_IMPORT = 'ktp_service_import';

	private const MAX_ROWS         = 5000;
	private const MAX_IMPORT_BYTES = 10485760; // 10 MiB

	/**
	 * @return void
	 */
	public static function bootstrap(): void {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;

		add_action( 'admin_post_ktp_service_export', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_ktp_service_import', array( __CLASS__, 'handle_import' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ), 20 );
	}

	/**
	 * @return bool
	 */
	private static function user_can_manage(): bool {
		return current_user_can( 'edit_posts' ) || current_user_can( 'ktpwp_access' );
	}

	/**
	 * @return string[]
	 */
	public static function export_field_keys(): array {
		return array(
			'id',
			'service_name',
			'price',
			'tax_rate',
			'unit',
			'category',
			'is_public',
			'memo',
			'image_url',
			'image_base64',
			'contract_billing_cycle',
			'stock',
			'public_quantity_fixed',
			'public_instant_purchase',
			'public_html',
		);
	}

	/**
	 * @return array<string, string>
	 */
	public static function export_field_labels(): array {
		return array(
			'id'                      => __( 'ID', 'ktpwp' ),
			'service_name'            => __( 'サービス名', 'ktpwp' ),
			'price'                   => __( '単価', 'ktpwp' ),
			'tax_rate'                => __( '税率（%）', 'ktpwp' ),
			'unit'                    => __( '単位', 'ktpwp' ),
			'category'                => __( 'カテゴリー', 'ktpwp' ),
			'is_public'               => __( '公開状態', 'ktpwp' ),
			'memo'                    => __( 'メモ', 'ktpwp' ),
			'image_url'               => __( '画像URL', 'ktpwp' ),
			'image_base64'            => __( '画像データ（Base64）', 'ktpwp' ),
			'contract_billing_cycle'  => __( '契約課金サイクル', 'ktpwp' ),
			'stock'                   => __( '在庫', 'ktpwp' ),
			'public_quantity_fixed'   => __( '公開数量固定', 'ktpwp' ),
			'public_instant_purchase' => __( '即時購入', 'ktpwp' ),
			'public_html'             => __( '公開HTML', 'ktpwp' ),
		);
	}

	/**
	 * @return string[]
	 */
	public static function get_category_options(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'ktp_service';
		$rows  = $wpdb->get_col( "SELECT DISTINCT category FROM {$table} WHERE category IS NOT NULL AND category <> '' ORDER BY category ASC" );
		if ( ! is_array( $rows ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'strval', $rows ) ) );
	}

	/**
	 * コントローラー用ボタン HTML
	 *
	 * @return string
	 */
	public static function render_controller_buttons(): string {
		if ( ! self::user_can_manage() ) {
			return '';
		}

		$import_label = esc_html__( 'インポート', 'ktpwp' );
		$export_label = esc_html__( 'エクスポート', 'ktpwp' );

		return '<div class="ktp-service-controller-actions" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">'
			. '<button type="button" class="ktp-service-import-btn" style="padding:6px 12px;border:1px solid #ddd;border-radius:4px;background:#fff;cursor:pointer;font-size:12px;">'
			. esc_html( $import_label )
			. '</button>'
			. '<button type="button" class="ktp-service-export-btn" style="padding:6px 12px;border:1px solid #ddd;border-radius:4px;background:#fff;cursor:pointer;font-size:12px;">'
			. esc_html( $export_label )
			. '</button>'
			. '</div>';
	}

	/**
	 * モーダル HTML
	 *
	 * @return string
	 */
	public static function render_modal_markup(): string {
		if ( ! self::user_can_manage() ) {
			return '';
		}

		$categories = self::get_category_options();
		$export_url = admin_url( 'admin-post.php' );
		$import_url = admin_url( 'admin-post.php' );
		$redirect   = self::get_redirect_url();

		ob_start();
		?>
		<div id="ktp-service-import-export-modal" class="ktpwp-modal" style="display:none;" aria-hidden="true">
			<div class="ktpwp-modal-overlay">
				<div class="ktpwp-modal-content" role="dialog" aria-modal="true" aria-labelledby="ktp-service-ie-modal-title">
					<div class="ktpwp-modal-header">
						<h3 id="ktp-service-ie-modal-title"><?php esc_html_e( 'サービス エクスポート', 'ktpwp' ); ?></h3>
						<button type="button" class="ktpwp-modal-close ktp-service-ie-close" aria-label="<?php esc_attr_e( '閉じる', 'ktpwp' ); ?>">&times;</button>
					</div>
					<div class="ktpwp-modal-body">
						<form id="ktp-service-export-form" method="post" action="<?php echo esc_url( $export_url ); ?>">
							<input type="hidden" name="action" value="ktp_service_export" />
							<?php wp_nonce_field( self::NONCE_EXPORT, '_ktp_service_export_nonce' ); ?>
							<input type="hidden" name="redirect_to" value="<?php echo esc_url( $redirect ); ?>" />

							<p><label><strong><?php esc_html_e( 'ファイル形式', 'ktpwp' ); ?></strong></label></p>
							<p style="display:flex;flex-wrap:wrap;gap:12px;">
								<label><input type="radio" name="format" value="csv" checked /> CSV</label>
								<label><input type="radio" name="format" value="tsv" /> TSV</label>
								<label><input type="radio" name="format" value="json" /> JSON</label>
								<label><input type="radio" name="format" value="excel" /> Excel (.xls)</label>
								<label><input type="radio" name="format" value="google_sheets" /> <?php esc_html_e( 'Googleスプレッドシート', 'ktpwp' ); ?></label>
							</p>

							<p><label><strong><?php esc_html_e( 'カテゴリー', 'ktpwp' ); ?></strong></label></p>
							<p class="description"><?php esc_html_e( '未選択の場合はすべてのカテゴリーを対象にします。', 'ktpwp' ); ?></p>
							<div class="ktp-service-ie-categories" style="max-height:120px;overflow:auto;border:1px solid #eee;padding:8px;border-radius:4px;margin-bottom:12px;">
								<?php if ( empty( $categories ) ) : ?>
									<span class="description"><?php esc_html_e( 'カテゴリーがありません。', 'ktpwp' ); ?></span>
								<?php else : ?>
									<?php foreach ( $categories as $cat ) : ?>
										<label style="display:block;margin-bottom:4px;">
											<input type="checkbox" name="categories[]" value="<?php echo esc_attr( $cat ); ?>" />
											<?php echo esc_html( $cat ); ?>
										</label>
									<?php endforeach; ?>
								<?php endif; ?>
							</div>

							<p><label for="ktp-service-export-public"><strong><?php esc_html_e( '公開状態', 'ktpwp' ); ?></strong></label></p>
							<select id="ktp-service-export-public" name="is_public_filter" style="min-width:200px;padding:6px 8px;">
								<option value="all"><?php esc_html_e( 'すべて', 'ktpwp' ); ?></option>
								<option value="1"><?php esc_html_e( '公開のみ', 'ktpwp' ); ?></option>
								<option value="0"><?php esc_html_e( '非公開のみ', 'ktpwp' ); ?></option>
							</select>

							<p style="margin-top:12px;">
								<label>
									<input type="checkbox" name="include_images" value="1" checked />
									<?php esc_html_e( '画像も含める（JSON／Excel 推奨。CSV では Base64 列を出力）', 'ktpwp' ); ?>
								</label>
							</p>

							<p class="description ktp-service-ie-google-export-note" style="display:none;">
								<?php esc_html_e( 'Googleスプレッドシート形式は UTF-8 BOM 付き CSV としてダウンロードします。スプレッドシートの「ファイル → インポート」から取り込めます。', 'ktpwp' ); ?>
							</p>

							<p style="margin-top:16px;">
								<button type="submit" class="ktp-service-ie-submit-btn"><?php esc_html_e( 'エクスポート実行', 'ktpwp' ); ?></button>
							</p>
						</form>

						<form id="ktp-service-import-form" method="post" action="<?php echo esc_url( $import_url ); ?>" enctype="multipart/form-data" style="display:none;">
							<input type="hidden" name="action" value="ktp_service_import" />
							<?php wp_nonce_field( self::NONCE_IMPORT, '_ktp_service_import_nonce' ); ?>
							<input type="hidden" name="redirect_to" value="<?php echo esc_url( $redirect ); ?>" />

							<p><label><strong><?php esc_html_e( 'ファイル形式', 'ktpwp' ); ?></strong></label></p>
							<p style="display:flex;flex-wrap:wrap;gap:12px;">
								<label><input type="radio" name="format" value="csv" checked /> CSV</label>
								<label><input type="radio" name="format" value="tsv" /> TSV</label>
								<label><input type="radio" name="format" value="json" /> JSON</label>
								<label><input type="radio" name="format" value="excel" /> Excel (.xls / .xlsx)</label>
								<label><input type="radio" name="format" value="google_sheets" /> <?php esc_html_e( 'Googleスプレッドシート', 'ktpwp' ); ?></label>
							</p>

							<div class="ktp-service-ie-file-field">
								<p><label for="ktp-service-import-file"><strong><?php esc_html_e( 'ファイル', 'ktpwp' ); ?></strong></label></p>
								<input type="file" id="ktp-service-import-file" name="import_file" accept=".csv,.tsv,.tab,.txt,.json,.xls,.xlsx" />
							</div>

							<div class="ktp-service-ie-google-url-field" style="display:none;">
								<p><label for="ktp-service-google-url"><strong><?php esc_html_e( 'Googleスプレッドシート URL', 'ktpwp' ); ?></strong></label></p>
								<input type="url" id="ktp-service-google-url" name="google_sheets_url" class="regular-text" style="width:100%;max-width:100%;padding:6px 8px;" placeholder="https://docs.google.com/spreadsheets/d/..." />
								<p class="description"><?php esc_html_e( 'リンクを知っている全員が閲覧できる設定のシートのみ取り込めます。', 'ktpwp' ); ?></p>
							</div>

							<div class="ktp-service-ie-default-public">
								<p><label for="ktp-service-default-is-public"><strong><?php esc_html_e( '取り込み時の公開状態（列が無い場合）', 'ktpwp' ); ?></strong></label></p>
								<select id="ktp-service-default-is-public" name="default_is_public" class="ktp-service-ie-select">
									<option value="0"><?php esc_html_e( '非公開', 'ktpwp' ); ?></option>
									<option value="1"><?php esc_html_e( '公開', 'ktpwp' ); ?></option>
								</select>
							</div>

							<div class="ktp-service-ie-duplicate-policy">
								<p><label for="ktp-service-duplicate-policy"><strong><?php esc_html_e( 'データのインポート方法', 'ktpwp' ); ?></strong></label></p>
								<select id="ktp-service-duplicate-policy" name="duplicate_policy" class="ktp-service-ie-select">
									<option value="overwrite" selected><?php esc_html_e( '同一IDの場合は上書きする', 'ktpwp' ); ?></option>
									<option value="add_new"><?php esc_html_e( '別サービスとして追加し新IDを付加する', 'ktpwp' ); ?></option>
								</select>
							</div>

							<p style="margin-top:12px;">
								<label>
									<input type="checkbox" name="import_images" value="1" checked class="ktp-service-ie-import-images-toggle" />
									<?php esc_html_e( '画像も取り込む（image_url / image_base64 列）', 'ktpwp' ); ?>
								</label>
							</p>

							<div class="ktp-service-ie-existing-image-policy">
								<p><label for="ktp-service-existing-image-policy"><strong><?php esc_html_e( '同じIDで既に画像がある場合', 'ktpwp' ); ?></strong></label></p>
								<select id="ktp-service-existing-image-policy" name="existing_image_policy" style="min-width:280px;padding:6px 8px;">
									<option value="keep"><?php esc_html_e( '既存の画像を使用する', 'ktpwp' ); ?></option>
									<option value="no_image"><?php esc_html_e( 'ノーイメージにする', 'ktpwp' ); ?></option>
									<option value="import"><?php esc_html_e( 'ファイルの画像で上書きする', 'ktpwp' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'ID列が一致するサービスを更新する際、ディスク上に画像が既にある場合の扱いです。', 'ktpwp' ); ?></p>
							</div>

							<p style="margin-top:16px;">
								<button type="submit" class="ktp-service-ie-submit-btn"><?php esc_html_e( 'インポート実行', 'ktpwp' ); ?></button>
							</p>
						</form>
					</div>
				</div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * インポート結果通知（フローティング表示＋URLパラメータ除去）
	 *
	 * @return string
	 */
	public static function render_import_notice(): string {
		if ( ! isset( $_GET['ktp_service_import'] ) ) {
			return '';
		}
		$status = sanitize_key( wp_unslash( $_GET['ktp_service_import'] ) );
		$text   = '';

		if ( $status === 'success' ) {
			$inserted = isset( $_GET['inserted'] ) ? absint( $_GET['inserted'] ) : 0;
			$updated  = isset( $_GET['updated'] ) ? absint( $_GET['updated'] ) : 0;
			$skipped  = isset( $_GET['skipped'] ) ? absint( $_GET['skipped'] ) : 0;
			$errors   = isset( $_GET['errors'] ) ? absint( $_GET['errors'] ) : 0;
			$text     = sprintf(
				/* translators: 1: inserted count, 2: updated count, 3: skipped count, 4: error count */
				__( 'インポート完了：%1$d 件追加、%2$d 件更新、%3$d 件スキップ、%4$d 件エラー', 'ktpwp' ),
				$inserted,
				$updated,
				$skipped,
				$errors
			);
		} elseif ( $status === 'failed' ) {
			$text = isset( $_GET['error_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['error_msg'] ) ) : __( 'インポートに失敗しました。', 'ktpwp' );
		} else {
			return '';
		}

		$text_json = wp_json_encode( $text, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE );
		if ( false === $text_json ) {
			$text_json = '""';
		}
		$type_json = wp_json_encode( $status === 'success' ? 'success' : 'error' );

		return '<script>
document.addEventListener("DOMContentLoaded", function() {
	var msg = ' . $text_json . ';
	var type = ' . $type_json . ';
	if (type === "success" && typeof showSuccessNotification === "function") {
		showSuccessNotification(msg);
	} else if (type === "error" && typeof showErrorNotification === "function") {
		showErrorNotification(msg);
	} else {
		var n = document.createElement("div");
		n.className = "ktp-service-import-toast ktp-service-import-toast--" + type;
		n.textContent = msg;
		n.style.cssText = "position:fixed;top:20px;right:20px;z-index:2147483647;padding:12px 18px;border-radius:6px;color:#fff;font-size:14px;max-width:min(420px,calc(100vw - 40px));box-shadow:0 4px 12px rgba(0,0,0,.15);background:" + (type === "success" ? "#28a745" : "#dc3545") + ";";
		document.body.appendChild(n);
		setTimeout(function() { if (n.parentNode) { n.remove(); } }, 5000);
	}
	if (window.history.replaceState) {
		var url = new URL(window.location.href);
		["ktp_service_import","inserted","updated","skipped","errors","error_msg"].forEach(function(key) {
			url.searchParams.delete(key);
		});
		window.history.replaceState({ path: url.href }, "", url.href);
	}
});
</script>';
	}

	/**
	 * @return void
	 */
	public static function enqueue_scripts(): void {
		if ( ! function_exists( 'ktpwp_is_frontend_kantanpro_app_page' ) || ! ktpwp_is_frontend_kantanpro_app_page() ) {
			return;
		}
		if ( ! self::user_can_manage() ) {
			return;
		}
		$tab = isset( $_GET['tab_name'] ) ? sanitize_text_field( wp_unslash( $_GET['tab_name'] ) ) : '';
		if ( $tab !== '' && $tab !== 'service' ) {
			return;
		}

		wp_enqueue_script(
			'ktp-service-import-export',
			plugin_dir_url( __DIR__ ) . 'js/ktp-service-import-export.js',
			array( 'jquery' ),
			( defined( 'KANTANPRO_PLUGIN_VERSION' ) ? KANTANPRO_PLUGIN_VERSION : '1.0.0' ) . '.' . filemtime( dirname( __DIR__ ) . '/js/ktp-service-import-export.js' ),
			true
		);
	}

	/**
	 * @return void
	 */
	public static function handle_export(): void {
		if ( ! self::user_can_manage() ) {
			wp_die( esc_html__( '権限がありません。', 'ktpwp' ) );
		}
		if ( ! isset( $_POST['_ktp_service_export_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_ktp_service_export_nonce'] ) ), self::NONCE_EXPORT ) ) {
			wp_die( esc_html__( 'セキュリティチェックに失敗しました。', 'ktpwp' ) );
		}

		$format = isset( $_POST['format'] ) ? sanitize_key( wp_unslash( $_POST['format'] ) ) : 'csv';
		$allowed_formats = array( 'csv', 'tsv', 'json', 'excel', 'google_sheets' );
		if ( ! in_array( $format, $allowed_formats, true ) ) {
			$format = 'csv';
		}

		$categories = array();
		if ( isset( $_POST['categories'] ) && is_array( $_POST['categories'] ) ) {
			foreach ( $_POST['categories'] as $cat ) {
				$cat = sanitize_text_field( wp_unslash( $cat ) );
				if ( $cat !== '' ) {
					$categories[] = $cat;
				}
			}
		}

		$public_filter = isset( $_POST['is_public_filter'] ) ? sanitize_key( wp_unslash( $_POST['is_public_filter'] ) ) : 'all';
		if ( ! in_array( $public_filter, array( 'all', '0', '1' ), true ) ) {
			$public_filter = 'all';
		}

		$include_images = ! empty( $_POST['include_images'] );
		$rows           = self::fetch_services_for_export( $categories, $public_filter, $include_images );

		$timestamp = date_i18n( 'Ymd-His' );
		$filename  = 'kantanpro-services-' . $timestamp;

		if ( $format === 'json' ) {
			$payload = array(
				'metadata' => array(
					'exported_at'     => current_time( 'mysql' ),
					'plugin'          => 'KantanProEX',
					'entity'          => 'service',
					'include_images'  => $include_images,
					'categories'      => $categories,
					'is_public_filter'=> $public_filter,
				),
				'services' => $rows,
			);
			self::send_download( wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ), $filename . '.json', 'application/json; charset=utf-8' );
		}

		if ( $format === 'excel' ) {
			$content = self::build_spreadsheet_xml( $rows );
			self::send_download( $content, $filename . '.xls', 'application/vnd.ms-excel; charset=utf-8' );
		}

		$delimiter = ( $format === 'tsv' ) ? "\t" : ',';
		$ext       = ( $format === 'tsv' ) ? 'tsv' : 'csv';
		if ( $format === 'google_sheets' ) {
			$ext = 'csv';
		}
		$use_bom = ( $format === 'google_sheets' || $format === 'tsv' );
		$content = self::build_delimited_export( $rows, $delimiter, $use_bom );
		$mime    = ( $format === 'tsv' ) ? 'text/tab-separated-values; charset=utf-8' : 'text/csv; charset=utf-8';
		self::send_download( $content, $filename . '.' . $ext, $mime );
	}

	/**
	 * @return void
	 */
	public static function handle_import(): void {
		$redirect = self::get_redirect_url_from_post();

		if ( ! self::user_can_manage() ) {
			wp_die( esc_html__( '権限がありません。', 'ktpwp' ) );
		}
		if ( ! isset( $_POST['_ktp_service_import_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_ktp_service_import_nonce'] ) ), self::NONCE_IMPORT ) ) {
			self::redirect_import_result( $redirect, 'failed', __( 'セキュリティチェックに失敗しました。', 'ktpwp' ) );
		}

		$format = isset( $_POST['format'] ) ? sanitize_key( wp_unslash( $_POST['format'] ) ) : 'csv';
		$allowed_formats = array( 'csv', 'tsv', 'json', 'excel', 'google_sheets' );
		if ( ! in_array( $format, $allowed_formats, true ) ) {
			$format = 'csv';
		}

		$duplicate_policy = isset( $_POST['duplicate_policy'] ) ? sanitize_key( wp_unslash( $_POST['duplicate_policy'] ) ) : 'overwrite';
		if ( ! in_array( $duplicate_policy, array( 'overwrite', 'add_new' ), true ) ) {
			$duplicate_policy = 'overwrite';
		}
		$import_images          = ! empty( $_POST['import_images'] );
		$default_public         = isset( $_POST['default_is_public'] ) && (string) $_POST['default_is_public'] === '1' ? 1 : 0;
		$existing_image_policy = isset( $_POST['existing_image_policy'] ) ? sanitize_key( wp_unslash( $_POST['existing_image_policy'] ) ) : 'keep';
		if ( ! in_array( $existing_image_policy, array( 'keep', 'no_image', 'import' ), true ) ) {
			$existing_image_policy = 'keep';
		}

		$parsed = null;
		if ( $format === 'google_sheets' ) {
			$url = isset( $_POST['google_sheets_url'] ) ? esc_url_raw( wp_unslash( $_POST['google_sheets_url'] ) ) : '';
			if ( $url === '' ) {
				self::redirect_import_result( $redirect, 'failed', __( 'Googleスプレッドシートの URL を入力してください。', 'ktpwp' ) );
			}
			$fetch = self::fetch_google_sheets_csv( $url );
			if ( is_wp_error( $fetch ) ) {
				self::redirect_import_result( $redirect, 'failed', $fetch->get_error_message() );
			}
			$parsed = self::parse_delimited_text( $fetch, ',' );
		} elseif ( $format === 'json' ) {
			if ( empty( $_FILES['import_file']['tmp_name'] ) ) {
				self::redirect_import_result( $redirect, 'failed', __( 'ファイルを選択してください。', 'ktpwp' ) );
			}
			$raw = self::read_uploaded_file( $_FILES['import_file'] );
			if ( is_wp_error( $raw ) ) {
				self::redirect_import_result( $redirect, 'failed', $raw->get_error_message() );
			}
			$parsed = self::parse_json_import( $raw );
		} else {
			if ( empty( $_FILES['import_file']['tmp_name'] ) ) {
				self::redirect_import_result( $redirect, 'failed', __( 'ファイルを選択してください。', 'ktpwp' ) );
			}
			$raw = self::read_uploaded_file( $_FILES['import_file'] );
			if ( is_wp_error( $raw ) ) {
				self::redirect_import_result( $redirect, 'failed', $raw->get_error_message() );
			}
			$name = isset( $_FILES['import_file']['name'] ) ? strtolower( (string) $_FILES['import_file']['name'] ) : '';
			if ( $format === 'excel' || self::string_ends_with( $name, '.xls' ) || self::string_ends_with( $name, '.xlsx' ) ) {
				$parsed = self::parse_excel_import( $raw, $name );
			} elseif ( $format === 'tsv' || self::string_ends_with( $name, '.tsv' ) || self::string_ends_with( $name, '.tab' ) ) {
				$parsed = self::parse_delimited_text( $raw, "\t" );
			} else {
				$parsed = self::parse_delimited_text( $raw, null );
			}
		}

		if ( is_wp_error( $parsed ) ) {
			self::redirect_import_result( $redirect, 'failed', $parsed->get_error_message() );
		}

		$result = self::import_parsed_rows( $parsed, $duplicate_policy, $import_images, $default_public, $existing_image_policy );
		wp_safe_redirect(
			add_query_arg(
				array(
					'tab_name'           => 'service',
					'ktp_service_import' => 'success',
					'inserted'           => $result['inserted'],
					'updated'            => $result['updated'],
					'skipped'            => $result['skipped'],
					'errors'             => $result['errors'],
				),
				$redirect
			)
		);
		exit;
	}

	/**
	 * @param string[] $categories      Selected categories.
	 * @param string   $public_filter   all|0|1.
	 * @param bool     $include_images  Include base64 image data.
	 * @return array<int, array<string, mixed>>
	 */
	private static function fetch_services_for_export( array $categories, $public_filter, $include_images ): array {
		global $wpdb;
		$table  = $wpdb->prefix . 'ktp_service';
		$where  = array( '1=1' );
		$values = array();

		if ( ! empty( $categories ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $categories ), '%s' ) );
			$where[]      = "category IN ({$placeholders})";
			$values       = array_merge( $values, $categories );
		}
		if ( $public_filter === '0' || $public_filter === '1' ) {
			$where[]  = 'is_public = %d';
			$values[] = (int) $public_filter;
		}

		$sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id ASC LIMIT ' . self::MAX_ROWS;
		if ( ! empty( $values ) ) {
			$records = $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A );
		} else {
			$records = $wpdb->get_results( $sql, ARRAY_A );
		}
		if ( ! is_array( $records ) ) {
			return array();
		}

		$db     = class_exists( 'KTPWP_Service_DB' ) ? KTPWP_Service_DB::get_instance() : null;
		$export = array();
		foreach ( $records as $record ) {
			$row = self::normalize_service_row( $record );
			if ( $include_images && $db ) {
				$service_id = isset( $record['id'] ) ? absint( $record['id'] ) : 0;
				$row['image_url'] = $db->resolve_image_url( $service_id, isset( $record['image_url'] ) ? (string) $record['image_url'] : '' );
				$row['image_base64'] = self::encode_service_image_base64( $service_id, $db );
			} else {
				$row['image_base64'] = '';
			}
			$export[] = $row;
		}
		return $export;
	}

	/**
	 * @param array<string, mixed> $record DB row.
	 * @return array<string, mixed>
	 */
	private static function normalize_service_row( array $record ): array {
		$row = array();
		foreach ( self::export_field_keys() as $key ) {
			if ( $key === 'image_base64' ) {
				$row[ $key ] = '';
				continue;
			}
			if ( $key === 'id' ) {
				$row[ $key ] = isset( $record['id'] ) ? (int) $record['id'] : '';
				continue;
			}
			if ( ! array_key_exists( $key, $record ) ) {
				$row[ $key ] = '';
				continue;
			}
			$value = $record[ $key ];
			if ( $key === 'is_public' || $key === 'public_quantity_fixed' || $key === 'public_instant_purchase' ) {
				$row[ $key ] = (int) $value;
			} elseif ( $key === 'price' || $key === 'tax_rate' ) {
				$row[ $key ] = $value === null ? '' : $value;
			} else {
				$row[ $key ] = (string) $value;
			}
		}
		return $row;
	}

	/**
	 * @param int              $service_id Service ID.
	 * @param KTPWP_Service_DB $db         Service DB.
	 * @return string
	 */
	private static function encode_service_image_base64( $service_id, KTPWP_Service_DB $db ): string {
		$file = $db->find_uploaded_image_file( $service_id );
		if ( ! $file || ! is_readable( $file ) ) {
			return '';
		}
		$bytes = file_get_contents( $file );
		if ( $bytes === false || $bytes === '' ) {
			return '';
		}
		return base64_encode( $bytes );
	}

	/**
	 * @param array<int, array<string, mixed>> $rows      Rows.
	 * @param string                           $delimiter Delimiter.
	 * @param bool                             $utf8_bom  Prepend BOM.
	 * @return string
	 */
	private static function build_delimited_export( array $rows, $delimiter, $utf8_bom = false ): string {
		$headers = self::export_field_keys();
		$labels  = self::export_field_labels();
		$header_line = array();
		foreach ( $headers as $key ) {
			$header_line[] = isset( $labels[ $key ] ) ? $labels[ $key ] : $key;
		}
		$out = $utf8_bom ? "\xEF\xBB\xBF" : '';
		$out .= self::delimited_line( $header_line, $delimiter );
		foreach ( $rows as $row ) {
			$line = array();
			foreach ( $headers as $key ) {
				$val = isset( $row[ $key ] ) ? $row[ $key ] : '';
				$line[] = is_scalar( $val ) ? (string) $val : wp_json_encode( $val );
			}
			$out .= self::delimited_line( $line, $delimiter );
		}
		return $out;
	}

	/**
	 * @param array<int, string> $fields    Fields.
	 * @param string             $delimiter Delimiter.
	 * @return string
	 */
	private static function delimited_line( array $fields, $delimiter ): string {
		$escaped = array();
		foreach ( $fields as $field ) {
			$field = (string) $field;
			$must_quote = strpos( $field, $delimiter ) !== false
				|| strpos( $field, '"' ) !== false
				|| strpos( $field, "\n" ) !== false
				|| strpos( $field, "\r" ) !== false;
			if ( $must_quote ) {
				$field = '"' . str_replace( '"', '""', $field ) . '"';
			}
			$escaped[] = $field;
		}
		return implode( $delimiter, $escaped ) . "\n";
	}

	/**
	 * @param array<int, array<string, mixed>> $rows Rows.
	 * @return string
	 */
	private static function build_spreadsheet_xml( array $rows ): string {
		$headers = self::export_field_keys();
		$labels  = self::export_field_labels();
		$xml     = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml    .= '<?mso-application progid="Excel.Sheet"?>' . "\n";
		$xml    .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">' . "\n";
		$xml    .= '<Worksheet ss:Name="Services"><Table>' . "\n";
		$xml    .= '<Row>';
		foreach ( $headers as $key ) {
			$label = isset( $labels[ $key ] ) ? $labels[ $key ] : $key;
			$xml  .= '<Cell><Data ss:Type="String">' . esc_xml( $label ) . '</Data></Cell>';
		}
		$xml .= '</Row>' . "\n";
		foreach ( $rows as $row ) {
			$xml .= '<Row>';
			foreach ( $headers as $key ) {
				$val  = isset( $row[ $key ] ) ? $row[ $key ] : '';
				$type = is_numeric( $val ) && $key !== 'service_name' && $key !== 'image_base64' ? 'Number' : 'String';
				$xml .= '<Cell><Data ss:Type="' . $type . '">' . esc_xml( (string) $val ) . '</Data></Cell>';
			}
			$xml .= '</Row>' . "\n";
		}
		$xml .= '</Table></Worksheet></Workbook>';
		return $xml;
	}

	/**
	 * @param string $content  Content.
	 * @param string $filename Filename.
	 * @param string $mime     MIME type.
	 * @return void
	 */
	private static function send_download( $content, $filename, $mime ): void {
		if ( $content === '' || $content === false ) {
			wp_die( esc_html__( 'エクスポートデータの生成に失敗しました。', 'ktpwp' ) );
		}
		nocache_headers();
		header( 'Content-Description: File Transfer' );
		header( 'Content-Type: ' . $mime );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'Expires: 0' );
		header( 'Cache-Control: must-revalidate' );
		header( 'Pragma: public' );
		echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * @param array<string, mixed> $file $_FILES entry.
	 * @return string|WP_Error
	 */
	private static function read_uploaded_file( array $file ) {
		if ( ! empty( $file['error'] ) ) {
			return new WP_Error( 'upload', __( 'ファイルのアップロードに失敗しました。', 'ktpwp' ) );
		}
		$size = isset( $file['size'] ) ? (int) $file['size'] : 0;
		if ( $size <= 0 || $size > self::MAX_IMPORT_BYTES ) {
			return new WP_Error( 'size', __( 'ファイルサイズが大きすぎます（最大 10MB）。', 'ktpwp' ) );
		}
		$tmp = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
		if ( $tmp === '' || ! is_uploaded_file( $tmp ) ) {
			return new WP_Error( 'upload', __( 'ファイルを読み取れませんでした。', 'ktpwp' ) );
		}
		$raw = file_get_contents( $tmp );
		if ( $raw === false ) {
			return new WP_Error( 'read', __( 'ファイルを読み取れませんでした。', 'ktpwp' ) );
		}
		return $raw;
	}

	/**
	 * @param string $raw JSON string.
	 * @return array{headers: string[], rows: array<int, array<string, string>>}|WP_Error
	 */
	private static function parse_json_import( $raw ) {
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'json', __( 'JSON の形式が正しくありません。', 'ktpwp' ) );
		}
		$services = isset( $data['services'] ) && is_array( $data['services'] ) ? $data['services'] : $data;
		if ( ! is_array( $services ) || $services === array() ) {
			return new WP_Error( 'json', __( 'サービスデータが見つかりません。', 'ktpwp' ) );
		}
		$headers = self::export_field_keys();
		$rows    = array();
		foreach ( $services as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$row = array();
			foreach ( $headers as $key ) {
				$row[ $key ] = isset( $item[ $key ] ) ? (string) $item[ $key ] : '';
			}
			$rows[] = $row;
		}
		return array(
			'headers' => $headers,
			'rows'    => $rows,
		);
	}

	/**
	 * @param string      $raw       Raw text.
	 * @param string|null $delimiter Delimiter or null to detect.
	 * @return array{headers: string[], rows: array<int, array<int, string>>}|WP_Error
	 */
	private static function parse_delimited_text( $raw, $delimiter = null ) {
		$raw = (string) $raw;
		if ( $raw === '' ) {
			return new WP_Error( 'empty', __( '有効な行がありません。', 'ktpwp' ) );
		}

		$tmp = wp_tempnam( 'ktp-service-delim' );
		if ( ! $tmp ) {
			return new WP_Error( 'tmp', __( '一時ファイルを作成できませんでした。', 'ktpwp' ) );
		}
		file_put_contents( $tmp, $raw );
		$handle = fopen( $tmp, 'rb' );
		if ( ! $handle ) {
			@unlink( $tmp );
			return new WP_Error( 'read', __( 'ファイルを読み取れませんでした。', 'ktpwp' ) );
		}

		$first_line = fgets( $handle );
		if ( $first_line === false ) {
			fclose( $handle );
			@unlink( $tmp );
			return new WP_Error( 'empty', __( '有効な行がありません。', 'ktpwp' ) );
		}
		if ( strncmp( $first_line, "\xEF\xBB\xBF", 3 ) === 0 ) {
			$first_line = substr( $first_line, 3 );
		}

		if ( $delimiter === null ) {
			$tab = substr_count( $first_line, "\t" );
			$com = substr_count( $first_line, ',' );
			if ( $tab === 0 && $com === 0 ) {
				fclose( $handle );
				@unlink( $tmp );
				return new WP_Error( 'delim', __( '区切り文字を判定できませんでした。', 'ktpwp' ) );
			}
			$delimiter = ( $tab >= $com ) ? "\t" : ',';
		}

		rewind( $handle );
		$bom_check = fread( $handle, 3 );
		if ( $bom_check !== "\xEF\xBB\xBF" ) {
			fseek( $handle, 0 );
		}

		$headers = fgetcsv( $handle, 0, $delimiter );
		if ( ! is_array( $headers ) || $headers === array() ) {
			fclose( $handle );
			@unlink( $tmp );
			return new WP_Error( 'header', __( 'ヘッダー行を読み取れませんでした。', 'ktpwp' ) );
		}
		$headers = array_map(
			static function ( $h ) {
				return trim( (string) $h );
			},
			$headers
		);
		$col_count = count( $headers );
		$rows      = array();
		while ( count( $rows ) < self::MAX_ROWS && ( $cells = fgetcsv( $handle, 0, $delimiter ) ) !== false ) {
			if ( ! is_array( $cells ) || $cells === array( null ) ) {
				continue;
			}
			$assoc = array();
			for ( $c = 0; $c < $col_count; $c++ ) {
				$header_key           = self::map_header_to_field( $headers[ $c ] );
				$assoc[ $header_key ] = isset( $cells[ $c ] ) ? (string) $cells[ $c ] : '';
			}
			if ( trim( (string) ( $assoc['service_name'] ?? '' ) ) === '' ) {
				continue;
			}
			$rows[] = $assoc;
		}

		fclose( $handle );
		@unlink( $tmp );

		return array(
			'headers' => $headers,
			'rows'    => $rows,
		);
	}

	/**
	 * @param string $raw  File contents.
	 * @param string $name Filename.
	 * @return array{headers: string[], rows: array<int, array<string, string>>}|WP_Error
	 */
	private static function parse_excel_import( $raw, $name ) {
		$name = strtolower( (string) $name );
		if ( self::string_ends_with( $name, '.xlsx' ) ) {
			return self::parse_xlsx_import( $raw );
		}
		if ( strpos( $raw, '<Workbook' ) !== false || strpos( $raw, '<worksheet' ) !== false ) {
			return self::parse_spreadsheet_xml( $raw );
		}
		return self::parse_delimited_text( $raw, null );
	}

	/**
	 * SpreadsheetML (.xls) を解析
	 *
	 * @param string $raw XML.
	 * @return array{headers: string[], rows: array<int, array<string, string>>}|WP_Error
	 */
	private static function parse_spreadsheet_xml( $raw ) {
		libxml_use_internal_errors( true );
		$xml = simplexml_load_string( $raw, 'SimpleXMLElement', LIBXML_NOCDATA );
		if ( $xml === false ) {
			return new WP_Error( 'excel', __( 'Excel ファイルを読み取れませんでした。', 'ktpwp' ) );
		}
		$xml->registerXPathNamespace( 'ss', 'urn:schemas-microsoft-com:office:spreadsheet' );
		$row_nodes = $xml->xpath('//ss:Worksheet/ss:Table/ss:Row');
		if ( ! is_array( $row_nodes ) || $row_nodes === array() ) {
			return new WP_Error( 'excel', __( 'Excel にデータ行がありません。', 'ktpwp' ) );
		}
		$headers = array();
		$rows    = array();
		$row_num = 0;
		foreach ( $row_nodes as $row_node ) {
			$cells = array();
			foreach ( $row_node->Cell as $cell ) {
				$cells[] = isset( $cell->Data ) ? trim( (string) $cell->Data ) : '';
			}
			if ( $cells === array() ) {
				continue;
			}
			if ( $row_num === 0 ) {
				$headers = $cells;
				$row_num++;
				continue;
			}
			$row_num++;
			$assoc = array();
			foreach ( $headers as $c => $header ) {
				$field           = self::map_header_to_field( $header );
				$assoc[ $field ] = isset( $cells[ $c ] ) ? $cells[ $c ] : '';
			}
			if ( trim( (string) ( $assoc['service_name'] ?? '' ) ) === '' ) {
				continue;
			}
			$rows[] = $assoc;
		}
		return array(
			'headers' => $headers,
			'rows'    => $rows,
		);
	}

	/**
	 * 簡易 XLSX 解析（1シート目のみ）
	 *
	 * @param string $raw Binary.
	 * @return array{headers: string[], rows: array<int, array<string, string>>}|WP_Error
	 */
	private static function parse_xlsx_import( $raw ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'zip', __( 'XLSX の読み取りには ZipArchive が必要です。', 'ktpwp' ) );
		}
		$tmp = wp_tempnam( 'ktp-service-xlsx' );
		if ( ! $tmp ) {
			return new WP_Error( 'tmp', __( '一時ファイルを作成できませんでした。', 'ktpwp' ) );
		}
		file_put_contents( $tmp, $raw );
		$zip = new ZipArchive();
		if ( $zip->open( $tmp ) !== true ) {
			@unlink( $tmp );
			return new WP_Error( 'xlsx', __( 'XLSX ファイルを開けませんでした。', 'ktpwp' ) );
		}
		$shared = array();
		$shared_xml = $zip->getFromName( 'xl/sharedStrings.xml' );
		if ( $shared_xml ) {
			libxml_use_internal_errors( true );
			$sxml = simplexml_load_string( $shared_xml );
			if ( $sxml && isset( $sxml->si ) ) {
				foreach ( $sxml->si as $si ) {
					if ( isset( $si->t ) ) {
						$shared[] = (string) $si->t;
					} elseif ( isset( $si->r ) ) {
						$text = '';
						foreach ( $si->r as $r ) {
							$text .= (string) $r->t;
						}
						$shared[] = $text;
					} else {
						$shared[] = '';
					}
				}
			}
		}
		$sheet_xml = $zip->getFromName( 'xl/worksheets/sheet1.xml' );
		$zip->close();
		@unlink( $tmp );
		if ( ! $sheet_xml ) {
			return new WP_Error( 'xlsx', __( 'XLSX のシートデータが見つかりません。', 'ktpwp' ) );
		}
		libxml_use_internal_errors( true );
		$xml = simplexml_load_string( $sheet_xml );
		if ( $xml === false || ! isset( $xml->sheetData->row ) ) {
			return new WP_Error( 'xlsx', __( 'XLSX の内容を解析できませんでした。', 'ktpwp' ) );
		}
		$headers = array();
		$rows    = array();
		$row_num = 0;
		foreach ( $xml->sheetData->row as $row ) {
			$cells = array();
			foreach ( $row->c as $cell ) {
				$val = '';
				if ( isset( $cell['t'] ) && (string) $cell['t'] === 's' ) {
					$idx = (int) $cell->v;
					$val = isset( $shared[ $idx ] ) ? $shared[ $idx ] : '';
				} elseif ( isset( $cell->v ) ) {
					$val = (string) $cell->v;
				}
				$cells[] = $val;
			}
			if ( $cells === array() ) {
				continue;
			}
			if ( $row_num === 0 ) {
				$headers = $cells;
				$row_num++;
				continue;
			}
			$row_num++;
			$assoc = array();
			foreach ( $headers as $c => $header ) {
				$field           = self::map_header_to_field( $header );
				$assoc[ $field ] = isset( $cells[ $c ] ) ? $cells[ $c ] : '';
			}
			if ( trim( (string) ( $assoc['service_name'] ?? '' ) ) === '' ) {
				continue;
			}
			$rows[] = $assoc;
		}
		return array(
			'headers' => $headers,
			'rows'    => $rows,
		);
	}

	/**
	 * @param string $header Header cell.
	 * @return string
	 */
	private static function map_header_to_field( $header ): string {
		$header = trim( (string) $header );
		$lower  = strtolower( $header );
		$aliases = array(
			'id'                      => array( 'id', 'ID', 'サービスID' ),
			'service_name'            => array( 'service_name', 'サービス名', '商品・サービス名', '商品名', 'name' ),
			'price'                   => array( 'price', '単価', '単価（数値）' ),
			'tax_rate'                => array( 'tax_rate', '税率', '税率（%）', '税率（%・空可）' ),
			'unit'                    => array( 'unit', '単位' ),
			'category'                => array( 'category', 'カテゴリー', 'カテゴリ' ),
			'is_public'               => array( 'is_public', '公開状態', '公開', 'サイトに公開' ),
			'memo'                    => array( 'memo', 'メモ' ),
			'image_url'               => array( 'image_url', '画像url', '画像url', '画像' ),
			'image_base64'            => array( 'image_base64', '画像データ（base64）', 'image_data' ),
			'contract_billing_cycle'  => array( 'contract_billing_cycle', '契約課金サイクル' ),
			'stock'                   => array( 'stock', '在庫' ),
			'public_quantity_fixed'   => array( 'public_quantity_fixed', '公開数量固定' ),
			'public_instant_purchase' => array( 'public_instant_purchase', '即時購入' ),
			'public_html'             => array( 'public_html', '公開html' ),
		);
		foreach ( self::export_field_keys() as $field ) {
			if ( $lower === strtolower( $field ) ) {
				return $field;
			}
		}
		foreach ( $aliases as $field => $list ) {
			foreach ( $list as $alias ) {
				if ( $header === $alias || $lower === strtolower( $alias ) ) {
					return $field;
				}
			}
		}
		return sanitize_key( $header );
	}

	/**
	 * @param array{headers: string[], rows: array<int, array<string, string>>} $parsed Parsed data.
	 * @param string                                                              $duplicate_policy overwrite|add_new when import ID matches existing ID.
	 * @param bool                                                                $import_images Import images.
	 * @param int                                                                 $default_public Default is_public.
	 * @param string                                                              $existing_image_policy keep|no_image|import.
	 * @return array{inserted: int, updated: int, skipped: int, errors: int}
	 */
	private static function import_parsed_rows( array $parsed, $duplicate_policy, $import_images, $default_public, $existing_image_policy = 'keep' ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'ktp_service';

		$inserted = 0;
		$updated  = 0;
		$skipped  = 0;
		$errors   = 0;

		foreach ( $parsed['rows'] as $row ) {
			if ( ( $inserted + $updated + $skipped + $errors ) >= self::MAX_ROWS ) {
				break;
			}

			$name = trim( (string) ( $row['service_name'] ?? '' ) );
			if ( $name === '' ) {
				$skipped++;
				continue;
			}

			$import_id = isset( $row['id'] ) ? absint( $row['id'] ) : 0;
			$existing_id = 0;
			if ( $import_id > 0 ) {
				$existing_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d", $import_id ) );
			}

			$row_data = self::build_import_row_data( $row, $default_public );

			if ( $existing_id > 0 ) {
				if ( $duplicate_policy === 'overwrite' ) {
					if ( ! self::update_imported_service_row( $table, $existing_id, $row_data, $row, $import_images, $existing_image_policy ) ) {
						$errors++;
						continue;
					}
					$updated++;
					continue;
				}
				// add_new: 同一IDは使わず新規追加する
				$import_id = 0;
			}

			$insert = array_merge(
				array(
					'time'      => current_time( 'mysql' ),
					'image_url' => '',
				),
				$row_data['fields'],
				array(
					'frequency' => 0,
				)
			);
			$insert_formats = array_merge(
				array( '%s', '%s' ),
				$row_data['formats'],
				array( '%d' )
			);

			if ( $import_id > 0 ) {
				$insert = array_merge( array( 'id' => $import_id ), $insert );
				$insert_formats = array_merge( array( '%d' ), $insert_formats );
			}

			$res = $wpdb->insert( $table, $insert, $insert_formats );
			if ( $res === false ) {
				$errors++;
				continue;
			}

			$service_id = $import_id > 0 ? $import_id : (int) $wpdb->insert_id;
			self::apply_image_import_for_service( $service_id, $row, $import_images, $existing_image_policy );
			$inserted++;
		}

		return array(
			'inserted' => $inserted,
			'updated'  => $updated,
			'skipped'  => $skipped,
			'errors'   => $errors,
		);
	}

	/**
	 * @param string               $table                   Table name.
	 * @param int                  $service_id              Service ID.
	 * @param array{fields: array<string, mixed>, formats: string[]} $row_data Row data.
	 * @param array<string,string> $row                     Import row.
	 * @param bool                 $import_images           Import images.
	 * @param string               $existing_image_policy   Image policy.
	 * @return bool
	 */
	private static function update_imported_service_row( $table, $service_id, array $row_data, array $row, $import_images, $existing_image_policy ): bool {
		global $wpdb;

		$res = $wpdb->update(
			$table,
			$row_data['fields'],
			array( 'id' => $service_id ),
			$row_data['formats'],
			array( '%d' )
		);
		if ( $res === false ) {
			return false;
		}

		self::apply_image_import_for_service( $service_id, $row, $import_images, $existing_image_policy );
		return true;
	}

	/**
	 * @param array<string, string> $row            Import row.
	 * @param int                   $default_public Default is_public.
	 * @return array{fields: array<string, mixed>, formats: string[]}
	 */
	private static function build_import_row_data( array $row, $default_public ): array {
		$name = trim( (string) ( $row['service_name'] ?? '' ) );

		$price_raw = isset( $row['price'] ) ? str_replace( array( ',', ' ', '　' ), '', (string) $row['price'] ) : '0';
		$price     = is_numeric( $price_raw ) ? (float) $price_raw : 0.0;

		$tax_rate = null;
		if ( isset( $row['tax_rate'] ) && (string) $row['tax_rate'] !== '' ) {
			$tax_raw = str_replace( array( ',', '%', ' ', '　' ), '', (string) $row['tax_rate'] );
			if ( is_numeric( $tax_raw ) ) {
				$tax_rate = (float) $tax_raw;
			}
		}

		$unit     = isset( $row['unit'] ) ? sanitize_text_field( (string) $row['unit'] ) : '';
		$memo     = isset( $row['memo'] ) ? sanitize_textarea_field( (string) $row['memo'] ) : '';
		$category = isset( $row['category'] ) && trim( (string) $row['category'] ) !== '' ? sanitize_text_field( (string) $row['category'] ) : __( 'General', 'ktpwp' );

		$is_public = $default_public;
		if ( isset( $row['is_public'] ) && (string) $row['is_public'] !== '' ) {
			$pub_raw   = strtolower( trim( (string) $row['is_public'] ) );
			$is_public = in_array( $pub_raw, array( '1', 'true', 'yes', '公開', 'y' ), true ) ? 1 : 0;
		}

		$contract_billing_cycle = 'none';
		if ( class_exists( 'KTPWP_Contract_Billing_Cycle' ) && isset( $row['contract_billing_cycle'] ) ) {
			$contract_billing_cycle = KTPWP_Contract_Billing_Cycle::sanitize( (string) $row['contract_billing_cycle'] );
		}

		$stock                   = isset( $row['stock'] ) && (string) $row['stock'] !== '' ? max( 0, absint( $row['stock'] ) ) : 1;
		$public_quantity_fixed   = ! empty( $row['public_quantity_fixed'] ) && in_array( strtolower( (string) $row['public_quantity_fixed'] ), array( '1', 'true', 'yes' ), true ) ? 1 : 0;
		$public_instant_purchase = ! empty( $row['public_instant_purchase'] ) && in_array( strtolower( (string) $row['public_instant_purchase'] ), array( '1', 'true', 'yes' ), true ) ? 1 : 0;
		$public_html             = isset( $row['public_html'] ) ? wp_kses_post( (string) $row['public_html'] ) : '';

		$search = implode(
			', ',
			array(
				current_time( 'mysql' ),
				$name,
				(string) $price,
				$tax_rate === null ? '' : (string) $tax_rate,
				$unit,
				$memo,
				$category,
			)
		);

		global $wpdb;
		$table = $wpdb->prefix . 'ktp_service';

		$fields = array(
			'service_name' => $name,
			'price'        => $price,
			'unit'         => $unit,
			'memo'         => $memo,
			'category'     => $category,
			'is_public'    => $is_public,
			'search_field' => $search,
		);
		$formats = array( '%s', '%f', '%s', '%s', '%s', '%d', '%s' );

		if ( $tax_rate !== null ) {
			$fields['tax_rate'] = $tax_rate;
			$formats[]          = '%f';
		}

		if ( self::table_has_column( $table, 'contract_billing_cycle' ) ) {
			$fields['contract_billing_cycle'] = $contract_billing_cycle;
			$formats[]                        = '%s';
		}
		if ( self::table_has_column( $table, 'stock' ) ) {
			$fields['stock'] = $stock;
			$formats[]       = '%d';
		}
		if ( self::table_has_column( $table, 'public_quantity_fixed' ) ) {
			$fields['public_quantity_fixed'] = $public_quantity_fixed;
			$formats[]                       = '%d';
		}
		if ( self::table_has_column( $table, 'public_instant_purchase' ) ) {
			$fields['public_instant_purchase'] = $public_instant_purchase;
			$formats[]                         = '%d';
		}
		if ( self::table_has_column( $table, 'public_html' ) ) {
			$fields['public_html'] = $public_html;
			$formats[]             = '%s';
		}

		return array(
			'fields'  => $fields,
			'formats' => $formats,
		);
	}

	/**
	 * @param int                  $service_id            Service ID.
	 * @param array<string,string> $row                   Import row.
	 * @param bool                 $import_images         Import image columns.
	 * @param string               $existing_image_policy keep|no_image|import.
	 * @return void
	 */
	private static function apply_image_import_for_service( $service_id, array $row, $import_images, $existing_image_policy ): void {
		$service_id = absint( $service_id );
		if ( $service_id <= 0 ) {
			return;
		}

		$has_existing = self::service_has_uploaded_image( $service_id );
		if ( $has_existing ) {
			if ( $existing_image_policy === 'keep' ) {
				return;
			}
			if ( $existing_image_policy === 'no_image' ) {
				self::clear_service_image_to_default( $service_id );
				return;
			}
		}

		if ( ! $import_images ) {
			return;
		}

		self::import_service_image( $service_id, $row );
	}

	/**
	 * @param int $service_id Service ID.
	 * @return bool
	 */
	private static function service_has_uploaded_image( $service_id ): bool {
		if ( ! class_exists( 'KTPWP_Service_DB' ) ) {
			return false;
		}
		$db   = KTPWP_Service_DB::get_instance();
		$file = $db->find_uploaded_image_file( absint( $service_id ) );
		return is_string( $file ) && $file !== '' && is_file( $file );
	}

	/**
	 * @param int $service_id Service ID.
	 * @return void
	 */
	private static function clear_service_image_to_default( $service_id ): void {
		global $wpdb;
		$service_id = absint( $service_id );
		if ( $service_id <= 0 || ! class_exists( 'KTPWP_Service_DB' ) ) {
			return;
		}

		$db = KTPWP_Service_DB::get_instance();
		$db->delete_uploaded_image_files( $service_id );

		$table   = $wpdb->prefix . 'ktp_service';
		$default = $db->get_default_image_url();
		$wpdb->update(
			$table,
			array( 'image_url' => $default ),
			array( 'id' => $service_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * @param int                  $service_id Service ID.
	 * @param array<string,string> $row        Import row.
	 * @return void
	 */
	private static function import_service_image( $service_id, array $row ): void {
		$base64 = isset( $row['image_base64'] ) ? trim( (string) $row['image_base64'] ) : '';
		if ( $base64 !== '' ) {
			if ( preg_match( '/^data:image\/(\w+);base64,/', $base64, $m ) ) {
				$ext    = strtolower( $m[1] );
				$base64 = substr( $base64, strpos( $base64, ',' ) + 1 );
			} else {
				$ext = 'jpg';
			}
			$binary = base64_decode( $base64, true );
			if ( $binary !== false && $binary !== '' ) {
				self::save_service_image_binary( $service_id, $binary, $ext );
			}
			return;
		}

		$url = isset( $row['image_url'] ) ? trim( (string) $row['image_url'] ) : '';
		if ( $url === '' ) {
			return;
		}

		if ( preg_match( '/no-image-icon\.(jpg|png)$/i', $url ) ) {
			return;
		}

		if ( self::is_local_service_image_url( $url ) ) {
			$path = self::local_path_from_service_image_url( $url );
			if ( $path && is_readable( $path ) ) {
				$binary = file_get_contents( $path );
				if ( $binary !== false ) {
					$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
					self::save_service_image_binary( $service_id, $binary, $ext );
				}
			}
			return;
		}

		$response = wp_remote_get( $url, array( 'timeout' => 20 ) );
		if ( is_wp_error( $response ) ) {
			return;
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return;
		}
		$binary = wp_remote_retrieve_body( $response );
		if ( $binary === '' ) {
			return;
		}
		$ext = strtolower( pathinfo( wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png', 'gif' ), true ) ) {
			$ext = 'jpg';
		}
		self::save_service_image_binary( $service_id, $binary, $ext );
	}

	/**
	 * @param int    $service_id Service ID.
	 * @param string $binary     Image binary.
	 * @param string $ext        Extension.
	 * @return void
	 */
	private static function save_service_image_binary( $service_id, $binary, $ext ): void {
		global $wpdb;
		$service_id = absint( $service_id );
		if ( $service_id <= 0 || $binary === '' ) {
			return;
		}
		$ext = strtolower( preg_replace( '/[^a-z0-9]/', '', $ext ) );
		if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png', 'gif' ), true ) ) {
			$ext = 'jpg';
		}
		if ( $ext === 'jpeg' ) {
			$ext = 'jpg';
		}

		if ( class_exists( 'KTPWP_Service_Image_Storage' ) ) {
			$upload_dir = KTPWP_Service_Image_Storage::get_upload_dir();
			$upload_url = KTPWP_Service_Image_Storage::get_upload_url();
		} else {
			return;
		}
		if ( ! $upload_dir || ! $upload_url ) {
			return;
		}

		$db = class_exists( 'KTPWP_Service_DB' ) ? KTPWP_Service_DB::get_instance() : null;
		if ( $db ) {
			$db->delete_uploaded_image_files( $service_id );
		}

		$date_suffix   = current_time( 'Ymd' );
		$new_file_name = $service_id . '-' . $date_suffix . '.' . $ext;
		$file_path     = $upload_dir . $new_file_name;

		$tmp = wp_tempnam( 'ktp-service-img' );
		if ( ! $tmp ) {
			return;
		}
		file_put_contents( $tmp, $binary );

		$image = null;
		$mime  = mime_content_type( $tmp );
		if ( $mime === 'image/jpeg' ) {
			$image = imagecreatefromjpeg( $tmp );
		} elseif ( $mime === 'image/png' ) {
			$image = imagecreatefrompng( $tmp );
		} elseif ( $mime === 'image/gif' ) {
			$image = imagecreatefromgif( $tmp );
		}
		@unlink( $tmp );

		if ( ! $image ) {
			return;
		}

		if ( $ext === 'png' ) {
			imagepng( $image, $file_path, 9 );
		} elseif ( $ext === 'gif' ) {
			imagegif( $image, $file_path );
		} else {
			imagejpeg( $image, $file_path, 85 );
		}
		imagedestroy( $image );

		$table     = $wpdb->prefix . 'ktp_service';
		$image_url = $upload_url . $new_file_name;
		$wpdb->update(
			$table,
			array( 'image_url' => $image_url ),
			array( 'id' => $service_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * @param string $url Image URL.
	 * @return bool
	 */
	private static function is_local_service_image_url( $url ): bool {
		$url = (string) $url;
		if ( $url === '' ) {
			return false;
		}
		$site = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = wp_parse_url( $url, PHP_URL_HOST );
		return $host !== null && $site !== null && strtolower( (string) $host ) === strtolower( (string) $site );
	}

	/**
	 * @param string $url Image URL.
	 * @return string|false
	 */
	private static function local_path_from_service_image_url( $url ) {
		$filename = basename( wp_parse_url( $url, PHP_URL_PATH ) );
		if ( $filename === '' ) {
			return false;
		}
		if ( class_exists( 'KTPWP_Service_Image_Storage' ) ) {
			foreach ( KTPWP_Service_Image_Storage::get_search_dirs() as $dir ) {
				$path = $dir . $filename;
				if ( is_file( $path ) ) {
					return $path;
				}
			}
		}
		return false;
	}

	/**
	 * @param string $url Google Sheets URL.
	 * @return string|WP_Error
	 */
	private static function fetch_google_sheets_csv( $url ) {
		if ( ! preg_match( '#docs\.google\.com/spreadsheets/d/([a-zA-Z0-9-_]+)#', $url, $m ) ) {
			return new WP_Error( 'gs_url', __( 'Googleスプレッドシートの URL 形式が正しくありません。', 'ktpwp' ) );
		}
		$sheet_id = $m[1];
		$gid      = '0';
		if ( preg_match( '/[?&#]gid=(\d+)/', $url, $gm ) ) {
			$gid = $gm[1];
		}
		$export_url = 'https://docs.google.com/spreadsheets/d/' . rawurlencode( $sheet_id ) . '/export?format=csv&gid=' . rawurlencode( $gid );
		$response   = wp_remote_get( $export_url, array( 'timeout' => 30 ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'gs_fetch', __( 'スプレッドシートを取得できませんでした。共有設定を確認してください。', 'ktpwp' ) );
		}
		$body = wp_remote_retrieve_body( $response );
		if ( $body === '' ) {
			return new WP_Error( 'gs_empty', __( 'スプレッドシートにデータがありません。', 'ktpwp' ) );
		}
		return $body;
	}

	/**
	 * @param string $haystack Haystack.
	 * @param string $needle   Needle.
	 * @return bool
	 */
	private static function string_ends_with( $haystack, $needle ): bool {
		$needle   = (string) $needle;
		$haystack = (string) $haystack;
		if ( $needle === '' ) {
			return true;
		}
		return substr( $haystack, -strlen( $needle ) ) === $needle;
	}

	/**
	 * @return string
	 */
	private static function get_redirect_url(): string {
		if ( class_exists( 'KTPWP_Main' ) ) {
			return add_query_arg( 'tab_name', 'service', KTPWP_Main::get_current_page_base_url() );
		}
		return add_query_arg( 'tab_name', 'service', home_url( '/' ) );
	}

	/**
	 * @return string
	 */
	private static function get_redirect_url_from_post(): string {
		if ( isset( $_POST['redirect_to'] ) ) {
			$url = esc_url_raw( wp_unslash( $_POST['redirect_to'] ) );
			if ( $url !== '' ) {
				return $url;
			}
		}
		$referer = wp_get_referer();
		if ( $referer ) {
			return $referer;
		}
		return self::get_redirect_url();
	}

	/**
	 * @param string $table  Table name.
	 * @param string $column Column name.
	 * @return bool
	 */
	private static function table_has_column( $table, $column ): bool {
		global $wpdb;
		$column = sanitize_key( $column );
		if ( $column === '' ) {
			return false;
		}
		$found = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", $column ) );
		return ! empty( $found );
	}

	/**
	 * @param string $redirect Redirect URL.
	 * @param string $status   Status key.
	 * @param string $message  Error message.
	 * @return void
	 */
	private static function redirect_import_result( $redirect, $status, $message = '' ): void {
		$args = array(
			'tab_name'           => 'service',
			'ktp_service_import' => $status,
		);
		if ( $message !== '' ) {
			$args['error_msg'] = $message;
		}
		wp_safe_redirect( add_query_arg( $args, $redirect ) );
		exit;
	}
}
