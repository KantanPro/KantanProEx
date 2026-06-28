<?php
/**
 * 受注書複製（KantanBiz orders.duplicate 相当）
 *
 * @package KTPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'KTPWP_Order_Duplicate' ) ) {

	/**
	 * 受注書の複製処理と UI 断片。
	 */
	class KTPWP_Order_Duplicate {

		/**
		 * @return self
		 */
		public static function get_instance() {
			static $instance = null;
			if ( null === $instance ) {
				$instance = new self();
			}
			return $instance;
		}

		/**
		 * 複製先候補の顧客一覧（対象外を除く）。
		 *
		 * @return array<int, object>
		 */
		public function get_client_options() {
			global $wpdb;
			$table = $wpdb->prefix . 'ktp_client';

			$rows = $wpdb->get_results(
				"SELECT id, company_name, name FROM `{$table}`
				WHERE COALESCE(client_status, '対象') NOT IN ('対象外', 'Inactive')
				ORDER BY company_name ASC, id ASC"
			);

			return is_array( $rows ) ? $rows : array();
		}

		/**
		 * 複製ボタン HTML。
		 *
		 * @param array<string, mixed> $args order_id, client_id, disabled.
		 * @return string
		 */
		public function render_button( $args = array() ) {
			$args = wp_parse_args(
				$args,
				array(
					'order_id'  => 0,
					'client_id' => 0,
					'disabled'  => false,
				)
			);

			if ( ! class_exists( 'KTPWP_Ui_Generator' ) ) {
				return '';
			}

			return KTPWP_Ui_Generator::render_tab_print_button(
				array(
					'id'       => 'orderDuplicateButton',
					'label'    => __( '複製', 'ktpwp' ),
					'title'    => __( '受注書を複製', 'ktpwp' ),
					'class'    => 'ktp-order-duplicate-btn',
					'disabled' => ! empty( $args['disabled'] ) || empty( $args['order_id'] ),
					'attrs'    => array(
						'data-order-id'  => (string) absint( $args['order_id'] ),
						'data-client-id' => (string) absint( $args['client_id'] ),
					),
				)
			);
		}

		/**
		 * 複製モーダル HTML（1ページに1つ）。
		 *
		 * @param object|null $order_data 表示中の受注書。
		 * @return string
		 */
		public function render_modal( $order_data ) {
			if ( ! $order_data || empty( $order_data->id ) ) {
				return '';
			}

			$clients      = $this->get_client_options();
			$current_id   = (int) ( $order_data->client_id ?? 0 );
			$submit_label = __( '複製する', 'ktpwp' );
			$cancel_label = __( 'キャンセル', 'ktpwp' );

			$html  = '<div id="ktp-order-duplicate-modal" class="ktp-order-duplicate-modal" hidden aria-hidden="true">';
			$html .= '<div class="ktp-order-duplicate-modal__backdrop" data-ktp-order-duplicate-close></div>';
			$html .= '<div class="ktp-order-duplicate-modal__panel" role="dialog" aria-modal="true" aria-labelledby="ktp-order-duplicate-title">';
			$html .= '<h3 id="ktp-order-duplicate-title" class="ktp-order-duplicate-modal__title">' . esc_html__( '受注書を複製', 'ktpwp' ) . '</h3>';
			$html .= '<p class="ktp-order-duplicate-modal__lead">' . esc_html__( '複製先の顧客を選んでください。', 'ktpwp' ) . '</p>';
			$html .= '<fieldset class="ktp-order-duplicate-modal__mode">';
			$html .= '<legend class="screen-reader-text">' . esc_html__( '複製時の顧客', 'ktpwp' ) . '</legend>';
			$html .= '<label class="ktp-order-duplicate-modal__radio"><input type="radio" name="ktp-order-duplicate-client-mode" value="same" checked> ' . esc_html__( 'この受注と同じ顧客のまま複製する', 'ktpwp' ) . '</label>';
			$html .= '<label class="ktp-order-duplicate-modal__radio"><input type="radio" name="ktp-order-duplicate-client-mode" value="other"> ' . esc_html__( '別の顧客を選んで複製する', 'ktpwp' ) . '</label>';
			$html .= '</fieldset>';
			$html .= '<div class="ktp-order-duplicate-modal__client-wrap" hidden>';
			$html .= '<label for="ktp-order-duplicate-target-client" class="ktp-order-duplicate-modal__client-label">' . esc_html__( '複製先の顧客', 'ktpwp' ) . '</label>';
			$html .= '<select id="ktp-order-duplicate-target-client" class="ktp-order-duplicate-modal__client-select">';
			$html .= '<option value="">' . esc_html__( '選択してください', 'ktpwp' ) . '</option>';
			foreach ( $clients as $client ) {
				$label = trim( (string) ( $client->company_name ?? '' ) );
				if ( $label === '' ) {
					$label = trim( (string) ( $client->name ?? '' ) );
				}
				if ( $label === '' ) {
					$label = 'ID:' . (int) $client->id;
				}
				$html .= '<option value="' . esc_attr( (string) (int) $client->id ) . '"' . selected( (int) $client->id, $current_id, false ) . '>' . esc_html( $label ) . '</option>';
			}
			$html .= '</select></div>';
			$html .= '<div class="ktp-order-duplicate-modal__actions">';
			$html .= '<button type="button" class="ktp-tab-print-btn ktp-order-duplicate-modal__cancel" data-ktp-order-duplicate-close>' . esc_html( $cancel_label ) . '</button>';
			$html .= '<button type="button" class="ktp-tab-print-btn ktp-order-duplicate-modal__submit" id="ktp-order-duplicate-submit">' . esc_html( $submit_label ) . '</button>';
			$html .= '</div></div></div>';

			return $html;
		}

		/**
		 * 受注書を複製する。
		 *
		 * @param int    $source_order_id 複製元 ID。
		 * @param string $client_mode     same|other。
		 * @param int    $target_client_id other 時の顧客 ID。
		 * @return int|WP_Error 新規受注書 ID。
		 */
		public function duplicate_order( $source_order_id, $client_mode, $target_client_id = 0 ) {
			global $wpdb;

			$source_order_id = absint( $source_order_id );
			if ( $source_order_id <= 0 ) {
				return new WP_Error( 'invalid_order', __( '複製元の受注書 ID が不正です。', 'ktpwp' ) );
			}

			$order_table  = $wpdb->prefix . 'ktp_order';
			$client_table = $wpdb->prefix . 'ktp_client';
			$source       = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$order_table}` WHERE id = %d", $source_order_id ) );
			if ( ! $source ) {
				return new WP_Error( 'not_found', __( '複製元の受注書が見つかりません。', 'ktpwp' ) );
			}

			$client_mode = ( $client_mode === 'other' ) ? 'other' : 'same';
			$client_id   = ( $client_mode === 'same' )
				? (int) ( $source->client_id ?? 0 )
				: absint( $target_client_id );

			if ( $client_id <= 0 ) {
				return new WP_Error( 'invalid_client', __( '複製先の顧客を選んでください。', 'ktpwp' ) );
			}

			$client = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$client_table}` WHERE id = %d", $client_id ) );
			if ( ! $client ) {
				return new WP_Error( 'client_not_found', __( '複製先の顧客が見つかりません。', 'ktpwp' ) );
			}

			$client_status = (string) ( $client->client_status ?? '対象' );
			if ( $client_status === '対象外' || $client_status === 'Inactive' ) {
				return new WP_Error( 'client_excluded', __( '対象外の顧客には複製できません。', 'ktpwp' ) );
			}

			$timestamp           = time();
			$order_number_prefix = date( 'Y-md', $timestamp ) . '-';
			$today_count         = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM `{$order_table}` WHERE order_number LIKE %s",
					$order_number_prefix . '%'
				)
			);
			$order_number = $order_number_prefix . str_pad( (string) ( $today_count + 1 ), 3, '0', STR_PAD_LEFT );

			$customer_name = trim( (string) ( $client->company_name ?? '' ) );
			$user_name     = trim( (string) ( $client->name ?? '' ) );
			if ( $customer_name === '' ) {
				$customer_name = $user_name;
			}

			$insert_data = array(
				'order_number'   => $order_number,
				'time'           => $timestamp,
				'client_id'      => $client_id,
				'customer_name'  => sanitize_text_field( $customer_name ),
				'user_name'      => sanitize_text_field( $user_name ),
				'project_name'   => sanitize_text_field( (string) ( $source->project_name ?? '' ) ),
				'progress'       => 1,
				'invoice_items'  => '',
				'cost_items'     => '',
				'memo'           => sanitize_textarea_field( (string) ( $source->memo ?? '' ) ),
				'search_field'   => sanitize_text_field( trim( $customer_name . ', ' . $user_name, ', ' ) ),
			);
			$insert_formats = array( '%s', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' );

			$order_cols = $wpdb->get_col( "SHOW COLUMNS FROM `{$order_table}`", 0 );
			if ( is_array( $order_cols ) ) {
				if ( in_array( 'payment_timing', $order_cols, true ) && isset( $source->payment_timing ) ) {
					$insert_data['payment_timing'] = $source->payment_timing;
					$insert_formats[]              = '%s';
				}
				if ( in_array( 'client_department_id', $order_cols, true ) ) {
					$dept_id = null;
					if ( $client_mode === 'same' && ! empty( $source->client_department_id ) ) {
						$dept_id = (int) $source->client_department_id;
					} elseif ( class_exists( 'KTPWP_Department_Manager' ) ) {
						$selected = KTPWP_Department_Manager::get_selected_department_by_client( $client_id );
						if ( $selected ) {
							$dept_id = (int) $selected->id;
						}
					}
					$insert_data['client_department_id'] = $dept_id;
					$insert_formats[]                    = $dept_id === null ? '%s' : '%d';
				}
				foreach ( array( 'completion_date', 'desired_delivery_date', 'expected_delivery_date', 'promised_delivery_date' ) as $date_col ) {
					if ( in_array( $date_col, $order_cols, true ) ) {
						$insert_data[ $date_col ] = null;
						$insert_formats[]         = '%s';
					}
				}
				foreach ( array( 'contract_id' ) as $int_col ) {
					if ( in_array( $int_col, $order_cols, true ) ) {
						$insert_data[ $int_col ] = 0;
						$insert_formats[]        = '%d';
					}
				}
				foreach ( array( 'billing_period', 'external_source', 'external_order_id', 'stripe_subscription_id', 'stripe_checkout_session_id' ) as $empty_col ) {
					if ( in_array( $empty_col, $order_cols, true ) ) {
						$insert_data[ $empty_col ] = '';
						$insert_formats[]          = '%s';
					}
				}
			}

			$wpdb->query( 'START TRANSACTION' );
			try {
				$inserted = $wpdb->insert( $order_table, $insert_data, $insert_formats );
				if ( ! $inserted ) {
					throw new Exception( $wpdb->last_error ?: 'insert failed' );
				}

				$new_order_id = (int) $wpdb->insert_id;
				$this->copy_invoice_items( $source_order_id, $new_order_id );
				$this->copy_cost_items( $source_order_id, $new_order_id );

				if ( class_exists( 'KTPWP_Order_Items' ) ) {
					$items_manager = KTPWP_Order_Items::get_instance();
					if ( empty( $items_manager->get_invoice_items( $new_order_id ) ) ) {
						$items_manager->create_initial_invoice_item( $new_order_id );
					}
					if ( empty( $items_manager->get_cost_items( $new_order_id ) ) ) {
						$items_manager->create_initial_cost_item( $new_order_id );
					}
				}

				$wpdb->query( 'COMMIT' );
				return $new_order_id;
			} catch ( Exception $e ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'duplicate_failed', __( '受注書の複製に失敗しました。', 'ktpwp' ) );
			}
		}

		/**
		 * @param int $source_order_id 複製元。
		 * @param int $new_order_id    複製先。
		 */
		private function copy_invoice_items( $source_order_id, $new_order_id ) {
			if ( ! class_exists( 'KTPWP_Order_Items' ) ) {
				return;
			}
			$items_manager = KTPWP_Order_Items::get_instance();
			$items         = $items_manager->get_invoice_items( $source_order_id );
			if ( empty( $items ) ) {
				return;
			}

			global $wpdb;
			$table = $wpdb->prefix . 'ktp_order_invoice_items';
			$items_manager->ensure_invoice_items_table();
			$has_provisional = $this->table_has_column( $table, 'is_provisional' );
			$sort            = 1;

			foreach ( $items as $item ) {
				$product_name = trim( (string) ( $item['product_name'] ?? '' ) );
				if ( $product_name === '' ) {
					continue;
				}
				$row = array(
					'order_id'     => $new_order_id,
					'product_name' => sanitize_text_field( $product_name ),
					'price'        => isset( $item['price'] ) ? floatval( $item['price'] ) : 0,
					'unit'         => sanitize_text_field( (string) ( $item['unit'] ?? '' ) ),
					'quantity'     => isset( $item['quantity'] ) ? floatval( $item['quantity'] ) : 0,
					'amount'       => isset( $item['amount'] ) ? floatval( $item['amount'] ) : 0,
					'remarks'      => sanitize_text_field( (string) ( $item['remarks'] ?? '' ) ),
					'sort_order'   => isset( $item['sort_order'] ) ? (int) $item['sort_order'] : $sort,
				);
				$formats = array( '%d', '%s', '%f', '%s', '%f', '%f', '%s', '%d' );
				if ( array_key_exists( 'tax_rate', $item ) && $item['tax_rate'] !== null && $item['tax_rate'] !== '' ) {
					$row['tax_rate'] = floatval( $item['tax_rate'] );
					$formats[]       = '%f';
				} else {
					$row['tax_rate'] = null;
					$formats[]       = '%s';
				}
				if ( $has_provisional ) {
					$row['is_provisional'] = ! empty( $item['is_provisional'] ) ? 1 : 0;
					$formats[]             = '%d';
				}
				$wpdb->insert( $table, $row, $formats );
				++$sort;
			}
		}

		/**
		 * @param int $source_order_id 複製元。
		 * @param int $new_order_id    複製先。
		 */
		private function copy_cost_items( $source_order_id, $new_order_id ) {
			if ( ! class_exists( 'KTPWP_Order_Items' ) ) {
				return;
			}
			$items_manager = KTPWP_Order_Items::get_instance();
			$items         = $items_manager->get_cost_items( $source_order_id );
			if ( empty( $items ) ) {
				return;
			}

			global $wpdb;
			$table = $wpdb->prefix . 'ktp_order_cost_items';
			$items_manager->add_supplier_id_column_if_missing();
			$has_supplier = $this->table_has_column( $table, 'supplier_id' );
			$sort         = 1;

			foreach ( $items as $item ) {
				$product_name = trim( (string) ( $item['product_name'] ?? '' ) );
				if ( $product_name === '' ) {
					continue;
				}
				$row = array(
					'order_id'     => $new_order_id,
					'product_name' => sanitize_text_field( $product_name ),
					'price'        => isset( $item['price'] ) ? floatval( $item['price'] ) : 0,
					'unit'         => sanitize_text_field( (string) ( $item['unit'] ?? '' ) ),
					'quantity'     => isset( $item['quantity'] ) ? floatval( $item['quantity'] ) : 0,
					'amount'       => isset( $item['amount'] ) ? floatval( $item['amount'] ) : 0,
					'remarks'      => sanitize_text_field( (string) ( $item['remarks'] ?? '' ) ),
					'purchase'     => sanitize_text_field( (string) ( $item['purchase'] ?? '' ) ),
					'qualified_invoice_number' => sanitize_text_field( (string) ( $item['qualified_invoice_number'] ?? '' ) ),
					'ordered'      => ! empty( $item['ordered'] ) ? 1 : 0,
					'sort_order'   => isset( $item['sort_order'] ) ? (int) $item['sort_order'] : $sort,
				);
				$formats = array( '%d', '%s', '%f', '%s', '%f', '%f', '%s', '%s', '%s', '%d', '%d' );
				if ( array_key_exists( 'tax_rate', $item ) && $item['tax_rate'] !== null && $item['tax_rate'] !== '' ) {
					$row['tax_rate'] = floatval( $item['tax_rate'] );
				} else {
					$row['tax_rate'] = 10.0;
				}
				$formats[] = '%f';
				if ( $has_supplier ) {
					$row['supplier_id'] = isset( $item['supplier_id'] ) && $item['supplier_id'] ? (int) $item['supplier_id'] : null;
					$formats[]          = $row['supplier_id'] === null ? '%s' : '%d';
				}
				$wpdb->insert( $table, $row, $formats );
				++$sort;
			}
		}

		/**
		 * @param string $table  テーブル名。
		 * @param string $column カラム名。
		 * @return bool
		 */
		private function table_has_column( $table, $column ) {
			global $wpdb;
			$found = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", $column ) );
			return ! empty( $found );
		}
	}
}
