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

			$resolved = $this->find_or_create_client(
				array(
					'company_name' => $company_name !== '' ? $company_name : $customer_name,
					'name'         => $contact_name,
					'email'        => $email,
					'phone'        => $phone,
					'message'      => $message,
					'service_name' => isset( $service->service_name ) ? (string) $service->service_name : '',
				)
			);

			$client_id     = is_array( $resolved ) ? (int) ( $resolved['client_id'] ?? 0 ) : 0;
			$department_id = is_array( $resolved ) ? ( $resolved['department_id'] ?? null ) : null;
			$department_id = $department_id ? (int) $department_id : null;

			if ( $client_id <= 0 ) {
				return array(
					'success' => false,
					'message' => __( 'お客様情報の保存に失敗しました。', 'ktpwp' ),
				);
			}

			$service_name = isset( $service->service_name ) ? sanitize_text_field( (string) $service->service_name ) : '';
			$project_name = $service_name;

			$memo = $this->build_order_memo( $message, (int) $service->id, $service_name );

			$search_field = trim( implode( ' ', array_filter( array( $customer_name, $contact_name, $service_name, $email ) ) ) );

			$order_id = $this->insert_order(
				array(
					'client_id'            => $client_id,
					'client_department_id' => $department_id,
					'customer_name'        => $customer_name,
					'user_name'            => $contact_name,
					'project_name'         => $project_name,
					'progress'             => 1,
					'memo'                 => $memo,
					'search_field'         => $search_field,
					'time'                 => time(),
				)
			);

			if ( ! $order_id ) {
				return array(
					'success' => false,
					'message' => __( '案件の作成に失敗しました。', 'ktpwp' ),
				);
			}

			$this->save_external_source( $order_id, (int) $service->id );

			try {
				if ( ! class_exists( 'KTPWP_Order_Items' ) ) {
					require_once dirname( __FILE__ ) . '/class-ktpwp-order-items.php';
				}

				$order_items = KTPWP_Order_Items::get_instance();

				$invoice_saved = false;
				if ( method_exists( $order_items, 'insert_invoice_item_from_public_product' ) ) {
					$invoice_saved = $order_items->insert_invoice_item_from_public_product( $order_id, $service, $quantity );
				}

				if ( ! $invoice_saved ) {
					error_log( 'KTPWP Public Product: Failed to save invoice items for order ' . $order_id );
				}

				if ( method_exists( $order_items, 'create_initial_cost_item' ) ) {
					$order_items->create_initial_cost_item( $order_id );
				}

				if ( class_exists( 'KTPWP_Staff_Chat' ) ) {
					$staff_chat = KTPWP_Staff_Chat::get_instance();
					if ( method_exists( $staff_chat, 'create_inbound_initial_chat' ) ) {
						$staff_chat->create_inbound_initial_chat(
							$order_id,
							KTPWP_Order_Admin_Notification::SOURCE_PUBLIC_PRODUCT
						);
					}
				}
			} catch ( Throwable $e ) {
				error_log( 'KTPWP Public Product: Post-order setup failed for order ' . $order_id . ' - ' . $e->getMessage() );
			}

			if ( class_exists( 'KTPWP_Order_Admin_Notification' ) ) {
				KTPWP_Order_Admin_Notification::get_instance()->notify_new_order(
					$order_id,
					KTPWP_Order_Admin_Notification::SOURCE_PUBLIC_PRODUCT,
					array(
						'client_email'  => $email,
						'service_name'  => $service_name,
					)
				);
			}

			return array(
				'success'  => true,
				'message'  => __( 'お問い合わせを受け付けました。担当者よりご連絡いたします。', 'ktpwp' ),
				'order_id' => (int) $order_id,
			);
		}

		/**
		 * AJAX: お申し込み送信。
		 *
		 * @return void
		 */
		public function ajax_submit_order() {
			$this->prepare_ajax_json_response();

			check_ajax_referer( self::get_nonce_action(), 'nonce' );

			if ( ! empty( $_POST['company_url'] ) ) {
				$this->send_ajax_json_error(
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
				$this->send_ajax_json_error(
					array( 'message' => $result['message'] ),
					400
				);
			}

			$this->send_ajax_json_success(
				array(
					'message'  => $result['message'],
					'order_id' => isset( $result['order_id'] ) ? (int) $result['order_id'] : 0,
				)
			);
		}

		/**
		 * AJAX 応答前に出力バッファをクリアする（JSON 破損防止）。
		 *
		 * @return void
		 */
		private function prepare_ajax_json_response() {
			nocache_headers();

			while ( ob_get_level() > 0 ) {
				ob_end_clean();
			}
		}

		/**
		 * @param array<string, mixed> $data レスポンス data。
		 * @return void
		 */
		private function send_ajax_json_success( array $data ) {
			while ( ob_get_level() > 0 ) {
				ob_end_clean();
			}

			wp_send_json_success( $data );
		}

		/**
		 * @param array<string, mixed> $data   エラー data。
		 * @param int                  $status HTTP ステータス。
		 * @return void
		 */
		private function send_ajax_json_error( array $data, $status = 400 ) {
			while ( ob_get_level() > 0 ) {
				ob_end_clean();
			}

			wp_send_json_error( $data, $status );
		}

		/**
		 * 受注書メモ欄用テキストを組み立てる。
		 *
		 * 形式: {メモ} 商品ID: {ID} {商品名}（Webお申込み）
		 *
		 * @param string $message      フォームのご要望・備考。
		 * @param int    $service_id   公開商品 ID。
		 * @param string $service_name 商品名。
		 * @return string
		 */
		private function build_order_memo( $message, $service_id, $service_name ) {
			$message      = trim( (string) $message );
			$service_name = trim( (string) $service_name );
			$service_id   = (int) $service_id;

			$product_suffix = sprintf(
				/* translators: 1: product ID, 2: product name */
				__( '商品ID: %1$d %2$s', 'ktpwp' ),
				$service_id,
				$service_name
			);
			$product_suffix = trim( $product_suffix );
			$web_suffix     = __( '（Webお申込み）', 'ktpwp' );

			$memo = $message === '' ? $product_suffix : $message . ' ' . $product_suffix;

			return trim( $memo . ' ' . $web_suffix );
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
		 * @return array{client_id: int, department_id: int|null}|false
		 */
		private function find_or_create_client( array $data ) {
			global $wpdb;

			$table_name = $wpdb->prefix . 'ktp_client';
			$email      = isset( $data['email'] ) ? sanitize_email( $data['email'] ) : '';
			$company_name = isset( $data['company_name'] ) ? sanitize_text_field( $data['company_name'] ) : '';
			$contact_name = isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '';
			$department_id = null;

			if ( $email !== '' ) {
				$existing = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT * FROM {$table_name} WHERE email = %s ORDER BY id DESC LIMIT 1",
						$email
					)
				);
				if ( $existing ) {
					$client_id = (int) $existing->id;

					if ( ! $this->identity_matches_client( $existing, $company_name, $contact_name ) ) {
						$department_id = $this->find_or_create_department_for_client( $client_id, $company_name, $contact_name, $email );
						$department_id = $department_id ? (int) $department_id : null;
					}

					return array(
						'client_id'     => $client_id,
						'department_id' => $department_id,
					);
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

			return array(
				'client_id'     => (int) $wpdb->insert_id,
				'department_id' => null,
			);
		}

		/**
		 * 既存顧客とフォーム入力の会社名・担当者名が一致するか。
		 *
		 * @param object $client       ktp_client 行。
		 * @param string $company_name 会社名。
		 * @param string $contact_name 担当者名。
		 * @return bool
		 */
		private function identity_matches_client( $client, $company_name, $contact_name ) {
			$existing_company = isset( $client->company_name ) ? (string) $client->company_name : '';
			$existing_contact = '';
			if ( isset( $client->representative_name ) && trim( (string) $client->representative_name ) !== '' ) {
				$existing_contact = (string) $client->representative_name;
			} elseif ( isset( $client->name ) ) {
				$existing_contact = (string) $client->name;
			}

			return $this->normalized_equal( $company_name, $existing_company )
				&& $this->normalized_equal( $contact_name, $existing_contact );
		}

		/**
		 * @param string $a 比較文字列 A。
		 * @param string $b 比較文字列 B。
		 * @return bool
		 */
		private function normalized_equal( $a, $b ) {
			return mb_strtolower( trim( (string) $a ) ) === mb_strtolower( trim( (string) $b ) );
		}

		/**
		 * 同一メール・別名義の問い合わせ用に部署を登録し、受注の宛先部署として選択する。
		 *
		 * @param int    $client_id    顧客 ID。
		 * @param string $company_name フォームの会社名。
		 * @param string $contact_name 担当者名。
		 * @param string $email        メールアドレス。
		 * @return int|false 部署 ID。失敗時 false。
		 */
		private function find_or_create_department_for_client( $client_id, $company_name, $contact_name, $email ) {
			$client_id    = (int) $client_id;
			$company_name = sanitize_text_field( (string) $company_name );
			$contact_name = sanitize_text_field( (string) $contact_name );
			$email        = sanitize_email( (string) $email );

			if ( $client_id <= 0 || $contact_name === '' || $email === '' ) {
				return false;
			}

			if ( ! class_exists( 'KTPWP_Department_Manager' ) ) {
				require_once dirname( __FILE__ ) . '/class-ktpwp-department-manager.php';
			}

			if ( ! KTPWP_Department_Manager::table_exists() && function_exists( 'ktpwp_create_department_table' ) ) {
				ktpwp_create_department_table();
			}

			$department_name = KTPWP_Department_Manager::build_inquiry_department_name( $company_name, $contact_name );

			$departments = KTPWP_Department_Manager::get_departments_by_client( $client_id );
			foreach ( $departments as $department ) {
				if (
					$this->normalized_equal( (string) ( $department->department_name ?? '' ), $department_name )
					&& $this->normalized_equal( (string) ( $department->contact_person ?? '' ), $contact_name )
				) {
					return (int) $department->id;
				}
			}

			$department_id = KTPWP_Department_Manager::add_department(
				$client_id,
				$department_name,
				$contact_name,
				$email
			);

			if ( ! $department_id ) {
				error_log( 'KTPWP Public Product: Failed to create department for client ' . $client_id );
				return false;
			}

			return (int) $department_id;
		}

		/**
		 * 受注データを挿入する（Contact Form 7 連携と同形式）。
		 *
		 * @param array $order_data 受注データ。
		 * @return int|false
		 */
		/**
		 * 日次連番の受注番号を採番する（欠番がある場合は最大値+1）。
		 *
		 * @param int $timestamp Unix タイムスタンプ。
		 * @return string
		 */
		private function generate_order_number( $timestamp ) {
			global $wpdb;

			$table_name = $wpdb->prefix . 'ktp_order';
			$timestamp  = (int) $timestamp;
			$prefix     = date( 'Y-md', $timestamp ) . '-';
			$max_suffix = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT MAX(CAST(SUBSTRING(order_number, %d) AS UNSIGNED)) FROM `{$table_name}` WHERE order_number LIKE %s",
					strlen( $prefix ) + 1,
					$prefix . '%'
				)
			);

			return $prefix . str_pad( (string) ( (int) $max_suffix + 1 ), 3, '0', STR_PAD_LEFT );
		}

		private function insert_order( array $order_data ) {
			global $wpdb;

			$table_name = $wpdb->prefix . 'ktp_order';
			$timestamp  = isset( $order_data['time'] ) ? (int) $order_data['time'] : time();
			$order_number = $this->generate_order_number( $timestamp );

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
			if ( ! empty( $order_data['client_department_id'] ) ) {
				$cols = $wpdb->get_col( "SHOW COLUMNS FROM `{$table_name}`", 0 );
				if ( is_array( $cols ) && in_array( 'client_department_id', $cols, true ) ) {
					$insert_data['client_department_id'] = (int) $order_data['client_department_id'];
				}
			}

			$format = array();
			foreach ( array_keys( $insert_data ) as $key ) {
				if ( in_array( $key, array( 'client_id', 'client_department_id', 'progress', 'time' ), true ) ) {
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

	}
}
