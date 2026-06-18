<?php
/**
 * KantanProEX エディション（スタッフ上限など）の定義と判定
 *
 * @package KantanPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * エディション別の制限を管理するクラス
 */
class KTPWP_Edition {

	/**
	 * エディション定義
	 *
	 * staff_limit: 0 = 無制限
	 *
	 * @return array<string, array{slug: string, plugin_name: string, staff_limit: int, label: string}>
	 */
	public static function get_definitions() {
		return array(
			'solo'     => array(
				'slug'         => 'solo',
				'plugin_name'  => 'KantanProEXsolo',
				'staff_limit'  => 1,
				'label'        => __( 'ソロ版', 'ktpwp' ),
			),
			'team'     => array(
				'slug'         => 'team',
				'plugin_name'  => 'KantanProEXteam',
				'staff_limit'  => 5,
				'label'        => __( 'チーム版', 'ktpwp' ),
			),
			'business' => array(
				'slug'         => 'business',
				'plugin_name'  => 'KantanProEXbusiness',
				'staff_limit'  => 15,
				'label'        => __( 'ビジネス版', 'ktpwp' ),
			),
			'pro'      => array(
				'slug'         => 'pro',
				'plugin_name'  => 'KantanProEX',
				'staff_limit'  => 0,
				'label'        => __( 'フルアクセス版', 'ktpwp' ),
			),
		);
	}

	/**
	 * 有効なエディション slug 一覧
	 *
	 * @return string[]
	 */
	public static function get_valid_slugs() {
		return array_keys( self::get_definitions() );
	}

	/**
	 * 開発者設定でのローカル上書きを含む、現在有効なエディション slug
	 *
	 * @return string
	 */
	public static function get_active_edition() {
		$edition = defined( 'KTPWP_EDITION' ) ? (string) KTPWP_EDITION : 'pro';

		if ( self::is_developer_override_enabled() ) {
			$override = get_option( 'ktp_developer_edition_override', '' );
			if ( is_string( $override ) && $override !== '' && isset( self::get_definitions()[ $override ] ) ) {
				$edition = $override;
			}
		}

		if ( ! isset( self::get_definitions()[ $edition ] ) ) {
			return 'pro';
		}

		return $edition;
	}

	/**
	 * 開発環境でエディション上書きが有効か
	 *
	 * @return bool
	 */
	public static function is_developer_override_enabled() {
		if ( defined( 'KTPWP_DEVELOPMENT_MODE' ) && KTPWP_DEVELOPMENT_MODE ) {
			return true;
		}
		if ( defined( 'KANTANPRO_DEV_MODE' ) && KANTANPRO_DEV_MODE ) {
			return true;
		}

		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		if ( $host === '' ) {
			return false;
		}

		return in_array( $host, array( 'localhost', '127.0.0.1' ), true )
			|| strpos( $host, '.local' ) !== false
			|| strpos( $host, '.test' ) !== false
			|| strpos( $host, '.dev' ) !== false
			|| strpos( $host, 'localhost:' ) !== false
			|| strpos( $host, '127.0.0.1:' ) !== false
			|| ( defined( 'WP_ENV' ) && WP_ENV === 'development' );
	}

	/**
	 * エディション表示名
	 *
	 * @param string|null $edition 省略時は現在有効なエディション
	 * @return string
	 */
	public static function get_edition_label( $edition = null ) {
		$edition = $edition ?? self::get_active_edition();
		$definitions = self::get_definitions();

		return isset( $definitions[ $edition ] ) ? $definitions[ $edition ]['label'] : $edition;
	}

	/**
	 * プラグイン表示名（ビルド時に KANTANPRO_PLUGIN_NAME が設定されていればそれを優先）
	 *
	 * @return string
	 */
	public static function get_plugin_name() {
		if ( defined( 'KANTANPRO_PLUGIN_NAME' ) && KANTANPRO_PLUGIN_NAME !== '' ) {
			return (string) KANTANPRO_PLUGIN_NAME;
		}

		$edition = self::get_active_edition();
		$definitions = self::get_definitions();

		return isset( $definitions[ $edition ] ) ? $definitions[ $edition ]['plugin_name'] : 'KantanProEX';
	}

	/**
	 * GitHub 更新チェック用リポジトリ（owner/repo）
	 *
	 * @return string
	 */
	public static function get_github_repo() {
		return 'KantanPro/KantanProEx';
	}

	/**
	 * エディション slug からプラグインフォルダ名（ビルド ZIP のルート名）を取得
	 *
	 * @param string|null $edition 省略時は現在有効なエディション
	 * @return string
	 */
	public static function get_plugin_dir_name( $edition = null ) {
		$edition     = $edition ?? self::get_active_edition();
		$definitions = self::get_definitions();

		return isset( $definitions[ $edition ] ) ? $definitions[ $edition ]['plugin_name'] : 'KantanProEX';
	}

	/**
	 * インストール先フォルダ名からエディション slug を推定
	 *
	 * @param string $dir_name wp-content/plugins 直下のフォルダ名
	 * @return string
	 */
	public static function detect_edition_from_plugin_dir( $dir_name ) {
		if ( ! is_string( $dir_name ) || $dir_name === '' ) {
			return 'pro';
		}

		foreach ( self::get_definitions() as $slug => $definition ) {
			if ( strcasecmp( (string) $definition['plugin_name'], $dir_name ) === 0 ) {
				return $slug;
			}
		}

		return 'pro';
	}

	/**
	 * GitHub Release の ZIP asset 照合に使うエディション（インストール先フォルダを優先）
	 *
	 * @return string
	 */
	public static function get_update_edition() {
		if ( defined( 'KANTANPRO_PLUGIN_FILE' ) ) {
			return self::detect_edition_from_plugin_dir( basename( dirname( KANTANPRO_PLUGIN_FILE ) ) );
		}

		return self::get_active_edition();
	}

	/**
	 * GitHub Release asset 用の ZIP ファイル名パターン（正規表現）
	 *
	 * 例: KantanProEXbusiness_1.3.68_20260617.zip / KantanProEX.zip
	 *
	 * @param string|null $edition 省略時は get_update_edition()
	 * @return string
	 */
	public static function get_release_asset_filename_pattern( $edition = null ) {
		$dir_name = self::get_plugin_dir_name( $edition ?? self::get_update_edition() );

		return '/^' . preg_quote( $dir_name, '/' ) . '(?:_\d+\.\d+\.\d+.*)?\.zip$/i';
	}

	/**
	 * GitHub Release の assets から、現行エディション用 ZIP の download URL を取得
	 *
	 * @param array<int, array<string, mixed>> $assets GitHub API assets 配列
	 * @param string|null                       $edition 省略時は get_update_edition()
	 * @return string 見つからなければ空文字
	 */
	public static function find_release_asset_url( array $assets, $edition = null ) {
		$edition    = $edition ?? self::get_update_edition();
		$dir_name   = self::get_plugin_dir_name( $edition );
		$pattern    = self::get_release_asset_filename_pattern( $edition );
		$exact_name = $dir_name . '.zip';
		$candidates = array();

		foreach ( $assets as $asset ) {
			if ( ! is_array( $asset ) || empty( $asset['name'] ) ) {
				continue;
			}

			$name = (string) $asset['name'];
			if ( $name !== $exact_name && ! preg_match( $pattern, $name ) ) {
				continue;
			}

			// WordPress の download_url では API asset URL より browser_download_url の方が安定する
			$url = '';
			if ( ! empty( $asset['browser_download_url'] ) ) {
				$url = (string) $asset['browser_download_url'];
			} elseif ( ! empty( $asset['url'] ) ) {
				$url = (string) $asset['url'];
			}

			if ( $url !== '' ) {
				$candidates[ $name ] = $url;
			}
		}

		if ( empty( $candidates ) ) {
			return '';
		}

		if ( isset( $candidates[ $exact_name ] ) ) {
			return $candidates[ $exact_name ];
		}

		$names = array_keys( $candidates );
		rsort( $names, SORT_STRING );

		return $candidates[ $names[0] ];
	}

	/**
	 * プラグインディレクトリの正規名（URL 正規化など）
	 *
	 * @return string
	 */
	public static function get_canonical_plugin_dir() {
		if ( defined( 'KANTANPRO_PLUGIN_FILE' ) ) {
			return basename( dirname( KANTANPRO_PLUGIN_FILE ) );
		}

		return self::get_plugin_dir_name();
	}

	/**
	 * スタッフ上限（0 = 無制限）
	 *
	 * @return int
	 */
	public static function get_staff_limit() {
		if ( defined( 'KTPWP_STAFF_LIMIT' ) ) {
			return max( 0, (int) KTPWP_STAFF_LIMIT );
		}

		$edition = self::get_active_edition();
		$definitions = self::get_definitions();

		if ( ! isset( $definitions[ $edition ] ) ) {
			return 0;
		}

		return max( 0, (int) $definitions[ $edition ]['staff_limit'] );
	}

	/**
	 * KantanPro 有料 EX 系エディションか（ライセンス免除・バナー非表示など）
	 *
	 * @return bool
	 */
	public static function is_ex_edition() {
		return isset( self::get_definitions()[ self::get_active_edition() ] );
	}

	/**
	 * 有料エディションか。
	 *
	 * @return bool
	 */
	public static function is_paid_edition() {
		return self::is_ex_edition();
	}

	/**
	 * 販売ページ URL
	 *
	 * @return string
	 */
	public static function get_store_url() {
		return 'https://www.kantanpro.com/product/kantanpro-ex';
	}

	/**
	 * エディション別機能フラグ（KantanProEX は有料版のみのため常に有効）
	 *
	 * @param string $feature 機能キー。
	 * @return bool
	 */
	public static function is_feature_enabled( $feature ) {
		return true;
	}

	/**
	 * バナー（ktp-banner）を隠すか。
	 *
	 * @return bool
	 */
	public static function should_hide_banner() {
		return self::is_paid_edition();
	}

	/**
	 * 機能無効時のメッセージ（販売リンク付き）
	 *
	 * @param string $feature_name 機能名。
	 * @return string
	 */
	public static function get_upgrade_message_html( $feature_name ) {
		$feature_name = sanitize_text_field( (string) $feature_name );
		$store_url    = esc_url( self::get_store_url() );
		$message      = sprintf(
			/* translators: %s: feature name */
			__( 'フリー版では「%s」は利用できません。', 'ktpwp' ),
			$feature_name
		);
		$link_label = __( 'KantanProEX（WP）販売所', 'ktpwp' );

		return '<div class="notice notice-warning inline"><p>'
			. esc_html( $message )
			. ' <a href="' . $store_url . '" target="_blank" rel="noopener noreferrer">' . esc_html( $link_label ) . '</a>'
			. '</p></div>';
	}

	/**
	 * ktpwp_access 権限を持つスタッフ数（管理者は含めない）
	 *
	 * @return int
	 */
	public static function count_staff_users() {
		$users = get_users(
			array(
				'role__not_in' => array( 'administrator' ),
				'fields'       => 'ID',
			)
		);

		$count = 0;
		foreach ( $users as $user_id ) {
			if ( user_can( (int) $user_id, 'ktpwp_access' ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * スタッフを追加できるか
	 *
	 * @return bool
	 */
	public static function can_add_staff() {
		$limit = self::get_staff_limit();
		if ( $limit <= 0 ) {
			return true;
		}

		return self::count_staff_users() < $limit;
	}

	/**
	 * スタッフ上限の表示用文字列
	 *
	 * @return string
	 */
	public static function format_staff_limit_display() {
		$limit = self::get_staff_limit();

		return $limit > 0 ? (string) $limit : __( '無制限', 'ktpwp' );
	}

	/**
	 * スタッフ上限到達時のメッセージ
	 *
	 * @return string
	 */
	public static function get_staff_limit_reached_message() {
		$limit = self::get_staff_limit();
		$edition_label = self::get_edition_label();

		return sprintf(
			/* translators: 1: edition label, 2: staff limit number */
			__( '%1$sではスタッフは最大%2$d人まで登録できます。上限に達しているため、これ以上スタッフを追加できません。', 'ktpwp' ),
			$edition_label,
			$limit
		);
	}
}

if ( ! function_exists( 'ktpwp_is_ex_edition' ) ) {
	/**
	 * KantanPro 有料 EX 系エディションか
	 *
	 * @return bool
	 */
	function ktpwp_is_ex_edition() {
		return class_exists( 'KTPWP_Edition' ) && KTPWP_Edition::is_ex_edition();
	}
}

if ( ! function_exists( 'ktpwp_should_hide_ktp_banner' ) ) {
	/**
	 * KTP バナーを隠すか。
	 *
	 * @return bool
	 */
	function ktpwp_should_hide_ktp_banner() {
		return class_exists( 'KTPWP_Edition' ) && KTPWP_Edition::should_hide_banner();
	}
}

if ( ! function_exists( 'ktpwp_is_feature_enabled' ) ) {
	/**
	 * エディションごとの機能フラグ
	 *
	 * @param string $feature 機能キー。
	 * @return bool
	 */
	function ktpwp_is_feature_enabled( $feature ) {
		if ( ! class_exists( 'KTPWP_Edition' ) ) {
			return true;
		}

		return KTPWP_Edition::is_feature_enabled( $feature );
	}
}
