<?php
/**
 * 公開商品問い合わせのブロック管理。
 *
 * @package KTPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'KTPWP_Inquiry_Block' ) ) {

	/**
	 * メールアドレス単位で公開商品からの問い合わせを拒否する。
	 */
	class KTPWP_Inquiry_Block {

		const COLUMN = 'inquiry_blocked';

		/**
		 * @return string
		 */
		public static function get_client_table() {
			global $wpdb;

			return $wpdb->prefix . 'ktp_client';
		}

		/**
		 * @return bool
		 */
		public static function column_exists() {
			global $wpdb;

			$table = self::get_client_table();
			$column = self::COLUMN;

			return $wpdb->get_var(
				$wpdb->prepare(
					"SHOW COLUMNS FROM `{$table}` LIKE %s",
					$column
				)
			) !== null;
		}

		/**
		 * 顧客一覧・選択用 SQL に付与する除外条件（ブロック中を除く）。
		 *
		 * @return string
		 */
		public static function sql_list_exclude_clause() {
			if ( ! self::column_exists() ) {
				return '';
			}

			return ' AND (COALESCE(`' . self::COLUMN . '`, 0) = 0)';
		}

		/**
		 * ブロック中顧客に紐づく受注書を除外する SQL 断片（WHERE 内 AND 用）。
		 *
		 * @param string $client_id_expr client_id 列参照（例: o.client_id）。
		 * @return string
		 */
		public static function sql_exclude_blocked_client_orders( $client_id_expr = 'client_id' ) {
			if ( ! self::column_exists() ) {
				return '';
			}

			global $wpdb;
			$client_table = self::get_client_table();
			$column       = self::COLUMN;

			return " AND NOT EXISTS (
				SELECT 1 FROM `{$client_table}` c
				WHERE c.id = {$client_id_expr}
				AND COALESCE(c.`{$column}`, 0) = 1
			)";
		}

		/**
		 * 受注書がブロック中顧客の案件かどうか。
		 *
		 * @param int $order_id 受注書 ID。
		 * @return bool
		 */
		public static function is_order_from_blocked_client( $order_id ) {
			$order_id = (int) $order_id;
			if ( $order_id <= 0 || ! self::column_exists() ) {
				return false;
			}

			global $wpdb;
			$order_table  = $wpdb->prefix . 'ktp_order';
			$client_table = self::get_client_table();
			$column       = self::COLUMN;

			$blocked = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT 1 FROM `{$order_table}` o
					INNER JOIN `{$client_table}` c ON c.id = o.client_id
					WHERE o.id = %d AND COALESCE(c.`{$column}`, 0) = 1
					LIMIT 1",
					$order_id
				)
			);

			return $blocked !== null;
		}

		/**
		 * 一覧に表示する最新の顧客 ID を取得する。
		 *
		 * @return int
		 */
		public static function get_latest_visible_client_id() {
			if ( ! self::column_exists() ) {
				global $wpdb;
				$table = self::get_client_table();

				return (int) $wpdb->get_var( "SELECT id FROM `{$table}` ORDER BY id DESC LIMIT 1" );
			}

			global $wpdb;
			$table   = self::get_client_table();
			$exclude = self::sql_list_exclude_clause();

			return (int) $wpdb->get_var( "SELECT id FROM `{$table}` WHERE 1=1{$exclude} ORDER BY id DESC LIMIT 1" );
		}

		/**
		 * ブロック中のメールアドレス一覧（代表行）。
		 *
		 * @return array<int, object>
		 */
		public static function get_blocked_list() {
			if ( ! self::column_exists() ) {
				return array();
			}

			global $wpdb;
			$table  = self::get_client_table();
			$column = self::COLUMN;

			$sql = "SELECT c.id, c.company_name, c.name, c.email, c.time,
				(SELECT COUNT(*) FROM `{$table}` t WHERE t.email = c.email AND COALESCE(t.`{$column}`, 0) = 1) AS blocked_count
				FROM `{$table}` c
				INNER JOIN (
					SELECT email, MAX(id) AS max_id
					FROM `{$table}`
					WHERE COALESCE(`{$column}`, 0) = 1 AND email != ''
					GROUP BY email
				) latest ON c.id = latest.max_id
				ORDER BY c.id DESC";

			$rows = $wpdb->get_results( $sql );

			return is_array( $rows ) ? $rows : array();
		}

		/**
		 * @param int $client_id 顧客 ID。
		 * @return bool
		 */
		public static function unblock_for_client( $client_id ) {
			return self::set_blocked_for_client( $client_id, false );
		}

		/**
		 * @param string $email メールアドレス。
		 * @return bool
		 */
		public static function is_email_blocked( $email ) {
			if ( ! self::column_exists() ) {
				return false;
			}

			$email = sanitize_email( (string) $email );
			if ( $email === '' || ! is_email( $email ) ) {
				return false;
			}

			global $wpdb;
			$table = self::get_client_table();

			$blocked = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM `{$table}` WHERE email = %s AND `" . self::COLUMN . "` = 1 LIMIT 1",
					$email
				)
			);

			return $blocked !== null;
		}

		/**
		 * @param int $client_id 顧客 ID。
		 * @return bool
		 */
		public static function is_client_blocked( $client_id ) {
			$client_id = (int) $client_id;
			if ( $client_id <= 0 || ! self::column_exists() ) {
				return false;
			}

			global $wpdb;
			$table = self::get_client_table();

			$value = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT `" . self::COLUMN . "` FROM `{$table}` WHERE id = %d",
					$client_id
				)
			);

			return (int) $value === 1;
		}

		/**
		 * 同一メールアドレスの顧客行すべてにブロック状態を反映する。
		 *
		 * @param int  $client_id 基準となる顧客 ID。
		 * @param bool $blocked   true=ブロック, false=解除。
		 * @return bool
		 */
		public static function set_blocked_for_client( $client_id, $blocked ) {
			$client_id = (int) $client_id;
			if ( $client_id <= 0 || ! self::column_exists() ) {
				return false;
			}

			global $wpdb;
			$table = self::get_client_table();

			$email = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT email FROM `{$table}` WHERE id = %d",
					$client_id
				)
			);

			$email = sanitize_email( (string) $email );
			if ( $email === '' || ! is_email( $email ) ) {
				return false;
			}

			$flag = $blocked ? 1 : 0;

			$result = $wpdb->query(
				$wpdb->prepare(
					"UPDATE `{$table}` SET `" . self::COLUMN . "` = %d WHERE email = %s",
					$flag,
					$email
				)
			);

			if ( $result !== false && class_exists( 'KTPWP_List_Warning_Counts' ) ) {
				KTPWP_List_Warning_Counts::invalidate();
			}

			return $result !== false;
		}

		/**
		 * マイグレーション未適用時にカラム追加を試みる。
		 *
		 * @return bool
		 */
		public static function ensure_schema() {
			if ( self::column_exists() ) {
				return true;
			}

			$migration_file = dirname( __FILE__ ) . '/migrations/20260626_add_inquiry_blocked_to_client.php';
			if ( ! file_exists( $migration_file ) ) {
				return false;
			}

			require_once $migration_file;
			if ( ! class_exists( 'KTPWP_Migration_20260626_Add_Inquiry_Blocked_To_Client' ) ) {
				return false;
			}

			return KTPWP_Migration_20260626_Add_Inquiry_Blocked_To_Client::up() && self::column_exists();
		}

		/**
		 * @param int $client_id 顧客 ID。
		 * @return array{success:bool,blocked:bool,message:string}
		 */
		public static function toggle_for_client( $client_id ) {
			$client_id = (int) $client_id;
			if ( $client_id <= 0 ) {
				return array(
					'success' => false,
					'blocked' => false,
					'message' => __( '顧客 ID が無効です。', 'ktpwp' ),
				);
			}

			if ( ! self::column_exists() && ! self::ensure_schema() ) {
				return array(
					'success' => false,
					'blocked' => false,
					'message' => __( 'ブロック機能の DB 更新が未完了です。管理画面を一度開いてから再度お試しください。', 'ktpwp' ),
				);
			}

			$new_blocked = ! self::is_client_blocked( $client_id );
			$ok          = self::set_blocked_for_client( $client_id, $new_blocked );

			if ( ! $ok ) {
				global $wpdb;
				$table = self::get_client_table();
				$email = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT email FROM `{$table}` WHERE id = %d",
						$client_id
					)
				);
				$email = sanitize_email( (string) $email );

				return array(
					'success' => false,
					'blocked' => self::is_client_blocked( $client_id ),
					'message' => ( $email === '' || ! is_email( $email ) )
						? __( 'メールアドレスが未設定のため、ブロックできません。', 'ktpwp' )
						: __( 'ブロック設定の更新に失敗しました。', 'ktpwp' ),
				);
			}

			return array(
				'success' => true,
				'blocked' => $new_blocked,
				'message' => $new_blocked
					? __( '公開商品からの問い合わせをブロックしました。顧客タブの一覧から非表示になります。', 'ktpwp' )
					: __( '問い合わせブロックを解除しました。', 'ktpwp' ),
			);
		}
	}
}
