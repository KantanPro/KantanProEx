<?php
/**
 * 仕事リスト・受注タブの工程表（受注日→約束納期）
 *
 * @package KTPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'KTPWP_Work_List_Schedule' ) ) {

	/**
	 * 工程表データ構築と HTML 出力
	 */
	class KTPWP_Work_List_Schedule {

		private const RANGE_PADDING_DAYS = 2;

		/**
		 * 受注タブの全案件を取得（ページネーション・検索は無視）
		 *
		 * @param wpdb   $wpdb            DB。
		 * @param string $list_type_where 定期のみフィルタ SQL 断片。
		 * @return array<int, object>
		 */
		public static function fetch_orders( $wpdb, $list_type_where = '' ) {
			$table_name = $wpdb->prefix . 'ktp_order';
			$order_block_exclude_sql = class_exists( 'KTPWP_Inquiry_Block' )
				? KTPWP_Inquiry_Block::sql_exclude_blocked_client_orders( "{$table_name}.client_id" )
				: '';

			$query = $wpdb->prepare(
				"SELECT *
				FROM {$table_name}
				WHERE progress = %d{$list_type_where}{$order_block_exclude_sql}
				ORDER BY
					CASE
						WHEN COALESCE(NULLIF(promised_delivery_date, '0000-00-00'), NULLIF(desired_delivery_date, '0000-00-00')) IS NULL THEN 999999
						WHEN COALESCE(NULLIF(promised_delivery_date, '0000-00-00'), NULLIF(desired_delivery_date, '0000-00-00')) <= CURDATE() THEN 0
						ELSE DATEDIFF(COALESCE(NULLIF(promised_delivery_date, '0000-00-00'), NULLIF(desired_delivery_date, '0000-00-00')), CURDATE())
					END ASC,
					time DESC",
				3
			);

			$orders = $wpdb->get_results( $query );

			return is_array( $orders ) ? $orders : array();
		}

		/**
		 * @param array<int, object> $orders 受注書行。
		 * @return array<string, mixed>
		 */
		public static function build( array $orders ) {
			$today = new DateTimeImmutable( 'today', wp_timezone() );
			$rows  = array();
			$skipped_without_ordered_at = 0;
			$without_due_date_count     = 0;

			foreach ( $orders as $order ) {
				$start = self::get_ordered_date( $order );
				if ( $start === null ) {
					$skipped_without_ordered_at++;
					continue;
				}

				$due_info = self::get_schedule_due_date( $order );
				$due_end  = $due_info !== null ? $due_info['date'] : null;
				$has_bar  = $due_end !== null;
				if ( ! $has_bar ) {
					$without_due_date_count++;
				}

				$end = $due_end ?? $start;
				if ( $has_bar && $end < $start ) {
					$end = $start;
				}

				$title = trim( (string) ( $order->project_name ?? '' ) );
				$label = $title !== ''
					? $title
					: sprintf(
						/* translators: %d: order ID */
						__( '受注 #%d', 'ktpwp' ),
						(int) $order->id
					);

				$client_label = trim( (string) ( $order->customer_name ?? '' ) . ' ' . (string) ( $order->user_name ?? '' ) );
				$detail_url   = add_query_arg(
					array(
						'tab_name' => 'order',
						'order_id' => (int) $order->id,
					)
				);

				$rows[] = array(
					'order_id'    => (int) $order->id,
					'label'       => $label,
					'sublabel'    => $client_label,
					'href'        => $detail_url,
					'start'       => $start,
					'end'         => $end,
					'left_pct'    => 0.0,
					'width_pct'   => 0.0,
					'has_bar'     => $has_bar,
					'due_source'  => $due_info !== null ? $due_info['source'] : '',
					'start_label' => $start->format( 'Y/n/j' ),
					'end_label'   => $has_bar ? $end->format( 'Y/n/j' ) : __( '約束納期・希望納期未入力', 'ktpwp' ),
					'due_label'   => $has_bar ? $end->format( 'Y/n/j' ) : '',
				);
			}

			if ( $rows === array() ) {
				$range_start = $today->sub( new DateInterval( 'P' . self::RANGE_PADDING_DAYS . 'D' ) );
				$range_end   = $today->add( new DateInterval( 'P' . self::RANGE_PADDING_DAYS . 'D' ) );
				$total_days  = max( 1, (int) $range_start->diff( $range_end )->days );

				return array(
					'range_start'                  => $range_start,
					'range_end'                    => $range_end,
					'total_days'                   => $total_days,
					'ticks'                        => self::build_ticks( $range_start, $range_end, $total_days ),
					'rows'                         => array(),
					'empty'                        => true,
					'displayed_count'              => 0,
					'total_orders'                 => count( $orders ),
					'skipped_without_ordered_at'   => $skipped_without_ordered_at,
					'without_due_date_count'       => $without_due_date_count,
				);
			}

			$range_start = $rows[0]['start'];
			$range_end   = $rows[0]['end'];
			foreach ( $rows as $row ) {
				if ( $row['start'] < $range_start ) {
					$range_start = $row['start'];
				}
				if ( $row['end'] > $range_end ) {
					$range_end = $row['end'];
				}
			}

			$range_start = $range_start->sub( new DateInterval( 'P' . self::RANGE_PADDING_DAYS . 'D' ) );
			$range_end   = $range_end->add( new DateInterval( 'P' . self::RANGE_PADDING_DAYS . 'D' ) );
			if ( $range_end < $today ) {
				$range_end = $today->add( new DateInterval( 'P' . self::RANGE_PADDING_DAYS . 'D' ) );
			}

			$total_days = max( 1, (int) $range_start->diff( $range_end )->days );

			foreach ( $rows as $index => $row ) {
				if ( ! $row['has_bar'] ) {
					$rows[ $index ]['left_pct']  = 0.0;
					$rows[ $index ]['width_pct'] = 0.0;
					continue;
				}

				$offset_days    = (int) $range_start->diff( $row['start'] )->days;
				$duration_days  = max( 1, (int) $row['start']->diff( $row['end'] )->days );
				$left_pct       = self::clamp_pct( ( $offset_days / $total_days ) * 100 );
				$width_pct      = self::clamp_pct( max( 0.8, ( $duration_days / $total_days ) * 100 ), 100 - $left_pct );
				$rows[ $index ]['left_pct']  = $left_pct;
				$rows[ $index ]['width_pct'] = $width_pct;
			}

			return array(
				'range_start'                  => $range_start,
				'range_end'                    => $range_end,
				'total_days'                   => $total_days,
				'ticks'                        => self::build_ticks( $range_start, $range_end, $total_days ),
				'rows'                         => $rows,
				'empty'                        => false,
				'displayed_count'              => count( $rows ),
				'total_orders'                 => count( $orders ),
				'skipped_without_ordered_at'   => $skipped_without_ordered_at,
				'without_due_date_count'       => $without_due_date_count,
			);
		}

		/**
		 * schedule=1 用 HTML（モーダル／印刷で fetch）
		 *
		 * @param array<string, mixed> $schedule build() の戻り値。
		 * @return string
		 */
		public static function render_schedule_page( array $schedule ) {
			return '<div id="work-list-schedule-area">' . self::render_chart( $schedule ) . '</div>';
		}

		/**
		 * モーダル用チャート HTML
		 *
		 * @param array<string, mixed> $schedule build() の戻り値。
		 * @return string
		 */
		public static function render_chart( array $schedule ) {
			$html = '<div id="work-list-schedule-chart" class="work-list-schedule-chart">';

			if ( ! $schedule['empty'] ) {
				$html .= '<p class="work-list-schedule-count">';
				$html .= esc_html(
					sprintf(
						/* translators: %d: number of orders */
						__( '表示 %d 件', 'ktpwp' ),
						(int) $schedule['displayed_count']
					)
				);
				if ( (int) $schedule['skipped_without_ordered_at'] > 0 ) {
					$html .= '<span class="work-list-schedule-count-note">';
					$html .= esc_html(
						sprintf(
							/* translators: %d: number of orders */
							__( '（受注日未設定 %d 件は除外）', 'ktpwp' ),
							(int) $schedule['skipped_without_ordered_at']
						)
					);
					$html .= '</span>';
				}
				if ( (int) $schedule['without_due_date_count'] > 0 ) {
					$html .= '<span class="work-list-schedule-count-note">';
					$html .= esc_html(
						sprintf(
							/* translators: %d: number of orders */
							__( '（約束納期・希望納期未入力 %d 件は棒なし）', 'ktpwp' ),
							(int) $schedule['without_due_date_count']
						)
					);
					$html .= '</span>';
				}
				$html .= '</p>';
			}

			if ( $schedule['empty'] ) {
				$html .= '<p class="work-list-schedule-empty">' . esc_html__( '工程表に表示できる受注案件がありません（受注日が未設定の案件は除外されます）。', 'ktpwp' ) . '</p>';
			} else {
				$html .= '<div class="work-list-schedule-scroll"><div class="work-list-schedule-layout">';
				$html .= '<div class="work-list-schedule-gridlines" aria-hidden="true">';
				foreach ( $schedule['ticks'] as $tick ) {
					$html .= '<span class="work-list-schedule-gridline" style="left:' . esc_attr( (string) $tick['left_pct'] ) . '%;"></span>';
				}
				$html .= '</div><div class="work-list-schedule-grid">';
				$html .= '<div class="work-list-schedule-case-header">' . esc_html__( '案件', 'ktpwp' ) . '</div>';
				$html .= '<div class="work-list-schedule-timeline-header">';
				foreach ( $schedule['ticks'] as $tick ) {
					$html .= '<span class="work-list-schedule-tick" style="left:' . esc_attr( (string) $tick['left_pct'] ) . '%;">' . esc_html( $tick['label'] ) . '</span>';
				}
				$html .= '</div>';

				foreach ( $schedule['rows'] as $index => $row ) {
					if ( $index > 0 ) {
						$html .= '<div class="work-list-schedule-row-divider" aria-hidden="true"></div>';
					}
					$html .= '<div class="work-list-schedule-label-cell">';
					$html .= '<a href="' . esc_url( $row['href'] ) . '" class="work-list-schedule-label" title="' . esc_attr( $row['label'] ) . '">' . esc_html( $row['label'] ) . '</a>';
					if ( $row['sublabel'] !== '' ) {
						$html .= '<p class="work-list-schedule-sublabel" title="' . esc_attr( $row['sublabel'] ) . '">' . esc_html( $row['sublabel'] ) . '</p>';
					}
					$html .= '</div><div class="work-list-schedule-track"';
					if ( ! $row['has_bar'] ) {
						$html .= ' title="' . esc_attr__( '約束納期・希望納期が未入力のため棒を表示できません', 'ktpwp' ) . '"';
					}
					$html .= '>';
					if ( $row['has_bar'] ) {
						$due_type_label = ( isset( $row['due_source'] ) && 'desired' === $row['due_source'] )
							? __( '希望納期', 'ktpwp' )
							: __( '約束納期', 'ktpwp' );
						$bar_title = sprintf(
							/* translators: 1: start date, 2: end date, 3: due date type label (promised or desired) */
							__( '受注 %1$s 〜 %3$s %2$s', 'ktpwp' ),
							$row['start_label'],
							$row['due_label'],
							$due_type_label
						);
						$html .= '<span class="work-list-schedule-bar" style="left:' . esc_attr( (string) $row['left_pct'] ) . '%;width:' . esc_attr( (string) $row['width_pct'] ) . '%;" title="' . esc_attr( $bar_title ) . '"></span>';
					} else {
						$html .= '<span class="work-list-schedule-no-bar">' . esc_html__( '約束納期・希望納期未入力', 'ktpwp' ) . '</span>';
					}
					$html .= '</div>';
				}

				$html .= '</div></div></div>';
			}

			$html .= '<div class="work-list-schedule-footnotes">';
			$html .= '<p class="work-list-schedule-legend">' . esc_html__( '一覧のページネーション・検索に関係なく、受注タブの全案件を表示します。', 'ktpwp' ) . '</p>';
			$html .= '<p class="work-list-schedule-legend">' . esc_html__( 'グレーの横棒は受注日から約束納期（未設定時は希望納期）までの期間を表します。いずれも未入力の案件は棒を表示しません。', 'ktpwp' ) . '</p>';
			$html .= '</div></div>';

			return $html;
		}

		/**
		 * 工程表モーダル HTML
		 *
		 * @return string
		 */
		public static function render_modal() {
			$html  = '<div id="work-list-schedule-modal" class="work-list-schedule-modal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="work-list-schedule-modal-title">';
			$html .= '<div class="work-list-schedule-overlay" aria-hidden="true"></div>';
			$html .= '<div class="work-list-schedule-modal__panel">';
			$html .= '<div class="work-list-schedule-modal__header">';
			$html .= '<h2 id="work-list-schedule-modal-title" class="work-list-schedule-modal__title">' . esc_html__( '工程表（受注）', 'ktpwp' ) . '</h2>';
			$html .= '<div class="work-list-schedule-modal__actions">';
			$html .= '<button type="button" id="js-work-list-schedule-print-btn" class="ktp-tab-print-btn ktp-list-schedule-btn" title="' . esc_attr__( '印刷（ブラウザの印刷／PDFに保存）', 'ktpwp' ) . '">' . esc_html__( '印刷', 'ktpwp' ) . '</button>';
			$html .= '<button type="button" class="work-list-schedule-close" aria-label="' . esc_attr__( '閉じる', 'ktpwp' ) . '">&times;</button>';
			$html .= '</div></div>';
			$html .= '<div id="work-list-schedule-modal-body" class="work-list-schedule-modal__body" data-loading-text="' . esc_attr__( '読み込み中…', 'ktpwp' ) . '" data-error-text="' . esc_attr__( '工程表を読み込めませんでした。', 'ktpwp' ) . '">';
			$html .= '<p class="work-list-schedule-loading">' . esc_html__( '読み込み中…', 'ktpwp' ) . '</p>';
			$html .= '</div></div></div>';

			return $html;
		}

		/**
		 * @return string
		 */
		public static function get_chart_styles() {
			return '';
		}

		/**
		 * @param object $order 受注書行。
		 * @return DateTimeImmutable|null
		 */
		private static function get_ordered_date( $order ) {
			$order_date = isset( $order->order_date ) ? trim( (string) $order->order_date ) : '';
			if ( $order_date !== '' && $order_date !== '0000-00-00' ) {
				$dt = DateTimeImmutable::createFromFormat( 'Y-m-d', $order_date, wp_timezone() );
				if ( $dt instanceof DateTimeImmutable ) {
					return $dt->setTime( 0, 0 );
				}
			}

			$raw_time = isset( $order->time ) ? $order->time : '';
			if ( $raw_time === '' || $raw_time === null || $raw_time === '0' || $raw_time === 0 ) {
				return null;
			}

			if ( is_numeric( $raw_time ) && strlen( (string) $raw_time ) >= 10 ) {
				$dt = ( new DateTimeImmutable( '@' . (int) $raw_time ) )->setTimezone( wp_timezone() );

				return $dt->setTime( 0, 0 );
			}

			$dt = date_create_immutable( (string) $raw_time, wp_timezone() );
			if ( $dt instanceof DateTimeImmutable ) {
				return $dt->setTime( 0, 0 );
			}

			return null;
		}

		/**
		 * 工程表の終了日（約束納期 → 希望納期の順で採用）
		 *
		 * @param object $order 受注書行。
		 * @return array{date: DateTimeImmutable, source: string}|null
		 */
		private static function get_schedule_due_date( $order ) {
			$promised = self::parse_date_field( isset( $order->promised_delivery_date ) ? (string) $order->promised_delivery_date : '' );
			if ( $promised instanceof DateTimeImmutable ) {
				return array(
					'date'   => $promised,
					'source' => 'promised',
				);
			}

			$desired = self::parse_date_field( isset( $order->desired_delivery_date ) ? (string) $order->desired_delivery_date : '' );
			if ( $desired instanceof DateTimeImmutable ) {
				return array(
					'date'   => $desired,
					'source' => 'desired',
				);
			}

			return null;
		}

		/**
		 * @param string $value 日付文字列（Y-m-d）。
		 * @return DateTimeImmutable|null
		 */
		private static function parse_date_field( $value ) {
			$due = trim( (string) $value );
			if ( $due === '' || $due === '0000-00-00' ) {
				return null;
			}

			$dt = DateTimeImmutable::createFromFormat( 'Y-m-d', $due, wp_timezone() );
			if ( ! $dt instanceof DateTimeImmutable ) {
				return null;
			}

			return $dt->setTime( 0, 0 );
		}

		/**
		 * @param DateTimeImmutable $range_start 開始。
		 * @param DateTimeImmutable $range_end   終了。
		 * @param int               $total_days  日数。
		 * @return list<array{label: string, left_pct: float}>
		 */
		private static function build_ticks( DateTimeImmutable $range_start, DateTimeImmutable $range_end, $total_days ) {
			$span_days = max( 1, (int) $range_start->diff( $range_end )->days );
			$step_days = $span_days <= 14 ? 1 : ( $span_days <= 60 ? 7 : 14 );
			$ticks     = array();
			$cursor    = $range_start;
			$end       = $range_end;

			while ( $cursor <= $end ) {
				$offset_days = (int) $range_start->diff( $cursor )->days;
				$ticks[]     = array(
					'label'    => $cursor->format( 'n/j' ),
					'left_pct' => self::clamp_pct( ( $offset_days / $total_days ) * 100 ),
				);
				$cursor = $cursor->add( new DateInterval( 'P' . $step_days . 'D' ) );
			}

			if ( $ticks === array() || $ticks[ count( $ticks ) - 1 ]['left_pct'] < 95 ) {
				$offset_days = (int) $range_start->diff( $end )->days;
				$ticks[]     = array(
					'label'    => $end->format( 'n/j' ),
					'left_pct' => self::clamp_pct( ( $offset_days / $total_days ) * 100 ),
				);
			}

			return $ticks;
		}

		/**
		 * @param float      $value 値。
		 * @param float|null $max   上限。
		 * @return float
		 */
		private static function clamp_pct( $value, $max = null ) {
			$clamped = max( 0.0, min( 100.0, (float) $value ) );
			if ( $max !== null ) {
				$clamped = min( $clamped, (float) $max );
			}

			return round( $clamped, 4 );
		}
	}
}
