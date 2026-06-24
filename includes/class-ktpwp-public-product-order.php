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
			add_action( 'wp_ajax_ktpwp_public_product_purchase', array( $this, 'ajax_purchase_order' ) );
			add_action( 'wp_ajax_nopriv_ktpwp_public_product_purchase', array( $this, 'ajax_purchase_order' ) );
		}

		/**
		 * 公開商品の Stripe 即時購入が利用可能か。
		 *
		 * @return bool
		 */
		public static function is_stripe_purchase_enabled() {
			if ( function_exists( 'ktpwp_is_feature_enabled' ) && ! ktpwp_is_feature_enabled( 'stripe_billing' ) ) {
				return false;
			}

			return class_exists( 'KTPWP_Stripe_Billing' ) && KTPWP_Stripe_Billing::is_enabled();
		}

		/**
		 * サービスが公開商品の即時購入対象か。
		 *
		 * @param object|null $service サービスレコード。
		 * @return bool
		 */
		public static function service_supports_instant_purchase( $service ) {
			if ( ! self::is_stripe_purchase_enabled() ) {
				return false;
			}

			return class_exists( 'KTPWP_Service_DB' ) && KTPWP_Service_DB::is_public_instant_purchase( $service );
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
		public function submit_order( $service_id, array $form, array $options = array() ) {
			if ( function_exists( 'ktpwp_is_feature_enabled' ) && ! ktpwp_is_feature_enabled( 'public_products' ) ) {
				$store_url = class_exists( 'KTPWP_Edition' ) ? KTPWP_Edition::get_store_url() : 'https://www.kantanpro.com/product/kantanpro-ex';
				return array(
					'success' => false,
					'message' => sprintf(
						/* translators: %s: store URL */
						__( 'フリー版では公開商品機能は利用できません。KantanProEX（WP）販売所: %s', 'ktpwp' ),
						$store_url
					),
				);
			}

			$service = $this->get_public_service( $service_id );
			if ( ! $service ) {
				return array(
					'success' => false,
					'message' => __( '指定された商品は公開されていないか、存在しません。', 'ktpwp' ),
				);
			}

			$company_name = trim( isset( $form['company_name'] ) ? sanitize_text_field( $form['company_name'] ) : '' );
			$contact_name = trim( isset( $form['contact_name'] ) ? sanitize_text_field( $form['contact_name'] ) : '' );
			$email        = isset( $form['email'] ) ? sanitize_email( $form['email'] ) : '';
			$phone        = isset( $form['phone'] ) ? sanitize_text_field( $form['phone'] ) : '';
			$message      = isset( $form['message'] ) ? sanitize_textarea_field( $form['message'] ) : '';
			if ( class_exists( 'KTPWP_Service_DB' ) && KTPWP_Service_DB::is_public_quantity_fixed( $service ) ) {
				$quantity = 1;
			} else {
				$quantity = isset( $form['quantity'] ) ? floatval( $form['quantity'] ) : 1;
				if ( $quantity < 1 ) {
					$quantity = 1;
				}
			}

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

			if ( class_exists( 'KTPWP_Contract_Service_Public_Availability' ) ) {
				$availability = KTPWP_Contract_Service_Public_Availability::get_public_availability(
					(int) $service->id,
					$service
				);

				if ( empty( $availability['acceptance_open'] ) ) {
					$message = $availability['availability_state'] === 'sold_out'
						? __( 'こちらの商品は完売しました。', 'ktpwp' )
						: __( '現在この商品はお問い合わせを受け付けておりません（保留中）。', 'ktpwp' );

					return array(
						'success' => false,
						'message' => $message,
					);
				}
			}

			if ( ! class_exists( 'KTPWP_Inquiry_Client_Resolver' ) ) {
				require_once dirname( __FILE__ ) . '/class-ktpwp-inquiry-client-resolver.php';
			}

			$resolved = KTPWP_Inquiry_Client_Resolver::resolve(
				array(
					'company_name' => $company_name,
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

			// 受注の会社名は顧客マスタの登録会社名。担当者名はフォームのお名前。
			$customer_name = KTPWP_Inquiry_Client_Resolver::resolve_order_customer_name( $client_id, $company_name, $contact_name );

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

			if ( class_exists( 'KTPWP_Order_Admin_Notification' ) && empty( $options['defer_admin_notification'] ) ) {
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
		 * 公開商品を Stripe 決済へ誘導する（受注作成 → Checkout Session または Invoice）。
		 *
		 * @param int   $service_id サービス ID。
		 * @param array $form       フォーム入力。
		 * @param array $context    追加コンテキスト（return_url, cancel_url）。
		 * @return array{success:bool,message:string,order_id?:int,checkout_url?:string}
		 */
		public function purchase_order( $service_id, array $form, array $context = array() ) {
			if ( ! self::is_stripe_purchase_enabled() ) {
				return array(
					'success' => false,
					'message' => __( 'オンライン決済は現在ご利用いただけません。', 'ktpwp' ),
				);
			}

			$service = $this->get_public_service( $service_id );
			if ( ! $service ) {
				return array(
					'success' => false,
					'message' => __( '指定された商品は公開されていないか、存在しません。', 'ktpwp' ),
				);
			}

			if ( ! self::service_supports_instant_purchase( $service ) ) {
				return array(
					'success' => false,
					'message' => __( 'この商品は即時購入の対象外です。', 'ktpwp' ),
				);
			}

			$result = $this->submit_order(
				$service_id,
				$form,
				array( 'defer_admin_notification' => true )
			);
			if ( empty( $result['success'] ) ) {
				return $result;
			}

			$order_id = isset( $result['order_id'] ) ? (int) $result['order_id'] : 0;
			if ( $order_id <= 0 ) {
				return array(
					'success' => false,
					'message' => __( '決済の準備に失敗しました。', 'ktpwp' ),
				);
			}

			$this->save_instant_purchase_context(
				$order_id,
				array(
					'client_email' => isset( $form['email'] ) ? sanitize_email( $form['email'] ) : '',
					'service_name' => isset( $service->service_name ) ? sanitize_text_field( (string) $service->service_name ) : '',
				)
			);

			$this->save_order_payment_timing( $order_id, 'prepay' );

			$stripe = KTPWP_Stripe_Billing::get_instance();

			$return_url = isset( $context['return_url'] ) ? esc_url_raw( (string) $context['return_url'] ) : '';
			$cancel_url = isset( $context['cancel_url'] ) ? esc_url_raw( (string) $context['cancel_url'] ) : '';
			if ( $return_url === '' && $cancel_url !== '' ) {
				$return_url = $cancel_url;
			}
			if ( $cancel_url === '' && $return_url !== '' ) {
				$cancel_url = $return_url;
			}

			global $wpdb;
			$order_table = $wpdb->prefix . 'ktp_order';
			$order       = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$order_table} WHERE id = %d",
					$order_id
				)
			);

			$use_checkout = ! (
				$order
				&& class_exists( 'KTPWP_Stripe_Subscription' )
				&& KTPWP_Stripe_Subscription::get_instance()->order_qualifies_for_immediate_subscription( $order )
			);

			if ( $use_checkout ) {
				$checkout_result = $stripe->create_public_product_checkout_session(
					$order_id,
					$cancel_url,
					$return_url,
					isset( $form['email'] ) ? sanitize_email( $form['email'] ) : ''
				);
				if ( ! is_wp_error( $checkout_result ) ) {
					$checkout_url = isset( $checkout_result['url'] ) ? trim( (string) $checkout_result['url'] ) : '';
					if ( $checkout_url !== '' ) {
						return array(
							'success'      => true,
							'message'      => __( '決済ページへ移動します…', 'ktpwp' ),
							'order_id'     => $order_id,
							'checkout_url' => $checkout_url,
						);
					}
				}
			}

			$invoice_result = $stripe->prepare_invoice_for_order( $order_id, true );
			if ( is_wp_error( $invoice_result ) ) {
				return array(
					'success' => false,
					'message' => $invoice_result->get_error_message(),
				);
			}

			$checkout_url = isset( $invoice_result['url'] ) ? trim( (string) $invoice_result['url'] ) : '';
			if ( $checkout_url === '' ) {
				return array(
					'success' => false,
					'message' => __( '決済ページの取得に失敗しました。', 'ktpwp' ),
				);
			}

			return array(
				'success'      => true,
				'message'      => __( '決済ページへ移動します…', 'ktpwp' ),
				'order_id'     => $order_id,
				'checkout_url' => $checkout_url,
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
		 * AJAX: Stripe 即時購入。
		 *
		 * @return void
		 */
		public function ajax_purchase_order() {
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
			$context    = array(
				'return_url' => isset( $_POST['return_url'] ) ? wp_unslash( $_POST['return_url'] ) : '',
				'cancel_url' => isset( $_POST['cancel_url'] ) ? wp_unslash( $_POST['cancel_url'] ) : '',
			);

			$result = $this->purchase_order( $service_id, $form, $context );

			if ( empty( $result['success'] ) ) {
				$this->send_ajax_json_error(
					array( 'message' => $result['message'] ),
					400
				);
			}

			$this->send_ajax_json_success(
				array(
					'message'      => $result['message'],
					'order_id'     => isset( $result['order_id'] ) ? (int) $result['order_id'] : 0,
					'checkout_url' => isset( $result['checkout_url'] ) ? (string) $result['checkout_url'] : '',
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

		/**
		 * 受注の支払タイミングを保存する。
		 *
		 * @param int    $order_id 受注 ID。
		 * @param string $timing   postpay|prepay。
		 * @return void
		 */
		private function save_order_payment_timing( $order_id, $timing ) {
			global $wpdb;

			$order_id = absint( $order_id );
			if ( $order_id <= 0 ) {
				return;
			}

			$timing = sanitize_text_field( (string) $timing );
			if ( ! in_array( $timing, array( 'postpay', 'prepay' ), true ) ) {
				return;
			}

			$table = $wpdb->prefix . 'ktp_order';
			$cols  = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`", 0 );
			if ( ! is_array( $cols ) || ! in_array( 'payment_timing', $cols, true ) ) {
				return;
			}

			$wpdb->update(
				$table,
				array( 'payment_timing' => $timing ),
				array( 'id' => $order_id ),
				array( '%s' ),
				array( '%d' )
			);
		}

		/**
		 * 即時購入の決済完了通知用コンテキストを保存する。
		 *
		 * @param int                  $order_id 受注 ID。
		 * @param array<string, mixed> $context  client_email, service_name など。
		 * @return void
		 */
		private function save_instant_purchase_context( $order_id, array $context ) {
			$order_id = absint( $order_id );
			if ( $order_id <= 0 ) {
				return;
			}

			set_transient( $this->get_instant_purchase_context_key( $order_id ), $context, DAY_IN_SECONDS );
		}

		/**
		 * @param int $order_id 受注 ID。
		 * @return string
		 */
		private function get_instant_purchase_context_key( $order_id ) {
			return 'ktpwp_instant_purchase_ctx_' . absint( $order_id );
		}

		/**
		 * Stripe 入金完了後に、即時購入の管理者通知・購入者メールを送信する。
		 *
		 * @param int $order_id 受注 ID。
		 * @return bool 処理した場合 true。
		 */
		public function handle_instant_purchase_paid( $order_id ) {
			$order_id = absint( $order_id );
			if ( $order_id <= 0 ) {
				return false;
			}

			$ctx_key = $this->get_instant_purchase_context_key( $order_id );
			$context = get_transient( $ctx_key );
			if ( ! is_array( $context ) ) {
				return false;
			}

			delete_transient( $ctx_key );

			if ( class_exists( 'KTPWP_Order_Admin_Notification' ) ) {
				KTPWP_Order_Admin_Notification::get_instance()->notify_new_order(
					$order_id,
					KTPWP_Order_Admin_Notification::SOURCE_PUBLIC_PRODUCT,
					array(
						'client_email' => isset( $context['client_email'] ) ? (string) $context['client_email'] : '',
						'service_name' => isset( $context['service_name'] ) ? (string) $context['service_name'] : '',
					)
				);
			}

			$this->notify_purchaser_payment_complete( $order_id, $context );

			return true;
		}

		/**
		 * 購入者へ決済完了メールを送信する。
		 *
		 * @param int                  $order_id 受注 ID。
		 * @param array<string, mixed> $context  通知コンテキスト。
		 * @return bool
		 */
		private function notify_purchaser_payment_complete( $order_id, array $context ) {
			$email = isset( $context['client_email'] ) ? sanitize_email( (string) $context['client_email'] ) : '';
			if ( $email === '' || ! is_email( $email ) ) {
				return false;
			}

			global $wpdb;
			$table = $wpdb->prefix . 'ktp_order';
			$order = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE id = %d",
					$order_id
				)
			);

			if ( ! $order ) {
				return false;
			}

			$site_name    = get_bloginfo( 'name' );
			$project_name = isset( $order->project_name ) ? (string) $order->project_name : '';
			$order_number = isset( $order->order_number ) ? (string) $order->order_number : '';
			$service_name = isset( $context['service_name'] ) ? sanitize_text_field( (string) $context['service_name'] ) : $project_name;

			$subject = sprintf(
				/* translators: %s: site name */
				__( '[%s] ご購入ありがとうございました', 'ktpwp' ),
				$site_name
			);

			$body  = __( 'この度はご購入いただきありがとうございました。', 'ktpwp' ) . "\n\n";
			$body .= __( 'お支払いが完了しました。', 'ktpwp' ) . "\n\n";

			if ( $service_name !== '' ) {
				$body .= __( '商品名:', 'ktpwp' ) . ' ' . $service_name . "\n";
			}
			if ( $order_number !== '' ) {
				$body .= __( '受注番号:', 'ktpwp' ) . ' ' . $order_number . "\n";
			}
			if ( $project_name !== '' && $project_name !== $service_name ) {
				$body .= __( '案件名:', 'ktpwp' ) . ' ' . $project_name . "\n";
			}

			$body .= "\n" . __( '内容の詳細は、担当者より改めてご連絡する場合がございます。', 'ktpwp' ) . "\n";
			$body .= "\n" . __( '※ このメールは自動送信されています。', 'ktpwp' ) . "\n";

			$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
			$smtp_settings = get_option( 'ktp_smtp_settings', array() );
			$from_email    = ! empty( $smtp_settings['email_address'] ) ? sanitize_email( $smtp_settings['email_address'] ) : sanitize_email( get_option( 'admin_email' ) );
			$from_name     = ! empty( $smtp_settings['smtp_from_name'] ) ? sanitize_text_field( $smtp_settings['smtp_from_name'] ) : $site_name;
			if ( $from_email !== '' && is_email( $from_email ) ) {
				$headers[] = $from_name !== ''
					? 'From: ' . $from_name . ' <' . $from_email . '>'
					: 'From: ' . $from_email;
			}

			if ( class_exists( 'KTPWP_Order_Auxiliary' ) ) {
				$outcome = KTPWP_Order_Auxiliary::run_wp_mail_with_result(
					static function () use ( $email, $subject, $body, $headers ) {
						return wp_mail( $email, $subject, $body, $headers );
					}
				);

				return ! empty( $outcome['success'] );
			}

			return (bool) wp_mail( $email, $subject, $body, $headers );
		}

	}
}
