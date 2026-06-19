<?php
/**
 * DB スキーマ（テーブル・カラム）存在確認のキャッシュ
 *
 * @package KantanPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class KTPWP_Schema_Cache
 */
class KTPWP_Schema_Cache {

	/**
	 * リクエスト内キャッシュ: テーブル存在
	 *
	 * @var array<string, bool>
	 */
	private static $table_exists = array();

	/**
	 * リクエスト内キャッシュ: カラム一覧
	 *
	 * @var array<string, string[]>
	 */
	private static $columns = array();

	/**
	 * Transient TTL（秒）
	 */
	const TRANSIENT_TTL = 3600;

	/**
	 * @param string $table テーブル名（プレフィックス付き可）。
	 * @return bool
	 */
	public static function table_exists( $table ) {
		$table = self::sanitize_table( $table );
		if ( '' === $table ) {
			return false;
		}

		if ( isset( self::$table_exists[ $table ] ) ) {
			return self::$table_exists[ $table ];
		}

		$transient_key = 'schema_table_' . md5( $table );
		$cached        = get_transient( 'ktpwp_' . $transient_key );
		if ( false !== $cached ) {
			self::$table_exists[ $table ] = (bool) $cached;
			return self::$table_exists[ $table ];
		}

		global $wpdb;
		$exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );
		self::$table_exists[ $table ] = $exists;
		set_transient( 'ktpwp_' . $transient_key, $exists ? 1 : 0, self::TRANSIENT_TTL );

		return $exists;
	}

	/**
	 * @param string $table テーブル名。
	 * @return string[]
	 */
	public static function get_columns( $table ) {
		$table = self::sanitize_table( $table );
		if ( '' === $table || ! self::table_exists( $table ) ) {
			return array();
		}

		if ( isset( self::$columns[ $table ] ) ) {
			return self::$columns[ $table ];
		}

		$transient_key = 'schema_cols_' . md5( $table );
		$cached        = get_transient( 'ktpwp_' . $transient_key );
		if ( is_array( $cached ) ) {
			self::$columns[ $table ] = $cached;
			return self::$columns[ $table ];
		}

		global $wpdb;
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`", 0 );
		if ( ! is_array( $columns ) ) {
			$columns = array();
		}

		self::$columns[ $table ] = $columns;
		set_transient( 'ktpwp_' . $transient_key, $columns, self::TRANSIENT_TTL );

		return $columns;
	}

	/**
	 * @param string $table  テーブル名。
	 * @param string $column カラム名。
	 * @return bool
	 */
	public static function column_exists( $table, $column ) {
		$column = sanitize_key( (string) $column );
		if ( '' === $column ) {
			return false;
		}
		return in_array( $column, self::get_columns( $table ), true );
	}

	/**
	 * @param string|null $table 指定時はそのテーブルのみ、null で全スキーマキャッシュ。
	 */
	public static function invalidate( $table = null ) {
		if ( null === $table ) {
			self::$table_exists = array();
			self::$columns      = array();
			global $wpdb;
			$like = $wpdb->esc_like( '_transient_ktpwp_schema_' ) . '%';
			$rows = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
					$like
				)
			);
			foreach ( (array) $rows as $option_name ) {
				delete_transient( str_replace( '_transient_', '', $option_name ) );
			}
			return;
		}

		$table = self::sanitize_table( $table );
		unset( self::$table_exists[ $table ], self::$columns[ $table ] );
		delete_transient( 'ktpwp_schema_table_' . md5( $table ) );
		delete_transient( 'ktpwp_schema_cols_' . md5( $table ) );
	}

	/**
	 * @param string $table テーブル名。
	 * @return string
	 */
	private static function sanitize_table( $table ) {
		return preg_replace( '/[^a-zA-Z0-9_]/', '', (string) $table );
	}
}
