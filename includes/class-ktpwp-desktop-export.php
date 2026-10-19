<?php
/**
 * デスクトップ版（KantanProEX（DT））への移行用ファイルを書き出す。
 *
 * 業務データ（ktp_ の表）・帳票やメールの設定・スタッフ・ファイル（帳票のロゴと印影・案件ファイル・サービス画像）を
 * ZIP 1 つにまとめる。デスクトップ版のメニュー「ファイル」→「WordPress 版から取り込む…」で読み込む。
 *
 * パスワード・API キー・Stripe の鍵は入れない（デスクトップ版で入れ直してもらう）。
 *
 * @package KantanPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KTPWP_Desktop_Export {

	const FORMAT  = 'kantanproex-wp-desktop-export';
	const VERSION = 1;

	/** 書き出す設定（業務に関わるものだけ。移行・ライセンス・画面の状態などは入れない） */
	const OPTIONS = array(
		'ktp_general_settings',
		'ktp_design_settings',
		'ktp_smtp_settings',
		'ktp_japanpost_api_settings',
		'ktp_pdf_document_settings',
		'ktp_pdf_issuer_name',
		'ktp_pdf_issuer_address',
		'ktp_pdf_layout',
		'ktp_pdf_logo_path',
		'ktp_pdf_seal_path',
		'ktp_atena_label_position',
		'ktpwp_qualified_invoice_enabled',
		'ktpwp_qualified_invoice_description',
	);

	/** 書き出さない表（利用規約はデスクトップ版のものを使う） */
	const SKIP_TABLES = array( 'ktp_terms_of_service' );

	/** uploads の中で一緒に運ぶフォルダ */
	const UPLOAD_DIRS = array( 'ktp-pdf-branding', 'ktp-order-files', 'ktpwp-service-images' );

	public static function bootstrap() {
		add_action( 'admin_post_ktpwp_export_desktop', array( __CLASS__, 'handle_download' ) );
	}

	/** バックアップ画面に出す「デスクトップ版へ移行」の欄 */
	public static function render_section() {
		if ( defined( 'KTP_DESKTOP' ) ) {
			return; // デスクトップ版の中では出さない
		}
		echo '<div class="ktp-settings-section">';
		echo '<h2>' . esc_html__( 'デスクトップ版へ移行', 'ktpwp' ) . '</h2>';
		echo '<p>' . esc_html__( 'KantanProEX（DT）（Mac / Windows のアプリ）へ移るためのファイル（ZIP）を書き出します。顧客・受注・請求・契約などのデータ、帳票やメールの設定、スタッフ、帳票のロゴと印影、案件ファイル、サービス画像が入ります。', 'ktpwp' ) . '</p>';
		echo '<p>' . esc_html__( 'デスクトップ版のメニュー「ファイル」→「WordPress 版から取り込む…」で、この ZIP を選んでください。', 'ktpwp' ) . '</p>';
		echo '<div class="notice notice-info inline"><p>' . esc_html__( 'メール（SMTP）のパスワード、日本郵便 API のシークレットキー、Stripe の鍵は書き出しません。デスクトップ版の設定画面で入れ直してください。ZIP には顧客の個人情報が入るので、取り込みが済んだら削除してください。', 'ktpwp' ) . '</p></div>';
		if ( ! class_exists( 'ZipArchive' ) ) {
			echo '<p style="color:#b91c1c;">' . esc_html__( 'このサーバーの PHP には ZIP 機能（ZipArchive）がないため、書き出せません。サーバーの管理者にお問い合わせください。', 'ktpwp' ) . '</p>';
			echo '</div>';
			return;
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="ktpwp_export_desktop" />';
		echo wp_nonce_field( 'ktpwp_export_desktop', 'ktpwp_export_desktop_nonce', true, false );
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( '移行用ファイルを書き出す', 'ktpwp' ) . '</button></p>';
		echo '</form>';
		echo '</div>';
	}

	public static function handle_download() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '権限がありません。', 'ktpwp' ) );
		}
		if ( ! isset( $_POST['ktpwp_export_desktop_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ktpwp_export_desktop_nonce'] ) ), 'ktpwp_export_desktop' ) ) {
			wp_die( esc_html__( 'セキュリティチェックに失敗しました。', 'ktpwp' ) );
		}
		if ( ! class_exists( 'ZipArchive' ) ) {
			wp_die( esc_html__( 'このサーバーの PHP には ZIP 機能（ZipArchive）がないため、書き出せません。', 'ktpwp' ) );
		}
		$path = wp_tempnam( 'ktp-desktop-export' );
		try {
			self::build( $path, get_current_user_id() );
		} catch ( Exception $e ) {
			if ( is_file( $path ) ) {
				unlink( $path );
			}
			wp_die( esc_html( $e->getMessage() ) );
		}
		$filename = 'kantanproex-desktop-migration-' . date_i18n( 'Ymd-His' ) . '.zip';
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_readfile
		unlink( $path );
		exit;
	}

	/**
	 * ZIP を作る。
	 *
	 * @param string $dest     書き出し先のパス
	 * @param int    $owner_id デスクトップ版のオーナー（管理者）にするユーザー
	 * @return array<string,int> 件数
	 */
	public static function build( $dest, $owner_id ) {
		global $wpdb;
		$upload = wp_upload_dir();
		$counts = array();

		$data = array(
			'format'   => self::FORMAT,
			'version'  => self::VERSION,
			'metadata' => array(
				'exported_at'    => current_time( 'mysql' ),
				'site_url'       => site_url(),
				'plugin_version' => defined( 'KANTANPRO_PLUGIN_VERSION' ) ? KANTANPRO_PLUGIN_VERSION : '',
				'db_prefix'      => $wpdb->prefix,
				'uploads_baseurl' => trailingslashit( $upload['baseurl'] ),
				'plugin_url'     => defined( 'KANTANPRO_PLUGIN_URL' ) ? trailingslashit( KANTANPRO_PLUGIN_URL ) : '',
			),
			'options'  => self::collect_options(),
			'tables'   => array(),
			'staff'    => self::collect_staff( (int) $owner_id ),
		);

		$like = $wpdb->esc_like( $wpdb->prefix . 'ktp_' ) . '%';
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ) as $table ) {
			$suffix = substr( $table, strlen( $wpdb->prefix ) );
			if ( in_array( $suffix, self::SKIP_TABLES, true ) ) {
				continue;
			}
			$rows = $wpdb->get_results( "SELECT * FROM `{$table}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$data['tables'][ $suffix ] = $rows ? $rows : array();
			$counts[ $suffix ]         = count( $data['tables'][ $suffix ] );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $dest, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			throw new RuntimeException( __( 'ZIP ファイルを作れませんでした。', 'ktpwp' ) );
		}
		$zip->addFromString( 'export.json', wp_json_encode( $data ) );

		// uploads のファイル（帳票のロゴと印影・案件ファイル・サービス画像）
		$files = 0;
		foreach ( self::UPLOAD_DIRS as $dir ) {
			$files += self::add_dir( $zip, trailingslashit( $upload['basedir'] ) . $dir, 'files/uploads/' . $dir );
		}
		// 古い版でプラグインの中（images/upload/）に置いたサービス画像
		if ( defined( 'KANTANPRO_PLUGIN_DIR' ) ) {
			$files += self::add_dir( $zip, trailingslashit( KANTANPRO_PLUGIN_DIR ) . 'images/upload', 'files/plugin-upload' );
		}
		$counts['files'] = $files;

		if ( ! $zip->close() ) {
			throw new RuntimeException( __( 'ZIP ファイルを書き出せませんでした。', 'ktpwp' ) );
		}
		return $counts;
	}

	private static function collect_options() {
		$out = array();
		foreach ( self::OPTIONS as $name ) {
			$value = get_option( $name, null );
			if ( null === $value ) {
				continue;
			}
			if ( 'ktp_smtp_settings' === $name && is_array( $value ) ) {
				unset( $value['smtp_pass'] );
			}
			if ( 'ktp_japanpost_api_settings' === $name && is_array( $value ) ) {
				unset( $value['secret_key'] );
			}
			if ( 'ktp_general_settings' === $name && is_array( $value ) ) {
				foreach ( array_keys( $value ) as $key ) {
					if ( 0 === strpos( (string) $key, 'stripe_' ) || 'ktp_page_id' === $key ) {
						unset( $value[ $key ] );
					}
				}
			}
			$out[ $name ] = $value;
		}
		return $out;
	}

	/** 管理者と、KantanPro の利用権限（ktpwp_access）を持つスタッフ */
	private static function collect_staff( $owner_id ) {
		$out = array();
		foreach ( get_users( array( 'orderby' => 'ID' ) ) as $user ) {
			$is_admin = user_can( $user, 'manage_options' );
			if ( ! $is_admin && ! user_can( $user, 'ktpwp_access' ) ) {
				continue;
			}
			$out[] = array(
				'id'           => (int) $user->ID,
				'login'        => $user->user_login,
				'email'        => $user->user_email,
				'display_name' => $user->display_name,
				'owner'        => (int) $user->ID === $owner_id,
			);
		}
		return $out;
	}

	private static function add_dir( ZipArchive $zip, $dir, $prefix ) {
		if ( ! is_dir( $dir ) ) {
			return 0;
		}
		$n  = 0;
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			$base = $file->getFilename();
			// 置き場所を守るためのファイル（.htaccess・index.php など）は運ばない
			if ( '.' === $base[0] || 'index.php' === $base || 'index.html' === $base ) {
				continue;
			}
			$rel = ltrim( str_replace( '\\', '/', substr( $file->getPathname(), strlen( $dir ) ) ), '/' );
			if ( $zip->addFile( $file->getPathname(), $prefix . '/' . $rel ) ) {
				$n++;
			}
		}
		return $n;
	}
}
