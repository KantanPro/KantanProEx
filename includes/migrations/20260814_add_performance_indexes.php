<?php
/**
 * Migration: 一覧表示用のパフォーマンスインデックスを追加
 *
 * wp_ktp_order は PRIMARY KEY(id) と KEY client_id しか持っていなかったため、
 * 仕事リストの主クエリ（WHERE progress = N ... ORDER BY time DESC）が
 * 毎回フルスキャン＋filesort になっていた。
 * 受注書が数千件規模になると一覧表示が体感で遅くなるため、複合インデックスを追加する。
 *
 * 出力される HTML は一切変わらない（実行計画のみが変わる）。
 *
 * @package KTPWP
 * @subpackage Migrations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 一覧クエリ向けインデックスを追加する。
 */
class KTPWP_Migration_20260814_Add_Performance_Indexes {

	/**
	 * 追加するインデックス定義。
	 *
	 * table  … $wpdb->prefix を除いたテーブル名
	 * name   … インデックス名
	 * columns… 対象カラム（すべて存在する場合のみ作成）
	 *
	 * @return array
	 */
	private static function index_definitions() {
		return array(
			// 仕事リスト: WHERE progress = %d ... ORDER BY time DESC
			array(
				'table'   => 'ktp_order',
				'name'    => 'ktp_progress_time',
				'columns' => array( 'progress', 'time' ),
			),
			// 請求候補取得: WHERE client_id = %d AND progress = 4 ORDER BY completion_date ASC
			array(
				'table'   => 'ktp_order',
				'name'    => 'ktp_client_progress_completion',
				'columns' => array( 'client_id', 'progress', 'completion_date' ),
			),
			// 納期順ソート・納期警告件数の集計
			array(
				'table'   => 'ktp_order',
				'name'    => 'ktp_progress_promised',
				'columns' => array( 'progress', 'promised_delivery_date' ),
			),
			// 顧客詳細内の受注履歴: WHERE client_id IN (...) ORDER BY time
			array(
				'table'   => 'ktp_order',
				'name'    => 'ktp_client_time',
				'columns' => array( 'client_id', 'time' ),
			),
		);
		// 注: 顧客／サービス／協力会社の一覧は既定ソートが id DESC（主キー利用）のため、
		//     追加インデックスは不要。書き込みコストだけが増えるので作らない。
	}

	/**
	 * マイグレーション実行。
	 *
	 * @return bool 常に true（インデックス追加は失敗しても業務継続可能なため）
	 */
	public static function up() {
		global $wpdb;

		foreach ( self::index_definitions() as $definition ) {
			$table = $wpdb->prefix . $definition['table'];

			if ( ! self::table_exists( $table ) ) {
				continue;
			}

			if ( self::index_exists( $table, $definition['name'] ) ) {
				continue;
			}

			// 対象カラムがすべて存在する場合のみ作成する
			$missing = false;
			foreach ( $definition['columns'] as $column ) {
				if ( ! self::column_exists( $table, $column ) ) {
					$missing = true;
					break;
				}
			}
			if ( $missing ) {
				continue;
			}

			$columns_sql = '`' . implode( '`, `', $definition['columns'] ) . '`';

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$result = $wpdb->query(
				"ALTER TABLE `{$table}` ADD INDEX `{$definition['name']}` ({$columns_sql})"
			);

			if ( $result === false && function_exists( 'ktpwp_debug_log' ) ) {
				ktpwp_debug_log(
					'KTPWP Migration: インデックス作成に失敗しました: ' . $table . '.' . $definition['name'] . ' / ' . $wpdb->last_error
				);
			}
		}

		return true;
	}

	/**
	 * テーブルの存在確認。
	 *
	 * @param string $table テーブル名（プレフィックス込み）。
	 * @return bool
	 */
	private static function table_exists( $table ) {
		global $wpdb;

		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/**
	 * カラムの存在確認。
	 *
	 * @param string $table  テーブル名（プレフィックス込み）。
	 * @param string $column カラム名。
	 * @return bool
	 */
	private static function column_exists( $table, $column ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", $column ) );
	}

	/**
	 * インデックスの存在確認。
	 *
	 * @param string $table テーブル名（プレフィックス込み）。
	 * @param string $name  インデックス名。
	 * @return bool
	 */
	private static function index_exists( $table, $name ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM `{$table}` WHERE Key_name = %s", $name ) );
	}
}
