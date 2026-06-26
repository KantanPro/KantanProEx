<?php
/**
 * Migration: Add inquiry_blocked column to client table
 *
 * 公開商品からの問い合わせをメールアドレス単位でブロックするフラグ。
 *
 * @package KTPWP
 * @subpackage Migrations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 顧客テーブルに inquiry_blocked カラムを追加する。
 */
class KTPWP_Migration_20260626_Add_Inquiry_Blocked_To_Client {

	/**
	 * @return bool
	 */
	public static function up() {
		global $wpdb;

		$client_table = $wpdb->prefix . 'ktp_client';

		$table_exists = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$client_table
			)
		) === $client_table;

		if ( ! $table_exists ) {
			return false;
		}

		$column_exists = $wpdb->get_var(
			$wpdb->prepare(
				"SHOW COLUMNS FROM `{$client_table}` LIKE %s",
				'inquiry_blocked'
			)
		);

		if ( $column_exists ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->query(
			"ALTER TABLE `{$client_table}` ADD COLUMN `inquiry_blocked` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '公開商品問い合わせブロック（1=ブロック中）' AFTER `client_status`"
		);

		return $result !== false;
	}
}
