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
			'free'     => array(
				'slug'         => 'free',
				'plugin_name'  => 'KantanProEXfree',
				'staff_limit'  => 1,
				'label'        => __( 'フリー版', 'ktpwp' ),
			),
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
	 * フリー版か。
	 *
	 * @return bool
	 */
	public static function is_free_edition() {
		return self::get_active_edition() === 'free';
	}

	/**
	 * 有料エディションか（フリー版を除く）。
	 *
	 * @return bool
	 */
	public static function is_paid_edition() {
		return self::is_ex_edition() && ! self::is_free_edition();
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
	 * エディション別機能フラグ
	 *
	 * @param string $feature 機能キー。
	 * @return bool
	 */
	public static function is_feature_enabled( $feature ) {
		$feature = (string) $feature;

		if ( ! self::is_free_edition() ) {
			return true;
		}

		$disabled_features = array(
			'stripe_billing',
			'contract_invoice_auto_mail',
			'public_products',
		);

		return ! in_array( $feature, $disabled_features, true );
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
