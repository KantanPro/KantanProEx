<?php
/**
 * サービステーブルに在庫管理フラグ（stock_managed）を追加するマイグレーション
 *
 * 在庫を管理しない（0）を既定とし、管理する（1）をオプションにする。
 * 既存サービスの受付動作を変えないため、在庫数を 1 以外にしていたもの、
 * および公開中の定期契約サービスは「管理する」にしておく。
 *
 * @package KTPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$service_table = $wpdb->prefix . 'ktp_service';

if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $service_table ) ) !== $service_table ) {
	return;
}

$existing_columns = $wpdb->get_col( "SHOW COLUMNS FROM `{$service_table}`" );

if ( ! in_array( 'stock_managed', $existing_columns, true ) ) {
	$after  = in_array( 'stock', $existing_columns, true ) ? ' AFTER `stock`' : '';
	$sql    = "ALTER TABLE `{$service_table}` ADD COLUMN `stock_managed` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '在庫を管理するか（1=管理する・0=管理しない）'{$after}";
	$result = $wpdb->query( $sql );

	if ( $result !== false ) {
		if ( in_array( 'stock', $existing_columns, true ) ) {
			$wpdb->query( "UPDATE `{$service_table}` SET `stock_managed` = 1 WHERE `stock` <> 1" );
		}
		if ( in_array( 'is_public', $existing_columns, true ) && in_array( 'contract_billing_cycle', $existing_columns, true ) ) {
			$wpdb->query( "UPDATE `{$service_table}` SET `stock_managed` = 1 WHERE `is_public` = 1 AND `contract_billing_cycle` NOT IN ('none', '')" );
		}
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			ktpwp_debug_log( "KTPWP Migration: Added stock_managed column to {$service_table}" );
		}
	}
}

update_option( 'ktp_service_stock_managed_migration_completed', '2026-10-24' );
