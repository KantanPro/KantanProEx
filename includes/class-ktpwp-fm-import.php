<?php
/**
 * KantanPro（FileMaker Pro 版）由来の CSV/TSV（または Zip 内の1ファイル）を KantanProEX に取り込む（手動マッピング＋任意で OpenAI BYOK）。
 *
 * @package KantanProEX
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FileMaker 版データ取り込み（顧客・協力会社・商品）
 */
final class KTPWP_FM_Import {

	public const OPTION_OPENAI_KEY_ENC = 'ktp_fm_import_openai_key_enc';

	private const ENTITY_CLIENT   = 'client';
	private const ENTITY_SUPPLIER = 'supplier';
	private const ENTITY_SERVICE  = 'service';

	private const TRANSIENT_TTL   = 3600;
	private const MAX_FILE_BYTES  = 2097152; // 2 MiB
	private const MAX_DATA_ROWS   = 2000;
	private const NONCE_UPLOAD    = 'ktp_fm_import_upload';
	private const NONCE_IMPORT    = 'ktp_fm_import_run';
	private const NONCE_OPENAI    = 'ktp_fm_import_openai';
	private const NONCE_AJAX_AI   = 'ktp_fm_import_ai';

	/**
	 * @return void
	 */
	public static function bootstrap(): void {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin' ), 10, 1 );
		add_action( 'wp_ajax_ktp_fm_import_ai_mapping', array( __CLASS__, 'ajax_ai_mapping' ) );
	}

	/**
	 * @param string $hook_suffix Hook suffix.
	 * @return void
	 */
	public static function enqueue_admin( $hook_suffix ): void {
		if ( strpos( (string) $hook_suffix, 'ktp-fm-import' ) === false ) {
			return;
		}
		wp_enqueue_script(
			'ktp-fm-import-admin',
			plugin_dir_url( __FILE__ ) . '../js/ktp-fm-import-admin.js',
			array( 'jquery' ),
			defined( 'KANTANPRO_PLUGIN_VERSION' ) ? KANTANPRO_PLUGIN_VERSION : '1.0.0',
			true
		);
		wp_localize_script(
			'ktp-fm-import-admin',
			'ktpFmImport',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_AJAX_AI ),
				'i18n'    => array(
					'working' => __( 'AI に問い合わせ中…', 'ktpwp' ),
					'done'    => __( 'マッピングを反映しました。内容を確認してから取り込みを実行してください。', 'ktpwp' ),
					'error'   => __( 'AI マッピングに失敗しました。', 'ktpwp' ),
				),
			)
		);
	}

	/**
	 * 管理画面（ KantanPro 設定配下）
	 *
	 * @return void
	 */
	public static function render_admin_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'このページにアクセスする権限がありません。', 'ktpwp' ) );
		}

		if ( ! empty( $_GET['ktp_fm_reset'] ) && isset( $_GET['ktp_fm_reset_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['ktp_fm_reset_nonce'] ) ), 'ktp_fm_reset' ) ) {
			delete_transient( self::transient_key_for_user() );
			wp_safe_redirect( admin_url( 'admin.php?page=ktp-fm-import' ) );
			exit;
		}

		self::maybe_handle_save_openai_key();
		$upload_notice = self::maybe_handle_upload();
		$import_notice = self::maybe_handle_import();

		$transient_key = self::transient_key_for_user();
		$session       = get_transient( $transient_key );

		echo '<div class="wrap ktp-admin-wrap">';
		echo '<h1>' . esc_html__( 'FileMaker版データ取り込み', 'ktpwp' ) . '</h1>';

		echo '<div class="notice notice-info"><p>';
		echo esc_html__( 'KantanPro（FileMaker Pro 版）から UTF-8 で書き出した CSV / TSV、またはそのファイルをまとめた Zip（先頭の csv/tsv/tab/txt を1つ使用）をアップロードし、列の対応を確認してから取り込みます。', 'ktpwp' );
		echo ' ';
		echo esc_html__( '任意の「AI で列を提案」は、ご自身の OpenAI API キーで実行され、利用料金はお客様の OpenAI アカウントに発生します。', 'ktpwp' );
		echo '</p></div>';

		if ( is_string( $upload_notice ) && $upload_notice !== '' ) {
			echo wp_kses_post( $upload_notice );
		}
		if ( is_string( $import_notice ) && $import_notice !== '' ) {
			echo wp_kses_post( $import_notice );
		}

		self::render_openai_key_form();

		if ( is_array( $session ) && isset( $session['headers'], $session['rows'] ) ) {
			self::render_mapping_and_import( $session );
		} else {
			self::render_upload_form();
		}

		echo '</div>';
	}

	/**
	 * @return void
	 */
	private static function maybe_handle_save_openai_key(): void {
		if ( ! isset( $_POST['ktp_fm_openai_save'], $_POST['_wpnonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), self::NONCE_OPENAI ) ) {
			wp_die( esc_html__( 'セキュリティ検証に失敗しました。', 'ktpwp' ) );
		}
		$key = isset( $_POST['ktp_fm_openai_key'] ) ? sanitize_text_field( wp_unslash( $_POST['ktp_fm_openai_key'] ) ) : '';
		if ( $key === '' ) {
			delete_option( self::OPTION_OPENAI_KEY_ENC );
		} else {
			$enc = self::encrypt_string( $key );
			if ( $enc !== '' ) {
				update_option( self::OPTION_OPENAI_KEY_ENC, $enc, false );
			}
		}
	}

	/**
	 * @return string
	 */
	private static function maybe_handle_upload(): string {
		if ( ! isset( $_POST['ktp_fm_upload'], $_POST['_wpnonce'] ) ) {
			return '';
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), self::NONCE_UPLOAD ) ) {
			return '<div class="notice notice-error"><p>' . esc_html__( 'セキュリティ検証に失敗しました。', 'ktpwp' ) . '</p></div>';
		}
		if ( empty( $_FILES['ktp_fm_file']['tmp_name'] ) ) {
			return '<div class="notice notice-error"><p>' . esc_html__( 'ファイルを選択してください。', 'ktpwp' ) . '</p></div>';
		}

		$file = $_FILES['ktp_fm_file'];
		if ( ! empty( $file['error'] ) ) {
			return '<div class="notice notice-error"><p>' . esc_html__( 'ファイルのアップロードに失敗しました。', 'ktpwp' ) . '</p></div>';
		}
		if ( (int) $file['size'] > self::MAX_FILE_BYTES ) {
			return '<div class="notice notice-error"><p>' . esc_html__( 'ファイルが大きすぎます（2MB 以下にしてください）。', 'ktpwp' ) . '</p></div>';
		}

		$name     = isset( $file['name'] ) ? sanitize_file_name( $file['name'] ) : '';
		$ext      = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		$allowed  = array( 'csv', 'tsv', 'tab', 'txt', 'zip' );
		if ( ! in_array( $ext, $allowed, true ) ) {
			return '<div class="notice notice-error"><p>' . esc_html__( '拡張子は csv / tsv / tab / txt / zip のみ対応しています。', 'ktpwp' ) . '</p></div>';
		}

		$entity = isset( $_POST['ktp_fm_entity'] ) ? sanitize_key( wp_unslash( $_POST['ktp_fm_entity'] ) ) : self::ENTITY_CLIENT;
		if ( ! in_array( $entity, self::allowed_entities(), true ) ) {
			$entity = self::ENTITY_CLIENT;
		}

		$inner_label = $name;
		$raw         = self::read_upload_delimited_raw( $file['tmp_name'], $ext, $inner_label ); // Zip 時は $inner_label が「zip名 / 内側ファイル」に更新される。
		if ( is_wp_error( $raw ) ) {
			return '<div class="notice notice-error"><p>' . esc_html( $raw->get_error_message() ) . '</p></div>';
		}
		if ( ! is_string( $raw ) || $raw === '' ) {
			return '<div class="notice notice-error"><p>' . esc_html__( 'ファイルを読み取れませんでした。', 'ktpwp' ) . '</p></div>';
		}

		$parsed = self::parse_delimited_text( $raw );
		if ( is_wp_error( $parsed ) ) {
			return '<div class="notice notice-error"><p>' . esc_html( $parsed->get_error_message() ) . '</p></div>';
		}

		/** @var array{headers: string[], rows: array<int, array<int, string>>} $parsed */
		$rows = $parsed['rows'];
		if ( count( $rows ) > self::MAX_DATA_ROWS ) {
			$rows = array_slice( $rows, 0, self::MAX_DATA_ROWS );
		}

		$payload = array(
			'entity'      => $entity,
			'headers'     => $parsed['headers'],
			'rows'        => $rows,
			'filename'    => $inner_label,
			'uploaded_at' => time(),
		);
		set_transient( self::transient_key_for_user(), $payload, self::TRANSIENT_TTL );

		return '<div class="notice notice-success"><p>' . esc_html(
			sprintf(
				/* translators: %d: row count */
				__( '解析しました（最大 %d 行まで取り込み対象）。列の対応を確認してください。', 'ktpwp' ),
				count( $rows )
			)
		) . '</p></div>';
	}

	/**
	 * @return string
	 */
	private static function maybe_handle_import(): string {
		if ( ! isset( $_POST['ktp_fm_import_run'], $_POST['_wpnonce'] ) ) {
			return '';
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), self::NONCE_IMPORT ) ) {
			return '<div class="notice notice-error"><p>' . esc_html__( 'セキュリティ検証に失敗しました。', 'ktpwp' ) . '</p></div>';
		}

		$key = self::transient_key_for_user();
		$session = get_transient( $key );
		if ( ! is_array( $session ) || empty( $session['headers'] ) || ! isset( $session['rows'] ) ) {
			return '<div class="notice notice-error"><p>' . esc_html__( 'セッションが切れました。ファイルを再度アップロードしてください。', 'ktpwp' ) . '</p></div>';
		}

		$headers = $session['headers'];
		$rows    = $session['rows'];
		$entity  = isset( $session['entity'] ) ? self::normalize_entity( $session['entity'] ) : self::ENTITY_CLIENT;
		$map_in  = isset( $_POST['ktp_fm_map'] ) && is_array( $_POST['ktp_fm_map'] ) ? wp_unslash( $_POST['ktp_fm_map'] ) : array();

		$map = array();
		foreach ( self::target_fields_for_entity( $entity ) as $field => $_label ) {
			if ( ! isset( $map_in[ $field ] ) ) {
				continue;
			}
			$idx = sanitize_text_field( (string) $map_in[ $field ] );
			if ( $idx === '' || $idx === '__skip__' ) {
				continue;
			}
			$i = (int) $idx;
			if ( $i >= 0 && $i < count( $headers ) ) {
				$map[ $field ] = $i;
			}
		}

		$skip_dup_email   = ! empty( $_POST['ktp_fm_skip_dup_email'] );
		$skip_dup_service = ! empty( $_POST['ktp_fm_skip_dup_service_name'] );

		if ( $entity === self::ENTITY_CLIENT ) {
			$result = self::import_client_rows( $rows, $headers, $map, $skip_dup_email );
		} elseif ( $entity === self::ENTITY_SUPPLIER ) {
			$result = self::import_supplier_rows( $rows, $headers, $map, $skip_dup_email );
		} else {
			$result = self::import_service_rows( $rows, $headers, $map, $skip_dup_service );
		}
		delete_transient( $key );

		$cls = $result['errors'] > 0 ? 'notice-warning' : 'notice-success';
		$msg = sprintf(
			/* translators: 1: inserted count, 2: skipped, 3: errors */
			__( '取り込み完了：追加 %1$d 件、スキップ %2$d 件、エラー %3$d 件。', 'ktpwp' ),
			(int) $result['inserted'],
			(int) $result['skipped'],
			(int) $result['errors']
		);

		return '<div class="notice ' . esc_attr( $cls ) . ' is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
	}

	/**
	 * @return void
	 */
	public static function ajax_ai_mapping(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( '権限がありません。', 'ktpwp' ) ), 403 );
		}
		check_ajax_referer( self::NONCE_AJAX_AI, 'nonce' );

		$key = self::decrypt_api_key( (string) get_option( self::OPTION_OPENAI_KEY_ENC, '' ) );
		if ( $key === '' ) {
			wp_send_json_error( array( 'message' => __( 'OpenAI API キーが未設定です。', 'ktpwp' ) ) );
		}

		$headers = isset( $_POST['headers'] ) ? json_decode( wp_unslash( (string) $_POST['headers'] ), true ) : null;
		$samples = isset( $_POST['samples'] ) ? json_decode( wp_unslash( (string) $_POST['samples'] ), true ) : null;
		if ( ! is_array( $headers ) || $headers === array() ) {
			wp_send_json_error( array( 'message' => __( 'ヘッダーが不正です。', 'ktpwp' ) ) );
		}
		$headers = array_map( 'sanitize_text_field', array_map( 'strval', $headers ) );
		if ( ! is_array( $samples ) ) {
			$samples = array();
		}

		$entity = isset( $_POST['entity'] ) ? self::normalize_entity( wp_unslash( $_POST['entity'] ) ) : self::ENTITY_CLIENT;

		$system = self::build_openai_system_prompt_for_entity( $entity );
		$user   = wp_json_encode(
			array(
				'headers'       => $headers,
				'sample_rows'   => array_slice( $samples, 0, 3 ),
				'target_fields' => array_keys( self::target_fields_for_entity( $entity ) ),
			),
			JSON_UNESCAPED_UNICODE
		);

		$response = wp_remote_post(
			'https://api.openai.com/v1/chat/completions',
			array(
				'timeout' => 90,
				'headers' => array(
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'           => 'gpt-4o-mini',
						'temperature'     => 0.1,
						'response_format' => array( 'type' => 'json_object' ),
						'messages'        => array(
							array( 'role' => 'system', 'content' => $system ),
							array( 'role' => 'user', 'content' => $user ),
						),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => $response->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			$msg = is_array( $body ) && isset( $body['error']['message'] ) ? (string) $body['error']['message'] : __( 'OpenAI API エラー', 'ktpwp' );
			wp_send_json_error( array( 'message' => $msg ) );
		}
		$content = is_array( $body ) && isset( $body['choices'][0]['message']['content'] ) ? (string) $body['choices'][0]['message']['content'] : '';
		$decoded = json_decode( $content, true );
		if ( ! is_array( $decoded ) || empty( $decoded['column_map'] ) || ! is_array( $decoded['column_map'] ) ) {
			wp_send_json_error( array( 'message' => __( 'AI の応答を解釈できませんでした。', 'ktpwp' ) ) );
		}

		$out_map = array();
		foreach ( $decoded['column_map'] as $kantan_field => $header_name ) {
			$kantan_field = sanitize_key( (string) $kantan_field );
			if ( ! isset( self::target_fields_for_entity( $entity )[ $kantan_field ] ) ) {
				continue;
			}
			if ( $header_name === null || $header_name === '' ) {
				continue;
			}
			$header_name = sanitize_text_field( (string) $header_name );
			$idx         = array_search( $header_name, $headers, true );
			if ( $idx !== false ) {
				$out_map[ $kantan_field ] = (int) $idx;
			}
		}

		wp_send_json_success( array( 'map' => $out_map ) );
	}

	/**
	 * @param string $entity Entity.
	 * @return string
	 */
	private static function build_openai_system_prompt_for_entity( $entity ): string {
		$labels = self::target_fields_for_entity( $entity );
		$lines  = array();
		foreach ( $labels as $k => $lab ) {
			$lines[] = "- {$k} … {$lab}";
		}
		$list = implode( "\n", $lines );

		if ( $entity === self::ENTITY_SUPPLIER ) {
			$role = '協力会社（supplier）マスタ';
		} elseif ( $entity === self::ENTITY_SERVICE ) {
			$role = '商品・サービス（service）マスタ';
		} else {
			$role = '顧客（client）マスタ';
		}

		return <<<PROMPT
あなたは KantanPro（FileMaker Pro 版）から CSV/TSV で書き出された列を、WordPress プラグイン KantanProEX の{$role}の列に対応づける専門家です。
入力 JSON の headers（列名の配列）と sample_rows（最大3行の配列の配列）を見て、次の Kantan 側フィールド名ごとに「一致する元の列名」を1つ選ぶ。該当がなければ null。

【対象フィールド】
{$list}

JSON のみ。形式:
{ "column_map": { "フィールド名": "元CSVの列名またはnull", ... } }
列名は headers に存在する文字列と完全一致にすること。
PROMPT;
	}

	/**
	 * @param string $entity client|supplier|service.
	 * @return array<string, string>
	 */
	private static function target_fields_for_entity( $entity ): array {
		$entity = self::normalize_entity( $entity );
		if ( $entity === self::ENTITY_SUPPLIER ) {
			return array(
				'company_name'             => __( '会社名', 'ktpwp' ),
				'name'                     => __( '担当者名', 'ktpwp' ),
				'email'                    => __( 'メール', 'ktpwp' ),
				'url'                      => __( 'URL', 'ktpwp' ),
				'representative_name'      => __( '代表者名', 'ktpwp' ),
				'phone'                    => __( '電話', 'ktpwp' ),
				'postal_code'              => __( '郵便番号', 'ktpwp' ),
				'prefecture'               => __( '都道府県', 'ktpwp' ),
				'city'                     => __( '市区町村', 'ktpwp' ),
				'address'                  => __( '住所', 'ktpwp' ),
				'building'                 => __( '建物名', 'ktpwp' ),
				'closing_day'              => __( '締め日', 'ktpwp' ),
				'payment_month'            => __( '支払月', 'ktpwp' ),
				'payment_day'              => __( '支払日', 'ktpwp' ),
				'payment_method'           => __( '支払方法', 'ktpwp' ),
				'tax_category'             => __( '税区分', 'ktpwp' ),
				'memo'                     => __( 'メモ', 'ktpwp' ),
				'qualified_invoice_number' => __( '適格請求書番号', 'ktpwp' ),
				'category'                 => __( 'カテゴリ', 'ktpwp' ),
			);
		}
		if ( $entity === self::ENTITY_SERVICE ) {
			return array(
				'service_name' => __( '商品・サービス名', 'ktpwp' ),
				'price'        => __( '単価（数値）', 'ktpwp' ),
				'tax_rate'     => __( '税率（%・空可）', 'ktpwp' ),
				'unit'         => __( '単位', 'ktpwp' ),
				'memo'         => __( 'メモ', 'ktpwp' ),
				'category'     => __( 'カテゴリ', 'ktpwp' ),
			);
		}

		return array(
			'company_name'        => __( '会社名', 'ktpwp' ),
			'name'                => __( '担当者名（顧客側）', 'ktpwp' ),
			'email'               => __( 'メール', 'ktpwp' ),
			'url'                 => __( 'URL', 'ktpwp' ),
			'representative_name' => __( '代表者名', 'ktpwp' ),
			'phone'               => __( '電話', 'ktpwp' ),
			'postal_code'         => __( '郵便番号', 'ktpwp' ),
			'prefecture'          => __( '都道府県', 'ktpwp' ),
			'city'                => __( '市区町村', 'ktpwp' ),
			'address'             => __( '住所', 'ktpwp' ),
			'building'            => __( '建物名', 'ktpwp' ),
			'closing_day'         => __( '締め日', 'ktpwp' ),
			'payment_month'       => __( '支払月', 'ktpwp' ),
			'payment_day'         => __( '支払日', 'ktpwp' ),
			'payment_method'      => __( '支払方法', 'ktpwp' ),
			'tax_category'        => __( '税区分', 'ktpwp' ),
			'payment_timing'      => __( '支払タイミング（postpay/prepay/prepay_wc）', 'ktpwp' ),
			'memo'                => __( 'メモ', 'ktpwp' ),
			'client_status'       => __( '顧客ステータス', 'ktpwp' ),
			'category'            => __( 'カテゴリ', 'ktpwp' ),
		);
	}

	/**
	 * @return void
	 */
	private static function render_openai_key_form(): void {
		$has_key = get_option( self::OPTION_OPENAI_KEY_ENC, '' ) !== '';
		echo '<div class="card" style="max-width:720px;margin:16px 0;padding:16px;">';
		echo '<h2>' . esc_html__( 'OpenAI API キー（任意・BYOK）', 'ktpwp' ) . '</h2>';
		echo '<p class="description">' . esc_html__( '「AI で列を提案」を使うときのみ必要です。キーはサイト内で暗号化して保存し、OpenAI の従量課金はご利用のアカウントに発生します。', 'ktpwp' ) . '</p>';
		echo '<form method="post" action="">';
		wp_nonce_field( self::NONCE_OPENAI );
		echo '<p><input type="password" name="ktp_fm_openai_key" class="regular-text" autocomplete="off" placeholder="' . esc_attr( $has_key ? __( '（保存済み・上書きする場合のみ入力）', 'ktpwp' ) : '' ) . '" /></p>';
		echo '<p><button type="submit" name="ktp_fm_openai_save" class="button">' . esc_html__( 'API キーを保存', 'ktpwp' ) . '</button> ';
		if ( $has_key ) {
			echo '<span class="description">' . esc_html__( '保存済みのキーを削除するには空のまま保存してください。', 'ktpwp' ) . '</span>';
		}
		echo '</p></form></div>';
	}

	/**
	 * @return void
	 */
	private static function render_upload_form(): void {
		echo '<div class="card" style="max-width:720px;margin:16px 0;padding:16px;">';
		echo '<h2>' . esc_html__( '1. ファイルをアップロード', 'ktpwp' ) . '</h2>';
		echo '<form method="post" enctype="multipart/form-data" action="">';
		wp_nonce_field( self::NONCE_UPLOAD );
		echo '<p><label for="ktp-fm-entity">' . esc_html__( '取り込み先のデータ種別', 'ktpwp' ) . '</label><br />';
		echo '<select name="ktp_fm_entity" id="ktp-fm-entity" style="min-width:280px;margin-top:6px;">';
		echo '<option value="' . esc_attr( self::ENTITY_CLIENT ) . '">' . esc_html__( '顧客', 'ktpwp' ) . '</option>';
		echo '<option value="' . esc_attr( self::ENTITY_SUPPLIER ) . '">' . esc_html__( '協力会社', 'ktpwp' ) . '</option>';
		echo '<option value="' . esc_attr( self::ENTITY_SERVICE ) . '">' . esc_html__( '商品（サービス）', 'ktpwp' ) . '</option>';
		echo '</select></p>';
		echo '<p><input type="file" name="ktp_fm_file" accept=".csv,.tsv,.tab,.txt,.zip,application/zip,text/csv" required /></p>';
		echo '<p><button type="submit" name="ktp_fm_upload" class="button button-primary">' . esc_html__( '解析する', 'ktpwp' ) . '</button></p>';
		echo '</form></div>';
	}

	/**
	 * @param array<string, mixed> $session Session payload.
	 * @return void
	 */
	private static function render_mapping_and_import( array $session ): void {
		$headers = $session['headers'];
		$rows    = $session['rows'];
		$entity  = isset( $session['entity'] ) ? self::normalize_entity( $session['entity'] ) : self::ENTITY_CLIENT;
		$guess   = self::guess_for_entity( $entity, $headers );

		$entity_label = self::entity_label( $entity );

		echo '<div class="card" style="margin:16px 0;padding:16px;">';
		echo '<p><strong>' . esc_html__( '対象ファイル:', 'ktpwp' ) . '</strong> ' . esc_html( isset( $session['filename'] ) ? (string) $session['filename'] : '' ) . '</p>';
		echo '<h2>' . esc_html__( '2. 列の対応', 'ktpwp' ) . '（' . esc_html( $entity_label ) . '）</h2>';
		echo '<p><button type="button" class="button" id="ktp-fm-ai-suggest">' . esc_html__( 'AI で列を提案（OpenAI）', 'ktpwp' ) . '</button> ';
		echo '<span class="description" id="ktp-fm-ai-status"></span></p>';

		echo '<form method="post" action="" id="ktp-fm-import-form">';
		wp_nonce_field( self::NONCE_IMPORT );

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( '取り込み先', 'ktpwp' ) . '</th><th>' . esc_html__( 'ファイルの列', 'ktpwp' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( self::target_fields_for_entity( $entity ) as $field => $label ) {
			$selected = isset( $guess[ $field ] ) ? (int) $guess[ $field ] : '';
			echo '<tr><th scope="row">' . esc_html( $label ) . '<br><code style="font-size:11px;">' . esc_html( $field ) . '</code></th><td>';
			echo '<select name="ktp_fm_map[' . esc_attr( $field ) . ']">';
			echo '<option value="__skip__">' . esc_html__( '（マッピングしない）', 'ktpwp' ) . '</option>';
			foreach ( $headers as $i => $h ) {
				$opt = esc_html( $h !== '' ? $h : (string) $i );
				echo '<option value="' . (int) $i . '"' . selected( $selected, $i, false ) . '>' . $opt . '</option>';
			}
			echo '</select></td></tr>';
		}

		echo '</tbody></table>';

		if ( $entity === self::ENTITY_CLIENT || $entity === self::ENTITY_SUPPLIER ) {
			echo '<p style="margin-top:12px;"><label><input type="checkbox" name="ktp_fm_skip_dup_email" value="1" checked /> ';
			echo esc_html__( 'メールアドレスが既に登録済みの行はスキップする', 'ktpwp' ) . '</label></p>';
		}
		if ( $entity === self::ENTITY_SERVICE ) {
			echo '<p style="margin-top:12px;"><label><input type="checkbox" name="ktp_fm_skip_dup_service_name" value="1" checked /> ';
			echo esc_html__( '同名の商品（サービス名）が既に登録済みの行はスキップする', 'ktpwp' ) . '</label></p>';
		}

		$confirm = __( 'データベースに追加します。よろしいですか？', 'ktpwp' );
		echo '<p><button type="submit" name="ktp_fm_import_run" class="button button-primary" onclick="return confirm(\'' . esc_js( $confirm ) . '\');">';
		echo esc_html__( '取り込みを実行', 'ktpwp' ) . '</button> ';
		echo '<a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin.php?page=ktp-fm-import&ktp_fm_reset=1' ), 'ktp_fm_reset', 'ktp_fm_reset_nonce' ) ) . '">' . esc_html__( '最初からやり直す', 'ktpwp' ) . '</a></p>';
		echo '</form>';

		echo '<h3>' . esc_html__( 'プレビュー（先頭5行）', 'ktpwp' ) . '</h3>';
		echo '<div style="overflow:auto;max-height:280px;border:1px solid #ccd0d4;">';
		echo '<table class="widefat" style="font-size:12px;"><thead><tr>';
		foreach ( $headers as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( array_slice( $rows, 0, 5 ) as $r ) {
			echo '<tr>';
			foreach ( $headers as $ci => $_h ) {
				$cell = isset( $r[ $ci ] ) ? (string) $r[ $ci ] : '';
				$cell_short = function_exists( 'mb_substr' ) ? mb_substr( $cell, 0, 80 ) : substr( $cell, 0, 80 );
				echo '<td>' . esc_html( $cell_short ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table></div>';

		$data_for_js = wp_json_encode(
			array(
				'entity'  => $entity,
				'headers' => $headers,
				'samples' => array_slice( $rows, 0, 3 ),
			),
			JSON_UNESCAPED_UNICODE
		);
		echo '<script type="application/json" id="ktp-fm-import-bootstrap">' . esc_html( $data_for_js ) . '</script>';

		echo '</div>';
	}

	/**
	 * @param string   $entity  Entity.
	 * @param string[] $headers Headers.
	 * @return array<string, int>
	 */
	private static function guess_for_entity( $entity, array $headers ): array {
		$entity = self::normalize_entity( $entity );
		if ( $entity === self::ENTITY_SUPPLIER ) {
			return self::guess_supplier_column_indexes( $headers );
		}
		if ( $entity === self::ENTITY_SERVICE ) {
			return self::guess_service_column_indexes( $headers );
		}
		return self::guess_client_column_indexes( $headers );
	}

	/**
	 * @param string[] $headers Header labels.
	 * @return array<string, int>
	 */
	private static function guess_client_column_indexes( array $headers ): array {
		return self::guess_by_rules(
			$headers,
			array(
				'company_name'        => array( '会社名', '取引先', '社名', '顧客名', 'company', 'client', '顧客' ),
				'name'                => array( '担当者', '氏名', '名前', 'name', 'contact', 'ご担当' ),
				'email'               => array( 'メール', 'e-mail', 'mail', 'email' ),
				'phone'               => array( '電話', 'tel', 'phone', '携帯' ),
				'postal_code'         => array( '郵便', 'zip', 'postal' ),
				'prefecture'          => array( '都道府県', 'prefecture' ),
				'city'                => array( '市区町村', 'city' ),
				'address'             => array( '住所', 'address' ),
				'building'            => array( '建物', 'ビル', 'building' ),
				'representative_name' => array( '代表', '代表者' ),
				'memo'                => array( 'メモ', '備考', 'memo', 'note' ),
				'category'            => array( 'カテゴリ', '分類', 'category' ),
				'tax_category'        => array( '税区分', '税', 'tax' ),
			)
		);
	}

	/**
	 * @param string[] $headers Header labels.
	 * @return array<string, int>
	 */
	private static function guess_supplier_column_indexes( array $headers ): array {
		return self::guess_by_rules(
			$headers,
			array(
				'company_name'             => array( '会社名', '社名', '協力会社', '仕入先', '外注', 'supplier', 'ベンダ' ),
				'name'                     => array( '担当者', '氏名', '名前', 'contact' ),
				'email'                    => array( 'メール', 'e-mail', 'mail', 'email' ),
				'phone'                    => array( '電話', 'tel', 'phone' ),
				'postal_code'              => array( '郵便', 'zip', 'postal' ),
				'prefecture'               => array( '都道府県', 'prefecture' ),
				'city'                     => array( '市区町村', 'city' ),
				'address'                  => array( '住所', 'address' ),
				'building'                 => array( '建物', 'ビル', 'building' ),
				'representative_name'      => array( '代表', '代表者' ),
				'qualified_invoice_number' => array( '適格', 'インボイス', '登録番号', 't+', 'invoice' ),
				'memo'                     => array( 'メモ', '備考', 'memo' ),
				'category'                 => array( 'カテゴリ', '分類', 'category' ),
				'tax_category'             => array( '税区分', '税', 'tax' ),
				'closing_day'              => array( '締め', '締日', 'closing' ),
				'payment_month'            => array( '支払月' ),
				'payment_day'              => array( '支払日' ),
				'payment_method'           => array( '支払方法', '振込', 'payment' ),
			)
		);
	}

	/**
	 * @param string[] $headers Header labels.
	 * @return array<string, int>
	 */
	private static function guess_service_column_indexes( array $headers ): array {
		return self::guess_by_rules(
			$headers,
			array(
				'service_name' => array( '商品', 'サービス', '品名', '名称', 'name', 'service', '項目' ),
				'price'        => array( '単価', '価格', '金額', 'price', '料金' ),
				'tax_rate'     => array( '税率', 'tax', '%' ),
				'unit'         => array( '単位', 'unit' ),
				'memo'         => array( 'メモ', '備考', '説明' ),
				'category'     => array( 'カテゴリ', '分類', 'category' ),
			)
		);
	}

	/**
	 * @param string[]                $headers Header labels.
	 * @param array<string, string[]> $rules   Field => keyword list.
	 * @return array<string, int>
	 */
	private static function guess_by_rules( array $headers, array $rules ): array {
		$norm = array();
		foreach ( $headers as $i => $h ) {
			$norm[ $i ] = mb_strtolower( trim( (string) $h ) );
		}

		$out = array();
		foreach ( $rules as $field => $keywords ) {
			foreach ( $norm as $idx => $low ) {
				foreach ( $keywords as $kw ) {
					if ( $low !== '' && mb_strpos( $low, mb_strtolower( $kw ) ) !== false ) {
						$out[ $field ] = (int) $idx;
						break 2;
					}
				}
			}
		}

		return $out;
	}

	/**
	 * @param array<int, array<int, string>> $rows Data rows.
	 * @param string[]                       $headers Headers.
	 * @param array<string, int>             $map Field => column index.
	 * @param bool                           $skip_dup Skip duplicate emails.
	 * @return array{inserted: int, skipped: int, errors: int}
	 */
	private static function import_client_rows( array $rows, array $headers, array $map, bool $skip_dup ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'ktp_client';

		$inserted = 0;
		$skipped  = 0;
		$errors   = 0;

		$default_timing = 'postpay';

		foreach ( $rows as $row ) {
			$data = self::build_client_row_from_map( $row, $map );
			if ( trim( (string) ( $data['company_name'] ?? '' ) ) === '' ) {
				$skipped++;
				continue;
			}

			$email = isset( $data['email'] ) ? trim( (string) $data['email'] ) : '';
			if ( $skip_dup && $email !== '' ) {
				$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE email = %s", $email ) );
				if ( $exists > 0 ) {
					$skipped++;
					continue;
				}
			}

			$search = self::build_client_search_field( $data );

			$insert = array(
				'time'                   => current_time( 'mysql' ),
				'company_name'           => $data['company_name'],
				'name'                   => isset( $data['name'] ) ? $data['name'] : '',
				'email'                  => $email,
				'url'                    => isset( $data['url'] ) ? $data['url'] : '',
				'representative_name'    => isset( $data['representative_name'] ) ? $data['representative_name'] : '',
				'phone'                  => isset( $data['phone'] ) ? $data['phone'] : '',
				'postal_code'            => isset( $data['postal_code'] ) ? $data['postal_code'] : '',
				'prefecture'             => isset( $data['prefecture'] ) ? $data['prefecture'] : '',
				'city'                   => isset( $data['city'] ) ? $data['city'] : '',
				'address'                => isset( $data['address'] ) ? $data['address'] : '',
				'building'               => isset( $data['building'] ) ? $data['building'] : '',
				'closing_day'            => isset( $data['closing_day'] ) ? $data['closing_day'] : '',
				'payment_month'          => isset( $data['payment_month'] ) ? $data['payment_month'] : '',
				'payment_day'            => isset( $data['payment_day'] ) ? $data['payment_day'] : '',
				'payment_method'         => isset( $data['payment_method'] ) ? $data['payment_method'] : '',
				'tax_category'           => isset( $data['tax_category'] ) && $data['tax_category'] !== '' ? $data['tax_category'] : __( '内税', 'ktpwp' ),
				'payment_timing'         => isset( $data['payment_timing'] ) && in_array( $data['payment_timing'], array( 'postpay', 'prepay', 'prepay_wc' ), true ) ? $data['payment_timing'] : $default_timing,
				'memo'                   => isset( $data['memo'] ) ? $data['memo'] : '',
				'client_status'          => isset( $data['client_status'] ) && $data['client_status'] !== '' ? $data['client_status'] : __( '対象', 'ktpwp' ),
				'category'               => isset( $data['category'] ) ? $data['category'] : '',
				'selected_department_id' => null,
				'search_field'           => $search,
			);

			// time … category まで 21 列が文字列、selected_department_id、search_field。
			$formats = array_merge(
				array_fill( 0, 21, '%s' ),
				array( '%d', '%s' )
			);

			$res = $wpdb->insert( $table, $insert, $formats );
			if ( $res === false ) {
				$errors++;
			} else {
				$inserted++;
			}
		}

		return array(
			'inserted' => $inserted,
			'skipped'  => $skipped,
			'errors'   => $errors,
		);
	}

	/**
	 * @param array<int, string>   $row CSV row.
	 * @param array<string, int>   $map Map.
	 * @return array<string, string>
	 */
	private static function build_client_row_from_map( array $row, array $map ): array {
		$out = array();
		foreach ( $map as $field => $col_idx ) {
			$val = isset( $row[ $col_idx ] ) ? trim( (string) $row[ $col_idx ] ) : '';
			switch ( $field ) {
				case 'email':
					$out[ $field ] = sanitize_email( $val );
					break;
				case 'url':
					$out[ $field ] = $val !== '' ? esc_url_raw( $val ) : '';
					break;
				case 'payment_timing':
					$v = strtolower( $val );
					if ( in_array( $v, array( 'prepay', 'postpay', 'prepay_wc' ), true ) ) {
						$out[ $field ] = $v;
					} elseif ( strpos( $v, '前' ) !== false || strpos( $v, '先払' ) !== false ) {
						$out[ $field ] = 'prepay';
					} else {
						$out[ $field ] = '';
					}
					break;
				default:
					$out[ $field ] = sanitize_text_field( $val );
			}
		}
		if ( ! isset( $out['company_name'] ) ) {
			$out['company_name'] = '';
		}
		return $out;
	}

	/**
	 * @param array<string, string> $data Row.
	 * @return string
	 */
	private static function build_client_search_field( array $data ): string {
		$parts = array(
			$data['company_name'] ?? '',
			$data['name'] ?? '',
			$data['email'] ?? '',
			$data['representative_name'] ?? '',
			$data['phone'] ?? '',
			$data['prefecture'] ?? '',
			$data['city'] ?? '',
			$data['address'] ?? '',
			$data['client_status'] ?? '',
			$data['category'] ?? '',
		);
		return implode( ' ', array_map( 'strval', $parts ) );
	}

	/**
	 * @param array<int, string>   $row CSV row.
	 * @param array<string, int>   $map Map.
	 * @return array<string, string>
	 */
	private static function build_supplier_row_from_map( array $row, array $map ): array {
		$out = array();
		foreach ( $map as $field => $col_idx ) {
			$val = isset( $row[ $col_idx ] ) ? trim( (string) $row[ $col_idx ] ) : '';
			switch ( $field ) {
				case 'email':
					$out[ $field ] = sanitize_email( $val );
					break;
				case 'url':
					$out[ $field ] = $val !== '' ? esc_url_raw( $val ) : '';
					break;
				case 'memo':
					$out[ $field ] = sanitize_textarea_field( $val );
					break;
				default:
					$out[ $field ] = sanitize_text_field( $val );
			}
		}
		if ( ! isset( $out['company_name'] ) ) {
			$out['company_name'] = '';
		}
		return $out;
	}

	/**
	 * @param array<string, string> $data Row.
	 * @return string
	 */
	private static function build_supplier_search_field( array $data ): string {
		return implode(
			', ',
			array(
				(string) current_time( 'timestamp' ),
				$data['company_name'] ?? '',
				$data['name'] ?? '',
				$data['email'] ?? '',
				$data['url'] ?? '',
				$data['representative_name'] ?? '',
				$data['phone'] ?? '',
				$data['postal_code'] ?? '',
				$data['prefecture'] ?? '',
				$data['city'] ?? '',
				$data['address'] ?? '',
				$data['building'] ?? '',
				$data['closing_day'] ?? '',
				$data['payment_month'] ?? '',
				$data['payment_day'] ?? '',
				$data['payment_method'] ?? '',
				$data['tax_category'] ?? '',
				$data['memo'] ?? '',
				$data['qualified_invoice_number'] ?? '',
				$data['category'] ?? '',
			)
		);
	}

	/**
	 * @param array<int, array<int, string>> $rows    Rows.
	 * @param string[]                       $headers Headers.
	 * @param array<string, int>             $map     Map.
	 * @param bool                             $skip_dup Skip duplicate email.
	 * @return array{inserted: int, skipped: int, errors: int}
	 */
	private static function import_supplier_rows( array $rows, array $headers, array $map, bool $skip_dup ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'ktp_supplier';

		$inserted = 0;
		$skipped  = 0;
		$errors   = 0;

		foreach ( $rows as $row ) {
			$data = self::build_supplier_row_from_map( $row, $map );
			if ( trim( (string) ( $data['company_name'] ?? '' ) ) === '' ) {
				$skipped++;
				continue;
			}

			$email = isset( $data['email'] ) ? trim( (string) $data['email'] ) : '';
			if ( $skip_dup && $email !== '' ) {
				$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE email = %s", $email ) );
				if ( $exists > 0 ) {
					$skipped++;
					continue;
				}
			}

			$defaults = array(
				'name'                     => '',
				'url'                      => '',
				'representative_name'      => '',
				'phone'                    => '',
				'postal_code'              => '',
				'prefecture'               => '',
				'city'                     => '',
				'address'                  => '',
				'building'                 => '',
				'closing_day'              => '',
				'payment_month'            => '',
				'payment_day'              => '',
				'payment_method'           => '',
				'tax_category'             => __( '内税', 'ktpwp' ),
				'memo'                     => '',
				'qualified_invoice_number' => '',
				'category'                 => __( 'General', 'ktpwp' ),
			);
			foreach ( $defaults as $k => $v ) {
				if ( ! isset( $data[ $k ] ) || $data[ $k ] === '' ) {
					$data[ $k ] = $v;
				}
			}
			if ( $data['tax_category'] === '' ) {
				$data['tax_category'] = __( '内税', 'ktpwp' );
			}

			$search = self::build_supplier_search_field( $data );

			$insert = array(
				'time'                     => current_time( 'timestamp' ),
				'company_name'             => $data['company_name'],
				'name'                     => $data['name'],
				'email'                    => $email,
				'url'                      => $data['url'],
				'representative_name'      => $data['representative_name'],
				'phone'                    => $data['phone'],
				'postal_code'              => $data['postal_code'],
				'prefecture'               => $data['prefecture'],
				'city'                     => $data['city'],
				'address'                  => $data['address'],
				'building'                 => $data['building'],
				'closing_day'              => $data['closing_day'],
				'payment_month'            => $data['payment_month'],
				'payment_day'              => $data['payment_day'],
				'payment_method'           => $data['payment_method'],
				'tax_category'             => $data['tax_category'],
				'memo'                     => $data['memo'],
				'qualified_invoice_number' => $data['qualified_invoice_number'],
				'category'                 => $data['category'],
				'search_field'             => $search,
			);

			$formats = array(
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
			);

			$res = $wpdb->insert( $table, $insert, $formats );
			if ( $res === false ) {
				$errors++;
			} else {
				$inserted++;
			}
		}

		return array(
			'inserted' => $inserted,
			'skipped'  => $skipped,
			'errors'   => $errors,
		);
	}

	/**
	 * @param array<int, string>   $row CSV row.
	 * @param array<string, int>   $map Map.
	 * @return array<string, mixed>
	 */
	private static function build_service_row_from_map( array $row, array $map ): array {
		$out = array();
		foreach ( $map as $field => $col_idx ) {
			$val = isset( $row[ $col_idx ] ) ? trim( (string) $row[ $col_idx ] ) : '';
			if ( $field === 'price' ) {
				$val = str_replace( array( ',', ' ', '　' ), '', $val );
				$out['price'] = is_numeric( $val ) ? (float) $val : 0.0;
				continue;
			}
			if ( $field === 'tax_rate' ) {
				$val = str_replace( array( ',', '%', ' ', '　' ), '', $val );
				if ( $val === '' || ! is_numeric( $val ) ) {
					$out['tax_rate'] = null;
				} else {
					$out['tax_rate'] = (float) $val;
				}
				continue;
			}
			if ( $field === 'memo' ) {
				$out['memo'] = sanitize_textarea_field( $val );
				continue;
			}
			$out[ $field ] = sanitize_text_field( $val );
		}
		if ( ! isset( $out['service_name'] ) ) {
			$out['service_name'] = '';
		}
		if ( ! isset( $out['price'] ) ) {
			$out['price'] = 0.0;
		}
		return $out;
	}

	/**
	 * @param array<int, array<int, string>> $rows    Rows.
	 * @param string[]                       $headers Headers.
	 * @param array<string, int>             $map     Map.
	 * @param bool                             $skip_dup Skip duplicate service_name.
	 * @return array{inserted: int, skipped: int, errors: int}
	 */
	private static function import_service_rows( array $rows, array $headers, array $map, bool $skip_dup ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'ktp_service';

		$inserted = 0;
		$skipped  = 0;
		$errors   = 0;

		foreach ( $rows as $row ) {
			$data = self::build_service_row_from_map( $row, $map );
			$name = trim( (string) ( $data['service_name'] ?? '' ) );
			if ( $name === '' ) {
				$skipped++;
				continue;
			}

			if ( $skip_dup ) {
				$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE service_name = %s", $name ) );
				if ( $exists > 0 ) {
					$skipped++;
					continue;
				}
			}

			$unit     = isset( $data['unit'] ) ? (string) $data['unit'] : '';
			$memo     = isset( $data['memo'] ) ? (string) $data['memo'] : '';
			$category = isset( $data['category'] ) && $data['category'] !== '' ? (string) $data['category'] : __( 'General', 'ktpwp' );
			$price    = isset( $data['price'] ) ? (float) $data['price'] : 0.0;
			$tax_rate = array_key_exists( 'tax_rate', $data ) ? $data['tax_rate'] : null;

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

			$insert = array(
				'time'         => current_time( 'mysql' ),
				'service_name' => $name,
				'price'        => $price,
			);
			$formats = array( '%s', '%s', '%f' );

			if ( $tax_rate !== null ) {
				$insert['tax_rate'] = $tax_rate;
				$formats[]        = '%f';
			}

			$insert['unit']         = $unit;
			$insert['memo']         = $memo;
			$insert['category']     = $category;
			$insert['image_url']    = '';
			$insert['frequency']    = 0;
			$insert['search_field'] = $search;

			$formats[] = '%s';
			$formats[] = '%s';
			$formats[] = '%s';
			$formats[] = '%s';
			$formats[] = '%d';
			$formats[] = '%s';

			$res = $wpdb->insert( $table, $insert, $formats );
			if ( $res === false ) {
				$errors++;
			} else {
				$inserted++;
			}
		}

		return array(
			'inserted' => $inserted,
			'skipped'  => $skipped,
			'errors'   => $errors,
		);
	}

	/**
	 * @param string $raw File contents.
	 * @return array{headers: string[], rows: array<int, array<int, string>>}|WP_Error
	 */
	private static function parse_delimited_text( $raw ) {
		$raw = (string) $raw;
		if ( strncmp( $raw, "\xEF\xBB\xBF", 3 ) === 0 ) {
			$raw = substr( $raw, 3 );
		}
		$lines = preg_split( '/\R/u', $raw, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $lines ) || $lines === array() ) {
			return new WP_Error( 'ktp_fm_empty', __( '有効な行がありません。', 'ktpwp' ) );
		}

		$first = $lines[0];
		$delim = self::detect_delimiter( $first );
		if ( $delim === null ) {
			return new WP_Error( 'ktp_fm_delim', __( '区切り文字を判定できませんでした（カンマまたはタブを含む1行目が必要です）。', 'ktpwp' ) );
		}

		$headers = str_getcsv( $first, $delim );
		if ( ! is_array( $headers ) || $headers === array() ) {
			return new WP_Error( 'ktp_fm_header', __( 'ヘッダー行を読み取れませんでした。', 'ktpwp' ) );
		}
		$headers = array_map(
			static function ( $h ) {
				return sanitize_text_field( trim( (string) $h ) );
			},
			$headers
		);

		$col_count = count( $headers );
		$rows      = array();
		for ( $i = 1, $len = count( $lines ); $i < $len; $i++ ) {
			$cells = str_getcsv( $lines[ $i ], $delim );
			if ( ! is_array( $cells ) ) {
				continue;
			}
			$norm = array();
			for ( $c = 0; $c < $col_count; $c++ ) {
				$norm[ $c ] = isset( $cells[ $c ] ) ? sanitize_text_field( (string) $cells[ $c ] ) : '';
			}
			if ( count( array_filter( $norm ) ) === 0 ) {
				continue;
			}
			$rows[] = $norm;
		}

		return array(
			'headers' => $headers,
			'rows'    => $rows,
		);
	}

	/**
	 * @param string $first_line First line.
	 * @return non-falsy-string|null
	 */
	private static function detect_delimiter( $first_line ) {
		$tab = substr_count( $first_line, "\t" );
		$com = substr_count( $first_line, ',' );
		if ( $tab === 0 && $com === 0 ) {
			return null;
		}
		return $tab >= $com ? "\t" : ',';
	}

	/**
	 * @return list<string>
	 */
	private static function allowed_entities(): array {
		return array( self::ENTITY_CLIENT, self::ENTITY_SUPPLIER, self::ENTITY_SERVICE );
	}

	/**
	 * @param string $entity Entity slug.
	 * @return string
	 */
	private static function entity_label( $entity ): string {
		$entity = self::normalize_entity( $entity );
		if ( $entity === self::ENTITY_SUPPLIER ) {
			return __( '協力会社', 'ktpwp' );
		}
		if ( $entity === self::ENTITY_SERVICE ) {
			return __( '商品（サービス）', 'ktpwp' );
		}
		return __( '顧客', 'ktpwp' );
	}

	/**
	 * @param string $entity Entity slug.
	 * @return string
	 */
	private static function normalize_entity( $entity ): string {
		$e = sanitize_key( (string) $entity );
		return in_array( $e, self::allowed_entities(), true ) ? $e : self::ENTITY_CLIENT;
	}

	/**
	 * @param string $tmp_path Temp path.
	 * @param string $ext      Extension (csv or zip).
	 * @param string $outer_name Sanitized outer filename (by ref: zip 時は「外側 / 内側」に書き換え).
	 * @return string|WP_Error
	 */
	private static function read_upload_delimited_raw( $tmp_path, $ext, &$outer_name ) {
		if ( $ext !== 'zip' ) {
			$raw = file_get_contents( $tmp_path );
			return is_string( $raw ) ? $raw : new WP_Error( 'ktp_fm_read', __( 'ファイルを読み取れませんでした。', 'ktpwp' ) );
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'ktp_fm_zip', __( 'このサーバーでは Zip を展開できません（ZipArchive がありません）。', 'ktpwp' ) );
		}

		$zip = new ZipArchive();
		if ( $zip->open( $tmp_path ) !== true ) {
			return new WP_Error( 'ktp_fm_zip_open', __( 'Zip ファイルを開けませんでした。', 'ktpwp' ) );
		}

		$candidates = array();
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$st = $zip->statIndex( $i );
			if ( ! is_array( $st ) || empty( $st['name'] ) ) {
				continue;
			}
			$fn = (string) $st['name'];
			if ( preg_match( '#(^|/)\.\.#', $fn ) ) {
				continue;
			}
			if ( substr( $fn, -1 ) === '/' || strpos( $fn, '__MACOSX/' ) === 0 ) {
				continue;
			}
			$leaf = basename( $fn );
			$ie   = strtolower( pathinfo( $leaf, PATHINFO_EXTENSION ) );
			if ( in_array( $ie, array( 'csv', 'tsv', 'tab', 'txt' ), true ) ) {
				$candidates[] = $fn;
			}
		}
		sort( $candidates, SORT_STRING );
		if ( $candidates === array() ) {
			$zip->close();
			return new WP_Error( 'ktp_fm_zip_empty', __( 'Zip 内に csv/tsv/tab/txt が見つかりませんでした。', 'ktpwp' ) );
		}

		$first = $candidates[0];
		$raw   = $zip->getFromName( $first );
		$zip->close();

		if ( ! is_string( $raw ) || $raw === '' ) {
			return new WP_Error( 'ktp_fm_zip_read', __( 'Zip 内のテーブルデータを読み取れませんでした。', 'ktpwp' ) );
		}

		$outer_name = $outer_name . ' / ' . $first;

		return $raw;
	}

	/**
	 * @return string
	 */
	private static function transient_key_for_user(): string {
		return self::TRANSIENT_PREFIX . (string) get_current_user_id();
	}

	/**
	 * @param string $plain Plaintext.
	 * @return string
	 */
	private static function encrypt_string( $plain ): string {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return '';
		}
		$key = hash( 'sha256', wp_salt( 'auth' ) . '|ktp_fm_import', true );
		$iv  = random_bytes( 16 );
		$enc = openssl_encrypt( $plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		if ( $enc === false ) {
			return '';
		}
		return base64_encode( $iv . $enc );
	}

	/**
	 * @param string $stored Stored ciphertext (base64).
	 * @return string
	 */
	private static function decrypt_api_key( $stored ): string {
		$stored = (string) $stored;
		if ( $stored === '' || ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}
		$bin = base64_decode( $stored, true );
		if ( ! is_string( $bin ) || strlen( $bin ) < 17 ) {
			return '';
		}
		$iv  = substr( $bin, 0, 16 );
		$enc = substr( $bin, 16 );
		$key = hash( 'sha256', wp_salt( 'auth' ) . '|ktp_fm_import', true );
		$dec = openssl_decrypt( $enc, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		return is_string( $dec ) ? $dec : '';
	}
}
