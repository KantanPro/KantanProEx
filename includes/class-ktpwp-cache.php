<?php
/**
 * 軽量キャッシュ（WordPress Transient ベース）
 *
 * @package KantanPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class KTPWP_Cache
 */
class KTPWP_Cache {

	/**
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * @var string
	 */
	private $prefix = 'ktpwp_';

	/**
	 * @return self
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * @param string      $key   キャッシュキー。
	 * @param string|null $group グループ（任意）。
	 * @return mixed
	 */
	public function get( $key, $group = null ) {
		return get_transient( $this->build_key( $key, $group ) );
	}

	/**
	 * @param string      $key         キャッシュキー。
	 * @param mixed       $data        保存データ。
	 * @param int|null    $expiration  有効期限（秒）。
	 * @param string|null $group       グループ（任意）。
	 * @return bool
	 */
	public function set( $key, $data, $expiration = null, $group = null ) {
		$ttl = null !== $expiration ? (int) $expiration : HOUR_IN_SECONDS;
		return set_transient( $this->build_key( $key, $group ), $data, $ttl );
	}

	/**
	 * @param string      $key   キャッシュキー。
	 * @param string|null $group グループ（任意）。
	 * @return bool
	 */
	public function delete( $key, $group = null ) {
		return delete_transient( $this->build_key( $key, $group ) );
	}

	/**
	 * @param string   $key         キャッシュキー。
	 * @param callable $callback    データ取得コールバック。
	 * @param int|null $expiration  有効期限（秒）。
	 * @param string|null $group    グループ（任意）。
	 * @return mixed
	 */
	public function remember( $key, $callback, $expiration = null, $group = null ) {
		$cached = $this->get( $key, $group );
		if ( false !== $cached ) {
			return $cached;
		}
		$value = is_callable( $callback ) ? call_user_func( $callback ) : false;
		if ( false !== $value ) {
			$this->set( $key, $value, $expiration, $group );
		}
		return $value;
	}

	/**
	 * @param string $key Transient キー（プレフィックスなし）。
	 * @return mixed
	 */
	public function get_transient( $key ) {
		return get_transient( $this->prefix . $key );
	}

	/**
	 * @param string   $key        Transient キー（プレフィックスなし）。
	 * @param mixed    $data       保存データ。
	 * @param int|null $expiration 有効期限（秒）。
	 * @return bool
	 */
	public function set_transient( $key, $data, $expiration = null ) {
		$ttl = null !== $expiration ? (int) $expiration : HOUR_IN_SECONDS;
		return set_transient( $this->prefix . $key, $data, $ttl );
	}

	/**
	 * @param string $key Transient キー（プレフィックスなし）。
	 * @return bool
	 */
	public function delete_transient( $key ) {
		return delete_transient( $this->prefix . $key );
	}

	/**
	 * KantanPro 関連 Transient を一括削除
	 */
	public function clear_all_cache() {
		global $wpdb;

		$like = $wpdb->esc_like( '_transient_' . $this->prefix ) . '%';
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$like
			)
		);

		foreach ( (array) $rows as $option_name ) {
			$key = str_replace( '_transient_', '', $option_name );
			delete_transient( $key );
		}
	}

	/**
	 * @param string $pattern キーパターン（* ワイルドカード可）。
	 */
	public function clear_cache_by_pattern( $pattern ) {
		global $wpdb;

		$pattern = str_replace( '*', '%', $pattern );
		$like    = $wpdb->esc_like( '_transient_' . $this->prefix ) . str_replace( $this->prefix, '', $pattern );
		if ( strpos( $like, '%' ) === false ) {
			$like .= '%';
		}

		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$like
			)
		);

		foreach ( (array) $rows as $option_name ) {
			$key = str_replace( '_transient_', '', $option_name );
			delete_transient( $key );
		}
	}

	/**
	 * @param string   $key        キャッシュキー。
	 * @param callable $callback   データ取得コールバック。
	 * @param int|null $expiration 有効期限（秒）。
	 * @return mixed
	 */
	public function distribution_cache( $key, $callback, $expiration = null ) {
		return $this->remember( $key, $callback, $expiration, 'distribution' );
	}

	/**
	 * 互換用（現状は no-op）
	 */
	public function auto_enable_cache() {
		// Transient ベースのため追加設定不要。
	}

	/**
	 * 互換用（現状は no-op）
	 */
	public function monitor_performance() {
		// 将来の拡張用。
	}

	/**
	 * @param string      $key   キャッシュキー。
	 * @param string|null $group グループ。
	 * @return string
	 */
	private function build_key( $key, $group = null ) {
		$key = sanitize_key( (string) $key );
		if ( ! empty( $group ) ) {
			return $this->prefix . sanitize_key( (string) $group ) . '_' . $key;
		}
		return $this->prefix . $key;
	}
}
