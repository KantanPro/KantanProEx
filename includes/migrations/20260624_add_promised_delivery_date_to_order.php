<?php
/**
 * 受注書テーブルに約束納期カラムを追加
 *
 * @package KTPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$table_name = $wpdb->prefix . 'ktp_order';

// 本番環境（top_ktp_order）を優先
$production_table = 'top_ktp_order';
$production_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$production_table}'" );
if ( $production_exists === $production_table ) {
	$table_name = $production_table;
}

$columns = $wpdb->get_col( "SHOW COLUMNS FROM `{$table_name}`", 0 );

if ( ! is_array( $columns ) ) {
	return;
}

if ( ! in_array( 'promised_delivery_date', $columns, true ) ) {
	$after = in_array( 'desired_delivery_date', $columns, true ) ? ' AFTER `desired_delivery_date`' : '';
	$result = $wpdb->query(
		"ALTER TABLE `{$table_name}` ADD COLUMN `promised_delivery_date` DATE NULL DEFAULT NULL COMMENT '約束納期'{$after}"
	);

	if ( $result === false && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		ktpwp_debug_log( 'KTPWP Migration: promised_delivery_date の追加に失敗: ' . $wpdb->last_error );
	} elseif ( class_exists( 'KTPWP_Schema_Cache' ) ) {
		KTPWP_Schema_Cache::invalidate( $table_name );
	}
}

update_option( 'ktp_order_promised_delivery_date_migration_completed', true );
