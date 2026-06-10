<?php
/**
 * 公開商品ショートコードからのお申し込み（案件作成）処理。
 *
 * @package KTPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'KTPWP_Public_Product_Order' ) ) {

	/**
	 * [ktpwp_public_products] からの案件（受付中）作成。
	 */
	class KTPWP_Public_Product_Order {

		/**
		 * @var self|null
		 */
		private static $instance = null;

		/**
		 * @return self
		 */
		public static function get_instance() {
			if ( self::$instance === null ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * コンストラクタ。
		 */
		private function __construct() {
			add_action( 'wp_ajax_ktpwp_public_product_submit', array( $this, 'ajax_submit_order' ) );
			add_action( 'wp_ajax_nopriv_ktpwp_public_product_submit', array( $this, 'ajax_submit_order' ) );
		}

		/**
		 * フロント用 nonce アクション名。
		 *
		 * @return string
		 */
		public static function get_nonce_action() {
			return 'ktpwp_public_product_order';
		}

		/**
		 * 公開商品から案件を作成する。
		 *
		 * @param int   $service_id サービス ID。
		 * @param array $form       フォーム入力。
		 * @return array{success:bool,message:string,order_id?:int}
		 */
		public function submit_order( $service_id, array $form ) {
			$service = $this->get_public_service( $service_id );
			if ( ! $service ) {
				return array(
					'success' => false,
					'message' => __( '指定された商品は公開されていないか、存在しません。', 'ktpwp' ),
				);
			}

			if ( ! $this->check_rate_limit() ) {
				return array(
					'success' => false,
					'message' => __( '送信回数が上限に達しました。しばらくしてから再度お試しください。', 'ktpwp' ),
				);
			}

			$company_name = isset( $form['company_name'] ) ? sanitize_text_field( $form['company_name'] ) : '';
			$contact_name = isset( $form['contact_name'] ) ? sanitize_text_field( $form['contact_name'] ) : '';
			$email        = isset( $form['email'] ) ? sanitize_email( $form['email'] ) : '';
			$phone        = isset( $form['phone'] ) ? sanitize_text_field( $form['phone'] ) : '';
			$message      = isset( $form['message'] ) ? sanitize_textarea_field( $form['message'] ) : '';
			$quantity     = isset( $form['quantity'] ) ? floatval( $form['quantity'] ) : 1;

			if ( $contact_name === '' ) {
				return array(
					'success' => false,
					'message' => __( 'お名前を入力してください。', 'ktpwp' ),
				);
			}

			if ( $email === '' || ! is_email( $email ) ) {
				return array(
					'success' => false,
					'message' => __( '有効なメールアドレスを入力してください。', 'ktpwp' ),
				);
			}

			if ( $quantity < 1 ) {
				$quantity = 1;
			}

			$customer_name = $company_name !== '' ? $company_name : $contact_name;

			$client_id = $this->find_or_create_client(
				array(
					'company_name' => $company_name !== '' ? $company_name : $customer_name,
					'name'         => $contact_name,
					'email'        => $email,
					'phone'        => $phone,
					'message'      => $message,
					'service_name' => isset( $service->service_name ) ? (string) $service->service_name : '',
				)
			);

			if ( ! $client_id ) {
				return array(
					'success' => false,
					'message' => __( 'お客様情報の保存に失敗しました。', 'ktpwp' ),
				);
			}

			$service_name = isset( $service->service_name ) ? sanitize_text_field( (string) $service->service_name ) : '';
			$project_name = $service_name !== ''
				? sprintf(
					/* translators: %s: service name */
					__( '%s（Webお申込み）', 'ktpwp' ),
					$service_name
				)
				: __( 'Webお申込み', 'ktpwp' );

			$memo_parts = array();
			if ( $message !== '' ) {
				$memo_parts[] = __( 'ご要望:', 'ktpwp' ) . ' ' . $message;
			}
			if ( $phone !== '' ) {
				$memo_parts[] = __( '電話:', 'ktpwp' ) . ' ' . $phone;
			}
			$memo_parts[] = __( '公開商品ID:', 'ktpwp' ) . ' ' . (int) $service->id;
			$memo = implode( "\n", $memo_parts );

			$search_field = trim( implode( ' ', array_filter( array( $customer_name, $contact_name, $service_name, $email ) ) ) );

			$order_id = $this->insert_order(
				array(
					'client_id'     => $client_id,
					'customer_name' => $customer_name,
					'user_name'     => $contact_name,
					'project_name'  => $project_name,
					'progress'      => 1,
					'memo'          => $memo,
					'search_field'  => $search_field,
					'time'          => time(),
				)
			);

			if ( ! $order_id ) {
				return array(
					'success' => false,
					'message' => __( '案件の作成に失敗しました。', 'ktpwp' ),
				);
			}

			$this->save_external_source( $order_id, (int) $service->id );

			if ( ! class_exists( 'KTPWP_Order_Items' ) ) {
				require_once dirname( __FILE__ ) . '/class-ktpwp-order-items.php';
			}

			$order_items = KTPWP_Order_Items::get_instance();
			$price       = isset( $service->price ) ? floatval( $service->price ) : 0;
			$unit        = isset( $service->unit ) ? sanitize_text_field( (string) $service->unit ) : '';
			$tax_rate    = null;
			if ( isset( $service->tax_rate ) && $service->tax_rate !== null && $service->tax_rate !== '' && is_numeric( $service->tax_rate ) ) {
				$tax_rate = floatval( $service->tax_rate );
			}

			$invoice_saved = $order_items->save_invoice_items(
				$order_id,
				array(
					array(
						'id'           => 0,
						'product_name' => $service_name,
						'price'        => $price,
						'quantity'     => $quantity,
						'unit'         => $unit !== '' ? $unit : __( '式', 'ktpwp' ),
						'amount'       => $price * $quantity,
						'tax_rate'     => $tax_rate,
						'remarks'      => '',
					),
				)
			);

			if ( ! $invoice_saved ) {
				error_log( 'KTPWP Public Product: Failed to save invoice items for order ' . $order_id );
			}

			if ( method_exists( $order_items, 'create_initial_cost_item' ) ) {
				$order_items->create_initial_cost_item( $order_id );
			}

			if ( class_exists( 'KTPWP_Staff_Chat' ) ) {
				$staff_chat = KTPWP_Staff_Chat::get_instance();
				if ( method_exists( $staff_chat, 'create_initial_chat' ) ) {
					$staff_chat->create_initial_chat( $order_id, null );
				}
			}

			$this->increment_rate_limit();

			return array(
				'success'  => true,
				'message'  => __( 'お申し込みを受け付けました。担当者よりご連絡いたします。', 'ktpwp' ),
				'order_id' => (int) $order_id,
			);
		}

		/**
		 * AJAX: お申し込み送信。
		 *
		 * @return void
		 */
		public function ajax_submit_order() {
			check_ajax_referer( self::get_nonce_action(), 'nonce' );

			if ( ! empty( $_POST['company_url'] ) ) {
				wp_send_json_error(
					array( 'message' => __( '送信に失敗しました。', 'ktpwp' ) ),
					400
				);
			}

			$service_id = isset( $_POST['service_id'] ) ? absint( $_POST['service_id'] ) : 0;
			$form       = array(
				'company_name' => isset( $_POST['company_name'] ) ? wp_unslash( $_POST['company_name'] ) : '',
				'contact_name' => isset( $_POST['contact_name'] ) ? wp_unslash( $_POST['contact_name'] ) : '',
				'email'        => isset( $_POST['email'] ) ? wp_unslash( $_POST['email'] ) : '',
				'phone'        => isset( $_POST['phone'] ) ? wp_unslash( $_POST['phone'] ) : '',
				'message'      => isset( $_POST['message'] ) ? wp_unslash( $_POST['message'] ) : '',
				'quantity'     => isset( $_POST['quantity'] ) ? wp_unslash( $_POST['quantity'] ) : 1,
			);

			$result = $this->submit_order( $service_id, $form );

			if ( empty( $result['success'] ) ) {
				wp_send_json_error(
					array( 'message' => $result['message'] ),
					400
				);
			}

			wp_send_json_success(
				array(
					'message'  => $result['message'],
					'order_id' => isset( $result['order_id'] ) ? (int) $result['order_id'] : 0,
				)
			);
		}

		/**
		 * 公開中のサービスを取得する。
		 *
		 * @param int $service_id サービス ID。
		 * @return object|null
		 */
		public function get_public_service( $service_id ) {
			if ( ! class_exists( 'KTPWP_Service_DB' ) ) {
				require_once dirname( __FILE__ ) . '/class-ktpwp-service-db.php';
			}

			return KTPWP_Service_DB::get_instance()->get_public_service_by_id( $service_id );
		}

		/**
		 * 顧客を検索または新規作成する。
		 *
		 * @param array $data 顧客データ。
		 * @return int|false
		 */
		private function find_or_create_client( array $data ) {
			global $wpdb;

			$table_name = $wpdb->prefix . 'ktp_client';
			$email      = isset( $data['email'] ) ? sanitize_email( $data['email'] ) : '';

			if ( $email !== '' ) {
				$existing_id = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT id FROM {$table_name} WHERE email = %s ORDER BY id DESC LIMIT 1",
						$email
					)
				);
				if ( $existing_id ) {
					return (int) $existing_id;
				}
			}

			$memo_parts = array();
			if ( ! empty( $data['message'] ) ) {
				$memo_parts[] = __( 'ご要望:', 'ktpwp' ) . ' ' . sanitize_textarea_field( $data['message'] );
			}
			if ( ! empty( $data['phone'] ) ) {
				$memo_parts[] = __( '電話:', 'ktpwp' ) . ' ' . sanitize_text_field( $data['phone'] );
			}
			if ( ! empty( $data['service_name'] ) ) {
				$memo_parts[] = __( '初回お申込商品:', 'ktpwp' ) . ' ' . sanitize_text_field( $data['service_name'] );
			}

			$client_data = array(
				'company_name'  => isset( $data['company_name'] ) ? sanitize_text_field( $data['company_name'] ) : '',
				'name'          => isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '',
				'email'         => $email,
				'memo'          => implode( "\n", $memo_parts ),
				'time'          => current_time( 'mysql' ),
				'client_status' => __( '対象', 'ktpwp' ),
			);

			$result = $wpdb->insert(
				$table_name,
				$client_data,
				array( '%s', '%s', '%s', '%s', '%s', '%s' )
			);

			if ( $result === false ) {
				error_log( 'KTPWP Public Product: Failed to insert client - ' . $wpdb->last_error );
				return false;
			}

			return (int) $wpdb->insert_id;
		}

		/**
		 * 受注データを挿入する（Contact Form 7 連携と同形式）。
		 *
		 * @param array $order_data 受注データ。
		 * @return int|false
		 */
		private function insert_order( array $order_data ) {
			global $wpdb;

			$table_name = $wpdb->prefix . 'ktp_order';
			$timestamp  = isset( $order_data['time'] ) ? (int) $order_data['time'] : time();
			$today      = date( 'Y-md', $timestamp );
			$prefix     = $today . '-';
			$today_count = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM `{$table_name}` WHERE order_number LIKE %s",
					$prefix . '%'
				)
			);
			$order_number = $prefix . str_pad( (string) ( (int) $today_count + 1 ), 3, '0', STR_PAD_LEFT );

			$insert_data = array(
				'order_number'  => $order_number,
				'client_id'     => (int) $order_data['client_id'],
				'customer_name' => sanitize_text_field( $order_data['customer_name'] ),
				'user_name'     => sanitize_text_field( $order_data['user_name'] ),
				'project_name'  => sanitize_text_field( $order_data['project_name'] ),
				'progress'      => (int) $order_data['progress'],
				'time'          => $timestamp,
				'created_at'    => current_time( 'mysql' ),
				'updated_at'    => current_time( 'mysql' ),
			);

			if ( isset( $order_data['memo'] ) ) {
				$insert_data['memo'] = sanitize_textarea_field( $order_data['memo'] );
			}
			if ( isset( $order_data['search_field'] ) ) {
				$insert_data['search_field'] = sanitize_textarea_field( $order_data['search_field'] );
			}

			$format = array();
			foreach ( array_keys( $insert_data ) as $key ) {
				if ( in_array( $key, array( 'client_id', 'progress', 'time' ), true ) ) {
					$format[] = '%d';
				} else {
					$format[] = '%s';
				}
			}

			$result = $wpdb->insert( $table_name, $insert_data, $format );
			if ( $result === false ) {
				error_log( 'KTPWP Public Product: Failed to insert order - ' . $wpdb->last_error );
				return false;
			}

			return (int) $wpdb->insert_id;
		}

		/**
		 * external_source カラムがあれば連携元を記録する。
		 *
		 * @param int $order_id   案件 ID。
		 * @param int $service_id サービス ID。
		 * @return void
		 */
		private function save_external_source( $order_id, $service_id ) {
			global $wpdb;

			$table = $wpdb->prefix . 'ktp_order';
			$cols  = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`", 0 );
			$cols  = is_array( $cols ) ? $cols : array();
			$update = array();

			if ( in_array( 'external_source', $cols, true ) ) {
				$update['external_source'] = 'public_product';
			}
			if ( in_array( 'external_order_id', $cols, true ) ) {
				$update['external_order_id'] = (string) $service_id;
			}

			if ( empty( $update ) ) {
				return;
			}

			$wpdb->update(
				$table,
				$update,
				array( 'id' => (int) $order_id ),
				array_fill( 0, count( $update ), '%s' ),
				array( '%d' )
			);
		}

		/**
		 * 同一 IP の送信回数制限（1時間あたり 10 件）。
		 *
		 * @return bool
		 */
		private function check_rate_limit() {
			$key   = $this->get_rate_limit_key();
			$count = (int) get_transient( $key );
			return $count < 10;
		}

		/**
		 * 送信回数を加算する。
		 *
		 * @return void
		 */
		private function increment_rate_limit() {
			$key   = $this->get_rate_limit_key();
			$count = (int) get_transient( $key );
			set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		}

		/**
		 * レート制限用 transient キー。
		 *
		 * @return string
		 */
		private function get_rate_limit_key() {
			$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
			return 'ktpwp_pp_order_' . md5( $ip );
		}
	}
}
