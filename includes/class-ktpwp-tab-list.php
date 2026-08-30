<?php
/**
 * List class for KTPWP plugin
 *
 * Handles order list display, filtering, and management.
 *
 * @package KTPWP
 * @subpackage Includes
 * @since 1.0.0
 * @author Kantan Pro
 * @copyright 2024 Kantan Pro
 * @license GPL-2.0+
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'KTPWP_List_Class' ) ) {

	/**
	 * List class for managing order lists
	 *
	 * @since 1.0.0
	 */
	class KTPWP_List_Class {

		/**
		 * Constructor
		 *
		 * @since 1.0.0
		 */
		public function __construct() {
			// Constructor initialization
		}

		/**
		 * Display list tab view
		 *
		 * @since 1.0.0
		 * @param string $tab_name Tab name
		 * @return void
		 */
		public function List_Tab_View( $tab_name ) {
			// Check user capabilities
			// if ( ! current_user_can( 'manage_options' ) ) {
			// wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'ktpwp' ) );
			// }

			if ( empty( $tab_name ) ) {
				ktpwp_debug_log( 'KTPWP: Empty tab_name provided to List_Tab_View method' );
				return;
			}

			global $wpdb;
			$table_name = $wpdb->prefix . 'ktp_order';
			$order_block_exclude_sql = class_exists( 'KTPWP_Inquiry_Block' )
				? KTPWP_Inquiry_Block::sql_exclude_blocked_client_orders( "{$table_name}.client_id" )
				: '';

			$content = '';

			$recurring_billing_view = isset( $_GET['recurring_billing'] ) && '1' === (string) wp_unslash( $_GET['recurring_billing'] );
			$list_type_recurring    = isset( $_GET['list_type'] ) && 'recurring' === sanitize_key( wp_unslash( $_GET['list_type'] ) );
			$order_has_contract_id  = class_exists( 'KTPWP_Schema_Cache' )
				? KTPWP_Schema_Cache::column_exists( $table_name, 'contract_id' )
				: in_array( 'contract_id', (array) $wpdb->get_col( "SHOW COLUMNS FROM `{$table_name}`" ), true );
			$list_type_where        = ( $list_type_recurring && $order_has_contract_id ) ? ' AND contract_id > 0' : '';

			// フリーワード検索用GETパラメータ
			$list_search = isset( $_GET['list_search'] ) ? sanitize_text_field( wp_unslash( $_GET['list_search'] ) ) : '';

			$selected_progress = isset( $_GET['progress'] ) ? absint( $_GET['progress'] ) : 1;
			$schedule_view     = isset( $_GET['schedule'] ) && '1' === (string) wp_unslash( $_GET['schedule'] );

			if ( $schedule_view && 3 === $selected_progress && ! $recurring_billing_view && class_exists( 'KTPWP_Work_List_Schedule' ) ) {
				$orders   = KTPWP_Work_List_Schedule::fetch_orders( $wpdb, $list_type_where );
				$schedule = KTPWP_Work_List_Schedule::build( $orders );

				return KTPWP_Work_List_Schedule::render_schedule_page( $schedule );
			}

    // Controller container display at top（検索全幅1行、フィルタ＋印刷は2行目）
			$content .= '<div class="controller ktp-list-controller">';
			$content .= '<div class="ktp-list-controller__search">';
			$content .= '<div class="ktp-list-search-wrap" style="display:flex;align-items:center;gap:6px;">';
			$content .= '<form method="get" action="" class="ktp-list-search-form" style="display:flex;align-items:center;gap:6px;">';
			// 仕事リストタブを維持
			$content .= '<input type="hidden" name="tab_name" value="' . esc_attr( $tab_name ) . '">';
			// 既存クエリを保持（tab_nameは上で固定したので除外）
			$keep_params = array( 'progress', 'page_start', 'page_stage', 'flg', 'list_type' );
			foreach ( $keep_params as $key ) {
				if ( isset( $_GET[ $key ] ) && (string) $_GET[ $key ] !== '' ) {
					$content .= '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( wp_unslash( $_GET[ $key ] ) ) . '">';
				}
			}
			$search_placeholder = ( $list_search !== '' ) ? $list_search : __( 'フリーワード', 'ktpwp' );
			$content .= '<input type="search" id="ktp-list-search-input" name="list_search" value="" placeholder="' . esc_attr( $search_placeholder ) . '" aria-label="' . esc_attr__( 'フリーワード', 'ktpwp' ) . '" class="ktp-list-search-input" style="min-width:160px;padding:6px 8px;border:1px solid #ddd;border-radius:4px;">';
			$content .= '<button type="submit" class="ktp-list-search-btn" title="' . esc_attr__( '検索', 'ktpwp' ) . '" style="padding:6px 10px;border:1px solid #ddd;border-radius:4px;background:#f5f5f5;cursor:pointer;">🔍</button>';
			$content .= '</form>';
			$content .= '</div>';
			$content .= '</div>';

			$content .= '<div class="ktp-list-controller__bar">';
			$content .= '<div class="ktp-list-controller-actions">';
			if ( class_exists( 'KTPWP_Contract_Billing_UI' ) ) {
				$billing_ui = KTPWP_Contract_Billing_UI::get_instance();
				$content   .= $billing_ui->render_list_view_switcher( $tab_name, $recurring_billing_view );
				if ( ! $recurring_billing_view ) {
					$content .= $billing_ui->render_list_type_filter( $tab_name, $list_type_recurring );
					if ( 3 === $selected_progress ) {
						$content .= '<button type="button" id="js-work-list-schedule-btn" class="ktp-tab-print-btn ktp-list-schedule-btn" title="' . esc_attr__( '受注案件の工程表を表示（受注日→約束納期）', 'ktpwp' ) . '" data-loading-text="' . esc_attr__( '読み込み中…', 'ktpwp' ) . '" data-error-text="' . esc_attr__( '工程表を読み込めませんでした。', 'ktpwp' ) . '">' . esc_html__( '工程表', 'ktpwp' ) . '</button>';
					}
				}
			}
			$content .= '</div>';

			$progress_labels_for_print = array(
				1 => __( '受付中', 'ktpwp' ),
				2 => __( '見積中', 'ktpwp' ),
				3 => __( '受注', 'ktpwp' ),
				4 => __( '完了', 'ktpwp' ),
				5 => __( '請求済', 'ktpwp' ),
				6 => __( '入金済', 'ktpwp' ),
			);
			$selected_progress_label   = isset( $progress_labels_for_print[ $selected_progress ] )
				? $progress_labels_for_print[ $selected_progress ]
				: __( '進捗タブ', 'ktpwp' );
			$status_label_map          = array();
			foreach ( $progress_labels_for_print as $num => $label ) {
				$status_label_map[ (string) $num ] = $label;
			}
			$my_company_for_print = '';
			if ( class_exists( 'KTPWP_Settings' ) ) {
				$my_company_for_print = KTPWP_Settings::get_company_info();
			}
			if ( empty( $my_company_for_print ) ) {
				$my_company_for_print = get_bloginfo( 'name' );
			}
			$my_company_for_print = wp_strip_all_tags( (string) $my_company_for_print );
			$my_company_for_print = preg_replace( '/\S+@\S+\.\S+/', '', $my_company_for_print );
			$my_company_for_print = preg_replace( '/\s+/', ' ', trim( $my_company_for_print ) );

			// Print button（KantanBiz 相当：テキストラベル＋data 属性）
			$content .= '<div class="ktp-list-controller__tools">';
			if ( class_exists( 'KTPWP_Ui_Generator' ) ) {
				$content .= KTPWP_Ui_Generator::render_tab_print_button(
					array(
						'id'     => 'js-work-list-print-btn',
						'label'  => __( '作業リスト印刷', 'ktpwp' ),
						'title'  => __( '印刷（ブラウザの印刷／PDFに保存）', 'ktpwp' ),
						'attrs'  => array(
							'data-print-list-title'      => __( '作業リスト', 'ktpwp' ),
							'data-print-header-format'   => ':statusの作業リスト',
							'data-selected-progress'     => (string) $selected_progress,
							'data-selected-status-label' => $selected_progress_label,
							'data-print-title'           => $selected_progress_label,
							'data-default-status-label'  => __( '進捗タブ', 'ktpwp' ),
							'data-status-label-map'      => $status_label_map,
							'data-print-footer-name'     => $my_company_for_print,
						),
					)
				);
			}
			$content .= '</div>';
			$content .= '</div>';
			$content .= '</div>'; // .controller end

			// Progress status buttons
			$progress_labels = array(
				1 => __( '受付中', 'ktpwp' ),
				2 => __( '見積中', 'ktpwp' ),
				3 => __( '受注', 'ktpwp' ),
				4 => __( '完了', 'ktpwp' ),
				5 => __( '請求済', 'ktpwp' ),
				6 => __( '入金済', 'ktpwp' ),
				7 => __( 'ボツ', 'ktpwp' ),
			);

			// 印刷時だけページネーションを無視して全件取得する
			$print_all = isset( $_GET['print_all'] ) && (string) $_GET['print_all'] !== '' && (string) $_GET['print_all'] !== '0';

			// Get count for each progress status（Transient キャッシュ利用）
			$warning_bundle        = class_exists( 'KTPWP_List_Warning_Counts' )
				? KTPWP_List_Warning_Counts::get_bundle()
				: array(
					'progress_counts'         => array(),
					'progress_warnings'       => array(),
					'invoice_warning_count'   => 0,
					'payment_warning_count'   => 0,
				);
			$progress_counts       = isset( $warning_bundle['progress_counts'] ) ? $warning_bundle['progress_counts'] : array();
			$progress_warnings     = isset( $warning_bundle['progress_warnings'] ) ? $warning_bundle['progress_warnings'] : array();
			$invoice_warning_count = isset( $warning_bundle['invoice_warning_count'] ) ? (int) $warning_bundle['invoice_warning_count'] : 0;
			$payment_warning_count = isset( $warning_bundle['payment_warning_count'] ) ? (int) $warning_bundle['payment_warning_count'] : 0;

			// 印刷対象エリア開始（現在表示されている内容を印刷するためのラッパー）
			$content .= '<div id="ktp_list_print_area">';

			if ( $recurring_billing_view && class_exists( 'KTPWP_Contract_Billing_UI' ) ) {
				$billing_period = isset( $_GET['billing_period'] ) ? sanitize_text_field( wp_unslash( $_GET['billing_period'] ) ) : null;
				$content       .= KTPWP_Contract_Billing_UI::get_instance()->render_monthly_panel( $tab_name, $billing_period );
			} else {

			// 検索結果（進捗ワークフローブロックの上に表示）
			if ( $list_search !== '' ) {
				$content .= $this->render_list_search_results( $list_search, $wpdb, remove_query_arg( 'list_search' ) );
			}

			// Workflow area to display progress buttons in full width
			$content .= '<div class="workflow ktp-list-workflow" style="width:100%;margin:0px 0 0px 0;">';
			$content .= '<div class="progress-filter ktp-list-progress-filter" style="display:flex;gap:8px;width:100%;justify-content:center;">';

			// 進捗アイコンの定義
			$progress_icons = array(
				1 => 'receipt',      // 受付中
				2 => 'calculate',    // 見積中
				3 => 'build',        // 受注
				4 => 'check_circle', // 完了
				5 => 'payment',      // 請求済
				6 => 'account_balance_wallet', // 入金済
				7 => 'cancel',        // ボツ
			);

			foreach ( $progress_labels as $num => $label ) {
				// ボツ（progress = 7）はワークフローに表示しない
				if ( $num == 7 ) {
					continue;
				}

				$active = ( $selected_progress === $num ) ? 'style="font-weight:bold;background:#1976d2;color:#fff;"' : '';
				$btn_label = esc_html( $label ) . ' (' . $progress_counts[ $num ] . ')';
				$icon = isset( $progress_icons[ $num ] ) ? $progress_icons[ $num ] : 'circle';

				// 進捗タブごとの赤い(!)マーク件数（CSSで右上オーバーレイ・表示制御）
				$warning_count = 0;
				$warning_title = '';
				if ( $num == 3 ) {
					$warning_count = isset( $progress_warnings[3] ) ? $progress_warnings[3] : 0;
					$warning_title = $warning_count > 0 ? sprintf( __( '納期が迫っている、または過ぎている案件が%d件あります', 'ktpwp' ), $warning_count ) : '';
				} elseif ( $num == 4 ) {
					$warning_count = $invoice_warning_count;
					$warning_title = $warning_count > 0 ? sprintf( __( '請求日を過ぎている案件が%d件あります', 'ktpwp' ), $warning_count ) : '';
				} elseif ( $num == 5 ) {
					$warning_count = $payment_warning_count;
					$warning_title = $warning_count > 0 ? sprintf( __( '入金予定日を過ぎている案件が%d件あります', 'ktpwp' ), $warning_count ) : '';
				}
				// 受注(3)・完了(4)・請求済(5)は常にバッジ要素を出力。赤い丸の中に通常の数字（1, 100, 1000等）
				$warning_badge = '';
				if ( $num == 3 || $num == 4 || $num == 5 ) {
					$badge_text = $warning_count > 0 ? (string) (int) $warning_count : '';
					$warning_badge = '<span class="ktp-progress-warning-badge" data-progress="' . esc_attr( $num ) . '" data-count="' . (int) $warning_count . '" title="' . esc_attr( $warning_title ) . '">' . esc_html( $badge_text ) . '</span>';
				}

				// 進捗ボタンはprogressを必ず付与
				$progress_btn_url = add_query_arg(
                    array(
						'tab_name' => $tab_name,
						'progress' => $num,
                    )
                );
				$content .= '<a href="' . $progress_btn_url . '" class="progress-btn" data-progress="' . $num . '" data-icon="' . $icon . '" ' . $active . '>';
				
				// SVGアイコンを使用
				if (class_exists('KTPWP_SVG_Icons')) {
					$content .= KTPWP_SVG_Icons::get_icon($icon, array('class' => 'progress-btn-icon ktp-svg-icon'));
				} else {
					// フォールバック: Material Symbols
					$content .= '<span class="progress-btn-icon material-symbols-outlined">' . $icon . '</span>';
				}
				
				$content .= '<span class="progress-btn-text">' . $btn_label . '</span>';
				$content .= $warning_badge;
				$content .= '</a>';
			}
			$content .= '</div>';
			$content .= '</div>';

			// 受注書リスト表示
			// $content .= '<h3>■ 受注書リスト</h3>';

			// ページネーション設定
			// 一般設定から表示件数を取得（設定クラスが利用可能な場合）
			if ( class_exists( 'KTPWP_Settings' ) ) {
				$query_limit = KTPWP_Settings::get_work_list_range();
			} else {
				$query_limit = 20; // フォールバック値
			}
			$page_stage = isset( $_GET['page_stage'] ) ? $_GET['page_stage'] : '';
			$page_start = isset( $_GET['page_start'] ) ? intval( $_GET['page_start'] ) : 0;
			$flg = isset( $_GET['flg'] ) ? $_GET['flg'] : '';
			$selected_progress = isset( $_GET['progress'] ) ? intval( $_GET['progress'] ) : 1;
			if ( $page_stage == '' ) {
				$page_start = 0;
			}
			$list_search_where = '';
			$list_search_args = array();
			if ( $list_search !== '' ) {
				$list_like = '%' . $wpdb->esc_like( $list_search ) . '%';
				$order_cols = class_exists( 'KTPWP_Schema_Cache' )
					? KTPWP_Schema_Cache::get_columns( $table_name )
					: (array) $wpdb->get_col( "SHOW COLUMNS FROM `{$table_name}`" );
				$search_columns = array( 'customer_name', 'user_name', 'project_name' );
				if ( is_array( $order_cols ) && in_array( 'memo', $order_cols, true ) ) {
					$search_columns[] = 'memo';
				}
				if ( is_array( $order_cols ) && in_array( 'search_field', $order_cols, true ) ) {
					$search_columns[] = 'search_field';
				}
				$search_parts = array();
				foreach ( $search_columns as $search_column ) {
					$search_parts[] = "`{$search_column}` LIKE %s";
					$list_search_args[] = $list_like;
				}
				$list_search_where = ' AND ( ' . implode( ' OR ', $search_parts ) . ' )';
			}
			// 総件数取得
			$total_query = $wpdb->prepare(
				"SELECT COUNT(*) FROM {$table_name} WHERE progress = %d{$list_search_where}{$list_type_where}{$order_block_exclude_sql}",
				array_merge( array( $selected_progress ), $list_search_args )
			);
			$total_rows = $wpdb->get_var( $total_query );
			$total_pages = ceil( $total_rows / $query_limit );
			$current_page = floor( $page_start / $query_limit ) + 1;
			$effective_due_sql = "COALESCE(NULLIF(promised_delivery_date, '0000-00-00'), NULLIF(desired_delivery_date, '0000-00-00'))";

			// データ取得（進捗が「受注」の場合は納期順でソート）
			if ( $print_all ) {
				// ページネーション無視：LIMIT を付けず全件取得
				if ( $selected_progress == 3 ) {
					// 受注の場合は約束納期（未設定時は希望納期）が迫っている順でソート
					$query = $wpdb->prepare(
						"SELECT *,
                    CASE
                        WHEN {$effective_due_sql} IS NULL THEN 999999
                        WHEN {$effective_due_sql} <= CURDATE() THEN 0
                        ELSE DATEDIFF({$effective_due_sql}, CURDATE())
                    END as days_until_delivery
                FROM {$table_name}
                WHERE progress = %d{$list_search_where}{$list_type_where}{$order_block_exclude_sql}
                ORDER BY days_until_delivery ASC, time DESC",
						array_merge( array( $selected_progress ), $list_search_args )
					);
				} else {
					// その他の進捗は従来通り時間順でソート
					$query = $wpdb->prepare(
						"SELECT * FROM {$table_name}
                WHERE progress = %d{$list_search_where}{$list_type_where}{$order_block_exclude_sql}
                ORDER BY time DESC",
						array_merge( array( $selected_progress ), $list_search_args )
					);
				}
			} else {
				// ページネーションあり（従来通り LIMIT/OFFSET）
				if ( $selected_progress == 3 ) {
					// 受注の場合は約束納期（未設定時は希望納期）が迫っている順でソート
					$query = $wpdb->prepare(
                        "SELECT *,
                    CASE
                        WHEN {$effective_due_sql} IS NULL THEN 999999
                        WHEN {$effective_due_sql} <= CURDATE() THEN 0
                        ELSE DATEDIFF({$effective_due_sql}, CURDATE())
                    END as days_until_delivery
                FROM {$table_name}
                WHERE progress = %d{$list_search_where}{$list_type_where}{$order_block_exclude_sql}
                ORDER BY days_until_delivery ASC, time DESC
                LIMIT %d, %d",
						array_merge( array( $selected_progress ), $list_search_args, array( $page_start, $query_limit ) )
					);
				} else {
					// その他の進捗は従来通り時間順でソート
					$query = $wpdb->prepare(
                        "SELECT * FROM {$table_name} 
                WHERE progress = %d{$list_search_where}{$list_type_where}{$order_block_exclude_sql}
                ORDER BY time DESC 
                LIMIT %d, %d",
						array_merge( array( $selected_progress ), $list_search_args, array( $page_start, $query_limit ) )
					);
				}
			}

			$order_list = $wpdb->get_results( $query );

			// --- ここからラッパー追加 ---
			$my_company = '';
			if ( class_exists( 'KTPWP_Settings' ) ) {
				$my_company = KTPWP_Settings::get_company_info();
			}
			if ( empty( $my_company ) ) {
				$my_company = get_bloginfo( 'name' );
			}
			$my_company = wp_strip_all_tags( (string) $my_company );
			// メールアドレス表記はフッターでは不要なため削除
			$my_company = preg_replace( '/\S+@\S+\.\S+/', '', $my_company );
			$my_company = preg_replace( '/\s+/', ' ', trim( $my_company ) );

			$content .= '<div class="ktp_work_list_box">';
			$content .= '<div id="ktp_list_my_company_name" style="display:none;">' . esc_html( $my_company ) . '</div>';

			// 受注の場合はソート順を説明
			if ( $selected_progress == 3 ) {
				$content .= '<div class="ktp-list-sort-notice">';
				$content .= '<strong>' . esc_html__( '📅 ソート順:', 'ktpwp' ) . '</strong> ' . esc_html__( '約束納期（未設定時は希望納期）が迫っている順 → 受注日時順（新しい順）で表示されています。', 'ktpwp' );
				$content .= '</div>';
			}

			$date_input_lang_attr = ( function_exists( 'determine_locale' ) && strpos( strtolower( determine_locale() ), 'en' ) === 0 ) ? ' lang="en-US"' : '';

			if ( $order_list ) {
				// 進捗ラベル
				$progress_labels = array(
					1 => __( '受付中', 'ktpwp' ),
					2 => __( '見積中', 'ktpwp' ),
					3 => __( '受注', 'ktpwp' ),
					4 => __( '完了', 'ktpwp' ),
					5 => __( '請求済', 'ktpwp' ),
					6 => __( '入金済', 'ktpwp' ),
					7 => __( 'ボツ', 'ktpwp' ),
				);

				// 締め日・支払条件の警告表示に使う顧客情報を一括取得する。
				// 以前は表示行ごとに SELECT ... WHERE id = %d を発行していたため、
				// 1ページ表示するだけで表示件数と同じ本数のクエリが発生していた（N+1）。
				$client_info_map = array();
				if ( in_array( (int) $selected_progress, array( 4, 5 ), true ) && ! empty( $order_list ) ) {
					$client_ids = array();
					foreach ( $order_list as $order ) {
						$cid = isset( $order->client_id ) ? (int) $order->client_id : 0;
						if ( $cid > 0 ) {
							$client_ids[ $cid ] = $cid;
						}
					}
					if ( ! empty( $client_ids ) ) {
						$client_table    = $wpdb->prefix . 'ktp_client';
						$client_ids      = array_values( $client_ids );
						$id_placeholders = implode( ', ', array_fill( 0, count( $client_ids ), '%d' ) );
						$client_rows     = $wpdb->get_results(
							$wpdb->prepare(
								"SELECT id, closing_day, payment_month, payment_day FROM `{$client_table}` WHERE id IN ({$id_placeholders})",
								$client_ids
							)
						);
						if ( is_array( $client_rows ) ) {
							foreach ( $client_rows as $client_row ) {
								$client_info_map[ (int) $client_row->id ] = $client_row;
							}
						}
					}
				}

				$content .= '<ul>';
				foreach ( $order_list as $order ) {
					$order_id = esc_html( $order->id );
					$project_name = isset( $order->project_name ) ? esc_html( $order->project_name ) : '';
					$list_client_label = '';
					if ( class_exists( 'KTPWP_Department_Manager' ) ) {
						$list_client_label = esc_html(
							KTPWP_Department_Manager::format_work_list_client_label_for_order( $order )
						);
					} else {
						$list_client_label = esc_html( trim( (string) $order->customer_name . ' ' . (string) $order->user_name ) );
					}

					$client_id = isset( $order->client_id ) ? (int) $order->client_id : 0;

					$promised_stored = isset( $order->promised_delivery_date ) ? trim( (string) $order->promised_delivery_date ) : '';
					if ( $promised_stored === '0000-00-00' ) {
						$promised_stored = '';
					}
					$promised_delivery_date = $promised_stored;
					$desired_fallback       = isset( $order->desired_delivery_date ) ? trim( (string) $order->desired_delivery_date ) : '';
					if ( $desired_fallback === '0000-00-00' ) {
						$desired_fallback = '';
					}
					$promised_input_title = __( '約束納期', 'ktpwp' );
					if ( $promised_stored === '' && $desired_fallback !== '' ) {
						$promised_input_title .= ' (' . sprintf(
							/* translators: %s: desired delivery date (Y-m-d) */
							__( '希望納期: %s', 'ktpwp' ),
							$desired_fallback
						) . ')';
					}
					$effective_due_date = $this->resolve_effective_due_date( $order );

					// 完了日フィールドの値を取得
					$completion_date = isset( $order->completion_date ) ? $order->completion_date : '';

					// 納期警告の判定（約束納期・希望納期のいずれかが迫っている／過ぎている）
					$show_warning = false;
					$is_urgent = false; // 緊急案件フラグ
					$delivery_warning_title = ''; // 行のツールチップ用
					if ( $effective_due_date !== '' && $selected_progress == 3 ) {
						// 一般設定から警告日数を取得
						$warning_days = 3; // デフォルト値
						if ( class_exists( 'KTPWP_Settings' ) ) {
							$warning_days = KTPWP_Settings::get_delivery_warning_days();
						}

						// 納期が迫っているか／過ぎているかチェック（不正な日付の場合はスキップ）
						$delivery_date = DateTime::createFromFormat( 'Y-m-d', $effective_due_date );
						if ( $delivery_date !== false ) {
							$delivery_date->setTime( 0, 0, 0 ); // 時間を00:00:00に設定
							$today = new DateTime();
							$today->setTime( 0, 0, 0 ); // 時間を00:00:00に設定

							$diff = $today->diff( $delivery_date );
							$days_left = $diff->invert ? -$diff->days : $diff->days;

							// 納期が迫っている（警告日数以内）または納期過ぎのときに警告表示
							$show_warning = $days_left <= $warning_days;
							$is_urgent = $days_left <= $warning_days;
							$delivery_warning_title = $days_left < 0
								? __( '納期が過ぎています', 'ktpwp' )
								: __( '納期が迫っています', 'ktpwp' );

							// デバッグ情報（開発時のみ）
							if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
								$debug_msg = '納期警告判定: 今日=' . $today->format( 'Y-m-d' ) . ', 納期=' . $delivery_date->format( 'Y-m-d' ) . ', 残り日数=' . $days_left . ', 警告日数=' . $warning_days . ', 表示=' . ( $show_warning ? 'YES' : 'NO' );
								ktpwp_debug_log( $debug_msg );
							}
						}
					}

					// ▼▼▼ 請求書締日警告の判定 ▼▼▼
					$show_invoice_warning = false;
					$invoice_warning_message = '';
					if ( $selected_progress == 4 ) { // 完了
						// 顧客IDから締め日を取得
						$client_id = isset( $order->client_id ) ? intval( $order->client_id ) : 0;
						if ( $client_id > 0 ) {
							$client_info = isset( $client_info_map[ $client_id ] ) ? $client_info_map[ $client_id ] : null;
							if ( $client_info && $client_info->closing_day && $client_info->closing_day !== 'なし' ) {
								// 案件の完了日を取得
								$completion_date = isset( $order->completion_date ) ? trim( (string) $order->completion_date ) : '';
								if ( $completion_date !== '' ) {
									$completion_dt = DateTime::createFromFormat( 'Y-m-d', $completion_date );
									if ( $completion_dt === false ) {
										$completion_dt = null;
									}
									if ( $completion_dt ) {
										$year = (int) $completion_dt->format( 'Y' );
										$month = (int) $completion_dt->format( 'm' );
										// 不正な年・月の場合は締め日計算をスキップ（-1-11-05 等の例外を防ぐ）
										if ( $year < 1 || $year > 9999 || $month < 1 || $month > 12 ) {
											$completion_dt = null;
										}
									}
									if ( $completion_dt ) {
										$closing_day = $client_info->closing_day;
										if ( $closing_day === '末日' ) {
											$closing_dt = new DateTime( "$year-$month-01" );
											$closing_dt->modify( 'last day of this month' );
										} else {
											$closing_day_num = intval( $closing_day );
											$closing_dt = new DateTime( "$year-$month-" . str_pad( $closing_day_num, 2, '0', STR_PAD_LEFT ) );
											// 月末を超える場合は末日に補正
											$last_day = (int) $closing_dt->format( 't' );
											if ( $closing_day_num > $last_day ) {
												$closing_dt->modify( 'last day of this month' );
											}
										}
										// 今日から締め日までの日数
										$today = new DateTime();
										$today->setTime( 0, 0, 0 );
										$closing_dt->setTime( 0, 0, 0 );
										$diff = $today->diff( $closing_dt );
										$days_left = $diff->invert ? -$diff->days : $diff->days;
										// 請求日当日以降の場合に警告マークを表示
										if ( $days_left <= 0 ) {
											$show_invoice_warning = true;
										}
									}
								}
							}
						}
					}

					// ▼▼▼ 入金予定日（支払期日）超過の判定（前入金済は対象外） ▼▼▼
					$show_payment_warning = false;
					$payment_warning_title = '';
					if ( $selected_progress == 5 ) { // 請求済
						// 前入金済みは未入金警告対象外
						$is_prepay = class_exists( 'KTPWP_Payment_Timing' ) && KTPWP_Payment_Timing::is_prepay( $order, null );
						if ( ! $is_prepay ) {
						$client_id = isset( $order->client_id ) ? (int) $order->client_id : 0;
						$completion_date_raw = isset( $order->completion_date ) ? trim( (string) $order->completion_date ) : '';
						if ( $client_id > 0 && $completion_date_raw !== '' ) {
							$client_info = isset( $client_info_map[ $client_id ] ) ? $client_info_map[ $client_id ] : null;
							if ( $client_info && ! empty( $client_info->payment_month ) && ! empty( $client_info->payment_day ) ) {
								$completion_dt = DateTime::createFromFormat( 'Y-m-d', $completion_date_raw );
								$errors = DateTime::getLastErrors();
								if ( $completion_dt !== false && ! ( $errors && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) ) {
									$completion_dt->setTime( 0, 0, 0 );
									$year  = (int) $completion_dt->format( 'Y' );
									$month = (int) $completion_dt->format( 'm' );
									if ( $year >= 1 && $year <= 9999 && $month >= 1 && $month <= 12 ) {
										$closing_day   = ! empty( $client_info->closing_day ) ? (string) $client_info->closing_day : '末日';
										$payment_month = (string) $client_info->payment_month;
										$payment_day   = (string) $client_info->payment_day;

										// 完了日が属する請求月（締め日基準）を決定
										$billing_year  = $year;
										$billing_month = $month;
										if ( $closing_day !== '' && $closing_day !== 'なし' ) {
											if ( $closing_day === '末日' ) {
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
											if ( $completion_dt > $closing_dt ) {
												$billing_month++;
												if ( $billing_month > 12 ) {
													$billing_month = 1;
													$billing_year++;
												}
											}
										}

										// 支払月を計算（今月/翌月/翌々月）
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

										// 支払日を計算（末日/即日/指定日）
										if ( $payment_day === '即日' ) {
											$due_dt = clone $completion_dt;
										} else {
											$due_dt = new DateTime();
											$due_dt->setDate( $payment_year, $payment_m_num, 1 );
											if ( $payment_day === '末日' ) {
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
										}

										$today = new DateTime();
										$today->setTime( 0, 0, 0 );
										if ( $today > $due_dt ) {
											$show_payment_warning = true;
											$payment_warning_title = __( '入金予定日が過ぎています', 'ktpwp' );
										}
									}
								}
							}
						}
						}
					}

					// 受付日フォーマット変換（仕事リストでは年月日のみ表示）
					$raw_time = $order->time;
					$formatted_time = '';
					if ( ! empty( $raw_time ) ) {
						// UNIXタイムスタンプかMySQL日付か判定
						if ( is_numeric( $raw_time ) && strlen( $raw_time ) >= 10 ) {
							// UNIXタイムスタンプ（秒単位）
							$timestamp = (int) $raw_time;
							$dt = new DateTime( '@' . $timestamp );
							$dt->setTimezone( new DateTimeZone( 'Asia/Tokyo' ) );
						} else {
							// MySQL DATETIME形式
							$dt = date_create( $raw_time, new DateTimeZone( 'Asia/Tokyo' ) );
						}
						if ( $dt ) {
							$formatted_time = $dt->format( 'Y/n/j' );
						}
					}
					$time = esc_html( $formatted_time );
					$progress = intval( $order->progress );

					// シンプルなURL生成（パーマリンク設定に依存しない）
					// $detail_url = '?tab_name=order&order_id=' . $order_id;
					// progressはリスト詳細リンクには付与しない
					$detail_url = add_query_arg(
                        array(
							'tab_name' => 'order',
							'order_id' => $order_id,
                        )
                    );

					// プルダウンフォーム（警告バッジ対象の行は同じ赤強調）
					$urgent_class = ( $is_urgent || $show_invoice_warning || $show_payment_warning ) ? 'urgent-delivery' : '';
					$row_click_handler   = "if (!event.target.closest('input, select, button, a, form, label')) { window.location.href = this.dataset.detailUrl; }";
					$row_keydown_handler = "if ((event.key === 'Enter' || event.key === ' ') && !event.target.closest('input, select, button, a, form, label')) { event.preventDefault(); window.location.href = this.dataset.detailUrl; }";
					$content .= '<li class="ktp_work_list_item ' . esc_attr( $urgent_class ) . '" data-detail-url="' . esc_url( $detail_url ) . '" role="link" tabindex="0" onclick="' . esc_attr( $row_click_handler ) . '" onkeydown="' . esc_attr( $row_keydown_handler ) . '">';
					// 左寄せブロック（ID・顧客名・担当者・プロジェクト・日時を一まとまりで左寄せ）
					$content .= '<span class="ktp_work_list_item_text">';
					// 行全体を受注書詳細へのリンクに統一し、顧客詳細リンクは廃止
					$content .= 'ID: ' . $order_id;
					if ( $list_client_label !== '' ) {
						$content .= ' ' . $list_client_label;
					}
					if ( $project_name !== '' ) {
						$content .= " - <span class='project_name'>{$project_name}</span>";
					}
					$content .= " - {$time}";
					// 受注経路ラベル（WEB受注 / 前入金済 / EC受注 等）
					if ( class_exists( 'KTPWP_Payment_Timing' ) ) {
						$source_label = KTPWP_Payment_Timing::get_inbound_source_label( $order, null );
						if ( $source_label !== '' ) {
							$content .= ' <span class="ktp-prepay-badge" style="display:inline-block;margin-left:6px;padding:2px 8px;font-size:11px;background:#e3f2fd;color:#1565c0;border-radius:4px;">' . esc_html( $source_label ) . '</span>';
						}
					}
					if ( $order_has_contract_id && isset( $order->contract_id ) && (int) $order->contract_id > 0 ) {
						$content .= ' <span class="ktp-recurring-badge">' . esc_html__( '定期', 'ktpwp' ) . '</span>';
					}
					$content .= '</span>';

					// 納期フィールドと進捗プルダウンを1つのコンテナにまとめる
					$content .= "<div class='delivery-dates-container'>";
					$content .= "<div class='delivery-input-wrapper'>";
					$content .= "<span class='delivery-label'>" . esc_html__( '約束納期', 'ktpwp' ) . "</span>";
					$content .= "<input type='date' name='promised_delivery_date_{$order_id}' value='" . esc_attr( $promised_delivery_date ) . "' class='delivery-date-input' data-order-id='{$order_id}' data-field='promised_delivery_date' data-last-saved='" . esc_attr( $promised_stored ) . "' placeholder='" . esc_attr__( '約束納期', 'ktpwp' ) . "' title='" . esc_attr( $promised_input_title ) . "'{$date_input_lang_attr}>";

					// 納期警告マークを追加
					if ( $show_warning && $delivery_warning_title !== '' ) {
						$content .= '<span class="delivery-warning-mark-row" title="' . esc_attr( $delivery_warning_title ) . '">!</span>';
					}

					// ▼▼▼ 請求書締日警告マークを追加 ▼▼▼
					if ( $show_invoice_warning ) {
						$content .= '<span class="invoice-warning-mark-row" title="' . esc_attr__( '請求日を過ぎています', 'ktpwp' ) . '">!</span>';
					}

					// ▼▼▼ 入金予定日超過警告マークを追加 ▼▼▼
					if ( $show_payment_warning && $payment_warning_title !== '' ) {
						$content .= '<span class="payment-warning-mark-row" title="' . esc_attr( $payment_warning_title ) . '">!</span>';
					}

					$content .= '</div>';

					// 完了日カレンダーを納期カレンダーの右側に追加
					$content .= "<div class='completion-input-wrapper'>";
					$content .= "<span class='completion-label'><span class='completion-label-desktop'>" . esc_html__( '完了日', 'ktpwp' ) . "</span><span class='completion-label-mobile'>" . esc_html__( '完了', 'ktpwp' ) . "</span></span>";
					$content .= "<input type='date' name='completion_date_{$order_id}' value='{$completion_date}' class='completion-date-input' data-order-id='{$order_id}' data-field='completion_date' placeholder='" . esc_attr__( '完了日', 'ktpwp' ) . "' title='" . esc_attr__( '完了日', 'ktpwp' ) . "'{$date_input_lang_attr}>";
					$content .= '</div>';

					// 進捗プルダウンを納期コンテナ内に配置
					$content .= "<form method='post' action='' style='margin: 0px 0 0px 0;display:inline;'>";
					$content .= "<input type='hidden' name='update_progress_id' value='{$order_id}' />";
					$content .= "<select name='update_progress' class='progress-select status-{$progress}' onchange='this.form.submit()'>";
					foreach ( $progress_labels as $num => $label ) {
						$selected = ( $progress === $num ) ? 'selected' : '';
						$content .= "<option value='{$num}' {$selected}>{$label}</option>";
					}
					$content .= '</select>';
					$content .= '</form>';
					$content .= '</div>';
					$content .= '</li>';
				}
				$content .= '</ul>';
			} else {
				$content .= '<div class="ktp_data_list_item" style="padding: 15px 20px; background: linear-gradient(135deg, #e3f2fd 0%, #fce4ec 100%); border-radius: 8px; margin: 18px 0; color: #333; font-weight: 600; box-shadow: 0 3px 12px rgba(0,0,0,0.07); display: flex; align-items: center; font-size: 15px; gap: 10px;">'
                . '<span class="material-symbols-outlined" aria-label="データなし">search_off</span>'
                . '<span style="font-size: 1em; font-weight: 600;">' . esc_html__( '受注書データがありません。', 'ktpwp' ) . '</span>'
                . '<span style="margin-left: 18px; font-size: 13px; color: #888;">' . esc_html__( '顧客タブで顧客情報を入力し受注書を作成してください', 'ktpwp' ) . '</span>'
                . '</div>';
			}
			// 進捗更新処理
			if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['update_progress_id'], $_POST['update_progress'] ) ) {
				$update_id = intval( $_POST['update_progress_id'] );
				$update_progress = intval( $_POST['update_progress'] );
				if ( $update_id > 0 && $update_progress >= 1 && $update_progress <= 7 ) {
					// 現在の進捗を取得
					$current_order = $wpdb->get_row( $wpdb->prepare( "SELECT progress FROM {$table_name} WHERE id = %d", $update_id ) );

					$update_data = array( 'progress' => $update_progress );

					// 進捗が「完了」（progress = 4）に変更された場合、完了日を記録
					if ( $update_progress == 4 && $current_order && $current_order->progress != 4 ) {
						$update_data['completion_date'] = current_time( 'Y-m-d' );
					}

					// 進捗が受注以前（受付中、見積中、受注）に変更された場合、完了日をクリア
					if ( in_array( $update_progress, array( 1, 2, 3 ) ) && $current_order && $current_order->progress > 3 ) {
						$update_data['completion_date'] = null;
					}

					$wpdb->update( $table_name, $update_data, array( 'id' => $update_id ) );
					if ( class_exists( 'KTPWP_Order_Progress_Effects' ) ) {
						KTPWP_Order_Progress_Effects::after_progress_updated( $update_id, $update_progress );
					}
					if ( class_exists( 'KTPWP_List_Warning_Counts' ) ) {
						KTPWP_List_Warning_Counts::invalidate();
					}
					// リダイレクトで再読み込み（POSTリダブミット防止）
					wp_redirect( esc_url_raw( $_SERVER['REQUEST_URI'] ) );
					exit;
				}
			}
			// --- ページネーション ---
			// データ0でも常にページネーションを表示するため、条件チェックを削除
			// 統一されたページネーションデザインを使用
			if ( ! $print_all ) {
				$content .= $this->render_pagination( $current_page, $total_pages, $query_limit, $tab_name, $flg, $selected_progress, $total_rows );
			}
			$content .= '</div>'; // .ktp_work_list_box 終了
			// --- ここまでラッパー追加 ---

			} // end recurring_billing_view else (通常の仕事リスト)

			$content .= '</div>'; // #ktp_list_print_area 終了

			if ( 3 === $selected_progress && ! $recurring_billing_view && class_exists( 'KTPWP_Work_List_Schedule' ) ) {
				$content .= KTPWP_Work_List_Schedule::render_modal();
			}

			return $content;
		}

		/**
		 * 仕事リスト用フリーワード検索結果をレンダリング（受注書・顧客・サービス・協力会社を横断検索）
		 *
		 * @param string $keyword  検索キーワード
		 * @param \wpdb   $wpdb     WordPress DB インスタンス
		 * @param string $close_url 検索結果を閉じるリンク先URL（list_search を除いたURL）
		 * @return string 検索結果HTML
		 */
		private function render_list_search_results( $keyword, $wpdb, $close_url = '' ) {
			$like = '%' . $wpdb->esc_like( $keyword ) . '%';
			$results = array();

			// 受注書（memo/search_field はテーブルに存在する前提でLIKEに含める）
			$order_table = $wpdb->prefix . 'ktp_order';
			$order_args = array( $like, $like, $like );
			$order_where = " ( customer_name LIKE %s OR user_name LIKE %s OR project_name LIKE %s ";
			$order_cols = class_exists( 'KTPWP_Schema_Cache' )
				? KTPWP_Schema_Cache::get_columns( $order_table )
				: (array) $wpdb->get_col( "SHOW COLUMNS FROM `{$order_table}`" );
			if ( is_array( $order_cols ) && in_array( 'memo', $order_cols, true ) ) {
				$order_where .= " OR memo LIKE %s ";
				$order_args[] = $like;
			}
			if ( is_array( $order_cols ) && in_array( 'search_field', $order_cols, true ) ) {
				$order_where .= " OR search_field LIKE %s ";
				$order_args[] = $like;
			}
			$order_where .= ') ';
			$order_args[] = 50;
			$order_block_exclude_sql = class_exists( 'KTPWP_Inquiry_Block' )
				? KTPWP_Inquiry_Block::sql_exclude_blocked_client_orders( 'o.client_id' )
				: '';
			$order_sql = "SELECT id, client_id, customer_name, user_name, project_name FROM `{$order_table}` o WHERE " . $order_where . $order_block_exclude_sql . " ORDER BY time DESC LIMIT %d";
			$orders = $wpdb->get_results( $wpdb->prepare( $order_sql, $order_args ) );
			if ( $orders ) {
				foreach ( $orders as $row ) {
					$client_label = class_exists( 'KTPWP_Department_Manager' )
						? KTPWP_Department_Manager::format_work_list_client_label_for_order( $row )
						: trim( (string) ( $row->customer_name ?: '' ) . ' ' . (string) ( $row->user_name ?: '' ) );
					$label = 'ID: ' . (int) $row->id;
					if ( $client_label !== '' ) {
						$label .= ' ' . $client_label;
					}
					if ( $row->project_name ) {
						$label .= ' - ' . $row->project_name;
					}
					$url = add_query_arg( array( 'tab_name' => 'order', 'order_id' => (int) $row->id ) );
					$results[] = array(
						'page_label' => __( '受注書', 'ktpwp' ),
						'label'     => $label,
						'url'       => $url,
					);
				}
			}

			// 顧客
			$client_table = $wpdb->prefix . 'ktp_client';
			$client_cols = class_exists( 'KTPWP_Schema_Cache' )
				? KTPWP_Schema_Cache::get_columns( $client_table )
				: (array) $wpdb->get_col( "SHOW COLUMNS FROM `{$client_table}`" );
			$client_where = " ( company_name LIKE %s OR name LIKE %s ";
			$client_args = array( $like, $like );
			if ( is_array( $client_cols ) && in_array( 'memo', $client_cols, true ) ) {
				$client_where .= " OR memo LIKE %s ";
				$client_args[] = $like;
			}
			if ( is_array( $client_cols ) && in_array( 'search_field', $client_cols, true ) ) {
				$client_where .= " OR search_field LIKE %s ";
				$client_args[] = $like;
			}
			$client_where .= ') ';
			$client_args[] = 50;
			$client_exclude_sql = class_exists( 'KTPWP_Inquiry_Block' ) ? KTPWP_Inquiry_Block::sql_list_exclude_clause() : '';
			$client_sql = "SELECT id, company_name, name FROM `{$client_table}` WHERE " . $client_where . $client_exclude_sql . " ORDER BY id DESC LIMIT %d";
			$clients = $wpdb->get_results( $wpdb->prepare( $client_sql, $client_args ) );
			if ( $clients ) {
				foreach ( $clients as $row ) {
					$label = ( $row->company_name ?: '' ) . ' (' . ( $row->name ?: '' ) . ')';
					$url = add_query_arg( array( 'tab_name' => 'client', 'data_id' => (int) $row->id ) );
					$results[] = array(
						'page_label' => __( '顧客', 'ktpwp' ),
						'label'     => $label,
						'url'       => $url,
					);
				}
			}

			// サービス
			$service_table = $wpdb->prefix . 'ktp_service';
			$service_cols = class_exists( 'KTPWP_Schema_Cache' )
				? KTPWP_Schema_Cache::get_columns( $service_table )
				: (array) $wpdb->get_col( "SHOW COLUMNS FROM `{$service_table}`" );
			$service_where = " ( service_name LIKE %s ";
			$service_args = array( $like );
			if ( is_array( $service_cols ) ) {
				if ( in_array( 'memo', $service_cols, true ) ) {
					$service_where .= " OR memo LIKE %s ";
					$service_args[] = $like;
				}
				if ( in_array( 'category', $service_cols, true ) ) {
					$service_where .= " OR category LIKE %s ";
					$service_args[] = $like;
				}
				if ( in_array( 'search_field', $service_cols, true ) ) {
					$service_where .= " OR search_field LIKE %s ";
					$service_args[] = $like;
				}
			}
			$service_where .= ') ';
			$service_args[] = 50;
			$service_sql = "SELECT id, service_name FROM `{$service_table}` WHERE " . $service_where . " ORDER BY id DESC LIMIT %d";
			$services = $wpdb->get_results( $wpdb->prepare( $service_sql, $service_args ) );
			if ( $services ) {
				foreach ( $services as $row ) {
					$label = ( $row->service_name ?: '' );
					$url = add_query_arg( array( 'tab_name' => 'service', 'data_id' => (int) $row->id ) );
					$results[] = array(
						'page_label' => __( 'サービス', 'ktpwp' ),
						'label'     => $label,
						'url'       => $url,
					);
				}
			}

			// 協力会社（職能・スキル名 product_name も検索対象）
			$supplier_table = $wpdb->prefix . 'ktp_supplier';
			$supplier_skills_table = $wpdb->prefix . 'ktp_supplier_skills';
			$supplier_skills_exists = class_exists( 'KTPWP_Schema_Cache' )
				? KTPWP_Schema_Cache::table_exists( $supplier_skills_table )
				: ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $supplier_skills_table ) ) === $supplier_skills_table );
			$supplier_cols = class_exists( 'KTPWP_Schema_Cache' )
				? KTPWP_Schema_Cache::get_columns( $supplier_table )
				: (array) $wpdb->get_col( "SHOW COLUMNS FROM `{$supplier_table}`" );
			$supplier_where = $supplier_skills_exists ? " ( s.company_name LIKE %s OR s.name LIKE %s " : " ( company_name LIKE %s OR name LIKE %s ";
			$supplier_args = array( $like, $like );
			if ( is_array( $supplier_cols ) ) {
				$pre = $supplier_skills_exists ? 's.' : '';
				if ( in_array( 'memo', $supplier_cols, true ) ) {
					$supplier_where .= " OR {$pre}memo LIKE %s ";
					$supplier_args[] = $like;
				}
				if ( in_array( 'search_field', $supplier_cols, true ) ) {
					$supplier_where .= " OR {$pre}search_field LIKE %s ";
					$supplier_args[] = $like;
				}
			}
			if ( $supplier_skills_exists ) {
				$supplier_where .= " OR ss.product_name LIKE %s ";
				$supplier_args[] = $like;
			}
			$supplier_where .= ') ';
			$supplier_args[] = 50;
			if ( $supplier_skills_exists ) {
				$supplier_sql = "SELECT DISTINCT s.id, s.company_name, s.name FROM `{$supplier_table}` s LEFT JOIN `{$supplier_skills_table}` ss ON ss.supplier_id = s.id WHERE " . $supplier_where . " ORDER BY s.id DESC LIMIT %d";
			} else {
				$supplier_sql = "SELECT id, company_name, name FROM `{$supplier_table}` WHERE " . $supplier_where . " ORDER BY id DESC LIMIT %d";
			}
			$suppliers = $wpdb->get_results( $wpdb->prepare( $supplier_sql, $supplier_args ) );
			if ( $suppliers ) {
				foreach ( $suppliers as $row ) {
					$label = ( $row->company_name ?: '' ) . ' (' . ( $row->name ?: '' ) . ')';
					$url = add_query_arg( array( 'tab_name' => 'supplier', 'data_id' => (int) $row->id ) );
					$results[] = array(
						'page_label' => __( '協力会社', 'ktpwp' ),
						'label'     => $label,
						'url'       => $url,
					);
				}
			}

			$close_btn = '';
			if ( $close_url !== '' ) {
				$close_btn = '<a href="' . esc_url( $close_url ) . '" class="ktp-list-search-results-close" title="' . esc_attr__( '閉じる', 'ktpwp' ) . '" style="position:absolute;top:8px;right:8px;width:28px;height:28px;display:flex;align-items:center;justify-content:center;color:#666;text-decoration:none;font-size:18px;line-height:1;border-radius:4px;transition:color .2s,background .2s;" onmouseover="this.style.color=\'#333\';this.style.background=\'#eee\';" onmouseout="this.style.color=\'#666\';this.style.background=\'transparent\';">×</a>';
			}

			if ( empty( $results ) ) {
				$html = '<div class="ktp-list-search-results" style="position:relative;margin-bottom:16px;padding:14px 18px;padding-right:44px;background:#f9f9f9;border:1px solid #eee;border-radius:6px;">';
				$html .= $close_btn;
				$html .= '<p style="margin:0;color:#666;font-size:14px;">' . esc_html__( '検索に一致するデータはありません。', 'ktpwp' ) . '</p>';
				$html .= '</div>';
				return $html;
			}

			$html = '<div class="ktp-list-search-results" style="position:relative;margin-bottom:16px;padding:14px 18px;padding-right:44px;background:#f0f7ff;border:1px solid #bbdefb;border-radius:6px;">';
			$html .= $close_btn;
			$html .= '<p style="margin:0 0 10px 0;font-weight:bold;font-size:14px;color:#1565c0;">' . esc_html__( '検索結果', 'ktpwp' ) . '</p>';
			$html .= '<ul style="margin:0;padding-left:20px;list-style:disc;">';
			foreach ( $results as $r ) {
				$html .= '<li style="margin-bottom:6px;">';
				$html .= '<span>' . esc_html( $r['label'] ) . '</span> ';
				$html .= '<a href="' . esc_url( $r['url'] ) . '" style="color:#1976d2;font-weight:600;">' . esc_html( $r['page_label'] ) . '</a>';
				$html .= '</li>';
			}
			$html .= '</ul>';
			$html .= '</div>';
			return $html;
		}

		/**
		 * 統一されたページネーションデザインをレンダリング
		 *
		 * @param int    $current_page 現在のページ
		 * @param int    $total_pages 総ページ数
		 * @param int    $total_pages 総ページ数
		 * @param int    $query_limit 1ページあたりの表示件数
		 * @param string $tab_name タブ名
		 * @param string $flg フラグ
		 * @param int    $selected_progress 選択された進捗
		 * @param int    $total_rows 総データ数
		 * @return string ページネーションHTML
		 */
		private function render_pagination( $current_page, $total_pages, $query_limit, $tab_name, $flg, $selected_progress, $total_rows ) {
			// 0データの場合でもページネーションを表示（要件対応）
			// データが0件の場合はtotal_pagesが0になるため、最低1ページとして扱う
			if ( $total_pages == 0 ) {
				$total_pages = 1;
				$current_page = 1;
			}

			$pagination_html = '<div class="pagination" style="text-align: center; margin: 20px 0; padding: 20px 0;">';

			// 1行目：ページ情報表示
			$pagination_html .= '<div style="margin-bottom: 18px; color: #4b5563; font-size: 14px; font-weight: 500;">';
			$pagination_html .= esc_html( sprintf( __( '%1$d / %2$d ページ（全 %3$d 件）', 'ktpwp' ), $current_page, $total_pages, $total_rows ) );
			$pagination_html .= '</div>';

			// 2行目：ページネーションボタン
			$pagination_html .= '<div style="display: flex; align-items: center; gap: 4px; flex-wrap: wrap; justify-content: center; width: 100%;">';

			// ページネーションボタンのスタイル（正円ボタン）
			$button_style = 'display: inline-block; width: 36px; height: 36px; padding: 0; margin: 0 2px; text-decoration: none; border: 1px solid #ddd; border-radius: 50%; color: #333; background: #fff; transition: all 0.3s ease; box-shadow: 0 1px 3px rgba(0,0,0,0.1); line-height: 34px; text-align: center; vertical-align: middle; font-size: 14px;';
			$current_style = 'background: #1976d2; color: white; border-color: #1976d2; font-weight: bold; transform: translateY(-1px); box-shadow: 0 2px 5px rgba(0,0,0,0.2);';
			$hover_effect = 'onmouseover="this.style.backgroundColor=\'#f5f5f5\'; this.style.transform=\'translateY(-1px)\'; this.style.boxShadow=\'0 2px 5px rgba(0,0,0,0.15)\';" onmouseout="this.style.backgroundColor=\'#fff\'; this.style.transform=\'none\'; this.style.boxShadow=\'0 1px 3px rgba(0,0,0,0.1)\';"';

			// ページネーションのリンクにはprogressを必ず付与
			$add_progress = isset( $_GET['progress'] );

			// 前のページボタン
			if ( $current_page > 1 && $total_pages > 1 ) {
				$prev_args = array(
					'tab_name' => $tab_name,
					'page_start' => ( $current_page - 2 ) * $query_limit,
					'page_stage' => 2,
					'flg' => $flg,
				);
				if ( $add_progress ) {
					$prev_args['progress'] = $selected_progress;
				}
				$prev_url = esc_url( add_query_arg( $prev_args ) );
				$pagination_html .= "<a href=\"{$prev_url}\" style=\"{$button_style}\" {$hover_effect}>‹</a>";
			}

			// ページ番号ボタン（省略表示対応）
			$start_page = max( 1, $current_page - 2 );
			$end_page = min( $total_pages, $current_page + 2 );

			// 最初のページを表示（データが0件でも1ページ目は表示）
			if ( $start_page > 1 && $total_pages > 1 ) {
				$first_args = array(
					'tab_name' => $tab_name,
					'page_start' => 0,
					'page_stage' => 2,
					'flg' => $flg,
				);
				if ( $add_progress ) {
					$first_args['progress'] = $selected_progress;
				}
				$first_url = esc_url( add_query_arg( $first_args ) );
				$pagination_html .= "<a href=\"{$first_url}\" style=\"{$button_style}\" {$hover_effect}>1</a>";

				if ( $start_page > 2 ) {
					$pagination_html .= "<span style=\"{$button_style} background: transparent; border: none; cursor: default;\">...</span>";
				}
			}

			// 中央のページ番号
			for ( $i = $start_page; $i <= $end_page; $i++ ) {
				$page_args = array(
					'tab_name' => $tab_name,
					'page_start' => ( $i - 1 ) * $query_limit,
					'page_stage' => 2,
					'flg' => $flg,
				);
				if ( $add_progress ) {
					$page_args['progress'] = $selected_progress;
				}
				$page_url = esc_url( add_query_arg( $page_args ) );

				if ( $i == $current_page ) {
					$pagination_html .= "<span style=\"{$button_style} {$current_style}\">{$i}</span>";
				} else {
					$pagination_html .= "<a href=\"{$page_url}\" style=\"{$button_style}\" {$hover_effect}>{$i}</a>";
				}
			}

			// 最後のページを表示
			if ( $end_page < $total_pages && $total_pages > 1 ) {
				if ( $end_page < $total_pages - 1 ) {
					$pagination_html .= "<span style=\"{$button_style} background: transparent; border: none; cursor: default;\">...</span>";
				}

				$last_args = array(
					'tab_name' => $tab_name,
					'page_start' => ( $total_pages - 1 ) * $query_limit,
					'page_stage' => 2,
					'flg' => $flg,
				);
				if ( $add_progress ) {
					$last_args['progress'] = $selected_progress;
				}
				$last_url = esc_url( add_query_arg( $last_args ) );
				$pagination_html .= "<a href=\"{$last_url}\" style=\"{$button_style}\" {$hover_effect}>{$total_pages}</a>";
			}

			// 次のページボタン
			if ( $current_page < $total_pages && $total_pages > 1 ) {
				$next_args = array(
					'tab_name' => $tab_name,
					'page_start' => $current_page * $query_limit,
					'page_stage' => 2,
					'flg' => $flg,
				);
				if ( $add_progress ) {
					$next_args['progress'] = $selected_progress;
				}
				$next_url = esc_url( add_query_arg( $next_args ) );
				$pagination_html .= "<a href=\"{$next_url}\" style=\"{$button_style}\" {$hover_effect}>›</a>";
			}

			$pagination_html .= '</div>';
			$pagination_html .= '</div>';

			return $pagination_html;
		}

		/**
		 * 仕事リストの警告・ソート用に、約束納期 → 希望納期の順で有効な納期を返す。
		 *
		 * @param object $order 受注書行。
		 * @return string Y-m-d または空文字。
		 */
		private function resolve_effective_due_date( $order ) {
			$promised = isset( $order->promised_delivery_date ) ? trim( (string) $order->promised_delivery_date ) : '';
			if ( $promised !== '' && $promised !== '0000-00-00' ) {
				return $promised;
			}

			$desired = isset( $order->desired_delivery_date ) ? trim( (string) $order->desired_delivery_date ) : '';
			if ( $desired !== '' && $desired !== '0000-00-00' ) {
				return $desired;
			}

			return '';
		}
	}
} // class_exists
