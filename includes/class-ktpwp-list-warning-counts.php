<?php
/**
 * 仕事リストの進捗件数・警告件数（Transient キャッシュ付き）
 *
 * @package KantanPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class KTPWP_List_Warning_Counts
 */
class KTPWP_List_Warning_Counts {

	const TRANSIENT_KEY = 'list_warning_counts';
	const TRANSIENT_TTL = 180;

	/**
	 * 進捗件数・警告件数をまとめて取得（キャッシュ利用）
	 *
	 * @return array{
	 *     progress_counts: array<int,int>,
	 *     progress_warnings: array<int,int>,
	 *     invoice_warning_count: int,
	 *     payment_warning_count: int,
	 *     warning_days: int
	 * }
	 */
	public static function get_bundle() {
		$cached = get_transient( 'ktpwp_' . self::TRANSIENT_KEY );
		if ( is_array( $cached ) && isset( $cached['progress_counts'] ) ) {
			return $cached;
		}

		$bundle = self::compute();
		set_transient( 'ktpwp_' . self::TRANSIENT_KEY, $bundle, self::TRANSIENT_TTL );

		return $bundle;
	}

	/**
	 * キャッシュを破棄
	 */
	public static function invalidate() {
		delete_transient( 'ktpwp_' . self::TRANSIENT_KEY );
	}

	/**
	 * @return array{
	 *     progress_counts: array<int,int>,
	 *     progress_warnings: array<int,int>,
	 *     invoice_warning_count: int,
	 *     payment_warning_count: int,
	 *     warning_days: int
	 * }
	 */
	private static function compute() {
		global $wpdb;

		$table_name   = $wpdb->prefix . 'ktp_order';
		$warning_days = 3;
		if ( class_exists( 'KTPWP_Settings' ) ) {
			$warning_days = (int) KTPWP_Settings::get_delivery_warning_days();
		}

		$progress_counts   = array_fill( 1, 7, 0 );
		$progress_warnings = array_fill( 1, 7, 0 );

		$count_rows = $wpdb->get_results(
			"SELECT progress, COUNT(*) AS cnt FROM `{$table_name}` GROUP BY progress",
			ARRAY_A
		);
		if ( is_array( $count_rows ) ) {
			foreach ( $count_rows as $row ) {
				$num = isset( $row['progress'] ) ? (int) $row['progress'] : 0;
				if ( $num >= 1 && $num <= 7 ) {
					$progress_counts[ $num ] = isset( $row['cnt'] ) ? (int) $row['cnt'] : 0;
				}
			}
		}

		$delivery_warning = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$table_name}` WHERE progress = %d AND expected_delivery_date IS NOT NULL AND expected_delivery_date <= DATE_ADD(CURDATE(), INTERVAL %d DAY)",
				3,
				$warning_days
			)
		);
		$progress_warnings[3] = (int) $delivery_warning;

		return array(
			'progress_counts'         => $progress_counts,
			'progress_warnings'       => $progress_warnings,
			'invoice_warning_count'   => self::count_invoice_warnings( $table_name ),
			'payment_warning_count'   => self::count_payment_warnings( $table_name ),
			'warning_days'            => $warning_days,
		);
	}

	/**
	 * @param string $table_name 受注テーブル名。
	 * @return int
	 */
	private static function count_invoice_warnings( $table_name ) {
		global $wpdb;

		$client_table = $wpdb->prefix . 'ktp_client';
		$query        = "SELECT o.id, o.client_id, o.completion_date, c.closing_day FROM {$table_name} o LEFT JOIN {$client_table} c ON o.client_id = c.id WHERE o.progress = 4 AND o.completion_date IS NOT NULL AND c.closing_day IS NOT NULL AND c.closing_day != 'なし'";
		$orders       = $wpdb->get_results( $query );
		if ( ! is_array( $orders ) || empty( $orders ) ) {
			return 0;
		}

		$today = new DateTime();
		$today->setTime( 0, 0, 0 );
		$count = 0;

		foreach ( $orders as $order ) {
			$completion_date = isset( $order->completion_date ) ? (string) $order->completion_date : '';
			if ( '' === $completion_date ) {
				continue;
			}

			$closing_dt = self::closing_date_for_completion( $completion_date, isset( $order->closing_day ) ? (string) $order->closing_day : '' );
			if ( ! $closing_dt ) {
				continue;
			}

			$diff      = $today->diff( $closing_dt );
			$days_left = $diff->invert ? -$diff->days : $diff->days;
			if ( $days_left <= 0 ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * @param string $table_name 受注テーブル名。
	 * @return int
	 */
	private static function count_payment_warnings( $table_name ) {
		global $wpdb;

		$client_table = $wpdb->prefix . 'ktp_client';
		$query        = "SELECT o.id, o.client_id, o.completion_date, o.payment_timing AS order_payment_timing, c.closing_day, c.payment_month, c.payment_day, c.payment_timing AS client_payment_timing FROM {$table_name} o LEFT JOIN {$client_table} c ON o.client_id = c.id WHERE o.progress = 5 AND o.completion_date IS NOT NULL AND c.payment_month IS NOT NULL AND c.payment_day IS NOT NULL";
		$orders       = $wpdb->get_results( $query );
		if ( ! is_array( $orders ) || empty( $orders ) ) {
			return 0;
		}

		$today = new DateTime();
		$today->setTime( 0, 0, 0 );
		$count = 0;

		foreach ( $orders as $order ) {
			if ( class_exists( 'KTPWP_Payment_Timing' ) ) {
				$order_obj  = (object) array(
					'payment_timing' => isset( $order->order_payment_timing ) ? $order->order_payment_timing : null,
					'client_id'      => isset( $order->client_id ) ? $order->client_id : 0,
				);
				$client_obj = (object) array(
					'payment_timing' => isset( $order->client_payment_timing ) ? $order->client_payment_timing : null,
				);
				if ( KTPWP_Payment_Timing::is_prepay( $order_obj, $client_obj ) ) {
					continue;
				}
			}

			$due_dt = self::payment_due_date_for_order( $order );
			if ( ! $due_dt ) {
				continue;
			}

			if ( $today > $due_dt ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * @param string $completion_date Y-m-d。
	 * @param string $closing_day     締め日。
	 * @return DateTime|null
	 */
	private static function closing_date_for_completion( $completion_date, $closing_day ) {
		$dt = DateTime::createFromFormat( 'Y-m-d', $completion_date );
		$errors = DateTime::getLastErrors();
		if ( false === $dt || ( $errors && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) ) {
			return null;
		}

		$year  = (int) $dt->format( 'Y' );
		$month = (int) $dt->format( 'm' );
		if ( $year < 1 || $year > 9999 || $month < 1 || $month > 12 ) {
			return null;
		}

		if ( '末日' === $closing_day ) {
			$closing_dt = new DateTime( "$year-$month-01" );
			$closing_dt->modify( 'last day of this month' );
		} else {
			$closing_day_num = (int) $closing_day;
			$closing_dt      = new DateTime( "$year-$month-" . str_pad( (string) $closing_day_num, 2, '0', STR_PAD_LEFT ) );
			$last_day        = (int) $closing_dt->format( 't' );
			if ( $closing_day_num > $last_day ) {
				$closing_dt->modify( 'last day of this month' );
			}
		}
		$closing_dt->setTime( 0, 0, 0 );

		return $closing_dt;
	}

	/**
	 * @param object $order 受注＋顧客 JOIN 行。
	 * @return DateTime|null
	 */
	private static function payment_due_date_for_order( $order ) {
		$completion_date = isset( $order->completion_date ) ? (string) $order->completion_date : '';
		if ( '' === $completion_date ) {
			return null;
		}

		$completion_dt = DateTime::createFromFormat( 'Y-m-d', $completion_date );
		$errors = DateTime::getLastErrors();
		if ( false === $completion_dt || ( $errors && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) ) {
			return null;
		}
		$completion_dt->setTime( 0, 0, 0 );

		$year  = (int) $completion_dt->format( 'Y' );
		$month = (int) $completion_dt->format( 'm' );
		if ( $year < 1 || $year > 9999 || $month < 1 || $month > 12 ) {
			return null;
		}

		$closing_day   = isset( $order->closing_day ) ? (string) $order->closing_day : '末日';
		$payment_month = isset( $order->payment_month ) ? (string) $order->payment_month : '翌月';
		$payment_day   = isset( $order->payment_day ) ? (string) $order->payment_day : '末日';

		$billing_year  = $year;
		$billing_month = $month;
		if ( '' !== $closing_day && 'なし' !== $closing_day ) {
			$closing_dt = self::closing_date_for_completion( $completion_date, $closing_day );
			if ( $closing_dt && $completion_dt > $closing_dt ) {
				$billing_month++;
				if ( $billing_month > 12 ) {
					$billing_month = 1;
					$billing_year++;
				}
			}
		}

		$payment_year  = $billing_year;
		$payment_m_num = $billing_month;
		switch ( $payment_month ) {
			case '今月':
				$payment_m_num = $billing_month;
				break;
			case '翌々月':
				$payment_m_num = $billing_month + 2;
				if ( $payment_m_num > 12 ) {
					$payment_m_num -= 12;
					$payment_year++;
				}
				break;
			case '翌月':
			default:
				$payment_m_num = $billing_month + 1;
				if ( $payment_m_num > 12 ) {
					$payment_m_num = 1;
					$payment_year++;
				}
				break;
		}

		if ( '即日' === $payment_day ) {
			return clone $completion_dt;
		}

		$due_dt = new DateTime();
		$due_dt->setDate( $payment_year, $payment_m_num, 1 );
		if ( '末日' === $payment_day ) {
			$due_dt->modify( 'last day of this month' );
		} else {
			$payment_day_num = (int) str_replace( '日', '', $payment_day );
			$due_dt->setDate( $payment_year, $payment_m_num, max( 1, $payment_day_num ) );
			$last_day = (int) $due_dt->format( 't' );
			if ( $payment_day_num > $last_day ) {
				$due_dt->modify( 'last day of this month' );
			}
		}
		$due_dt->setTime( 0, 0, 0 );

		return $due_dt;
	}
}
