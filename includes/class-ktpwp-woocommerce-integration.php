<?php
/**
 * WooCommerce 連携: WooCommerce の注文を KantanPro に自動追加
 *
 * @package KantanPro
 * @since 1.2.10
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class KTPWP_WooCommerce_Integration
 */
class KTPWP_WooCommerce_Integration {

	/**
	 * シングルトンインスタンス
	 *
	 * @var KTPWP_WooCommerce_Integration|null
	 */
	private static $instance = null;

	/**
	 * シングルトンインスタンスを取得
	 *
	 * @return KTPWP_WooCommerce_Integration
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * コンストラクタ
	 */
	private function __construct() {
		$this->init_hooks();
	}

	/**
	 * フックを登録
	 */
	private function init_hooks(): void {
		// 新規注文作成時
		add_action( 'woocommerce_new_order', array( $this, 'sync_order_to_ktp' ), 20, 2 );
		// チェックアウトで注文が作成された直後（クラシック・ブロック両方で発火しやすい）
		add_action( 'woocommerce_checkout_order_created', array( $this, 'sync_order_to_ktp_checkout_created' ), 20, 1 );
		// 注文ステータス変更時（どのステータスでも同期を試行し、未連携なら追加）
		add_action( 'woocommerce_order_status_pending', array( $this, 'sync_order_to_ktp_on_status' ), 20, 2 );
		add_action( 'woocommerce_order_status_on-hold', array( $this, 'sync_order_to_ktp_on_status' ), 20, 2 );
		add_action( 'woocommerce_order_status_processing', array( $this, 'sync_order_to_ktp_on_status' ), 20, 2 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'sync_order_to_ktp_on_status' ), 20, 2 );
		// 管理画面: 未同期注文の手動同期
		add_action( 'admin_menu', array( $this, 'add_sync_menu' ), 99 );
		add_action( 'admin_post_ktpwp_sync_woocommerce_orders', array( $this, 'handle_sync_woocommerce_orders' ) );
		add_action( 'admin_post_ktpwp_link_woocommerce_clients', array( $this, 'handle_link_woocommerce_clients' ) );
		add_action( 'admin_post_ktpwp_relink_woocommerce_clients', array( $this, 'handle_relink_woocommerce_clients' ) );
		add_action( 'admin_post_ktpwp_toggle_woocommerce_sync', array( $this, 'handle_toggle_woocommerce_sync' ) );
	}

	/**
	 * WooCommerce 連携が有効か（未設定は有効＝従来どおり）
	 */
	public function is_sync_enabled(): bool {
		return get_option( 'ktpwp_wc_sync_enabled', '1' ) !== '0';
	}

	/**
	 * 連携の有効/解除を切り替える
	 */
	public function handle_toggle_woocommerce_sync(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( '権限がありません。', 'ktpwp' ) );
		}
		check_admin_referer( 'ktpwp_toggle_wc_sync' );
		$enable = isset( $_GET['enable'] ) && '1' === $_GET['enable'];
		update_option( 'ktpwp_wc_sync_enabled', $enable ? '1' : '0' );
		set_transient(
			'ktpwp_wc_sync_message',
			$enable
				? __( 'WooCommerce との連携を有効にしました。', 'ktpwp' )
				: __( 'WooCommerce との連携を解除しました。今後の注文は KantanPro に取り込まれません（取り込み済みの受注はそのまま残ります）。', 'ktpwp' ),
			30
		);
		wp_safe_redirect( admin_url( 'admin.php?page=ktpwp-woocommerce-sync' ) );
		exit;
	}

	/**
	 * チェックアウト注文作成フック用（order_id または WC_Order が渡る）
	 *
	 * @param int|WC_Order $order_or_id 注文ID または注文オブジェクト
	 */
	public function sync_order_to_ktp_checkout_created( $order_or_id ): void {
		if ( $order_or_id instanceof WC_Order ) {
			$this->sync_order_to_ktp( (int) $order_or_id->get_id(), $order_or_id );
		} elseif ( is_numeric( $order_or_id ) ) {
			$this->sync_order_to_ktp( (int) $order_or_id, null );
		}
	}

	/**
	 * 注文ステータス変更時に同期（既に連携済みなら何もしない）
	 *
	 * @param int      $order_id WC 注文ID
	 * @param WC_Order $order    注文オブジェクト（WC 3.0+）
	 */
	public function sync_order_to_ktp_on_status( int $order_id, $order = null ): void {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order ) {
			return;
		}
		$this->sync_order_to_ktp( $order_id, $order );
	}

	/**
	 * WooCommerce 注文を KantanPro に同期
	 *
	 * @param int      $order_id WC 注文ID
	 * @param WC_Order $order    注文オブジェクト（省略時は get_order で取得）
	 */
	public function sync_order_to_ktp( int $order_id, $order = null ): void {
		if ( ! $this->is_sync_enabled() ) {
			return;
		}
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				ktpwp_debug_log( 'KTPWP WooCommerce: Invalid order id ' . $order_id );
			}
			return;
		}

		$this->import_order_payload( $this->order_payload( $order ) );
	}

	/**
	 * WooCommerce 注文を、受注の取り込みに使う配列にする。
	 *
	 * デスクトップ版（WooCommerce の無い環境）では、公開サイトの受付プラグイン（ktp-dt-bridge）が
	 * 同じ形の配列を送り、それを import_order_payload() に渡す。形を変えるときは受付プラグイン側も合わせる。
	 *
	 * @param WC_Order $order WooCommerce 注文。
	 * @return array{id: int, number: string, created: int, billing: array<string, string>, items: array<int, array>}
	 */
	public function order_payload( WC_Order $order ): array {
		$created  = $order->get_date_created();
		$fallback = $this->get_order_default_tax_rate( $order );

		$items = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$subtotal = (float) $item->get_subtotal();
			$total    = (float) $item->get_total();
			$items[]  = array(
				'name'     => $item->get_name(),
				'quantity' => (float) $item->get_quantity(),
				'subtotal' => $subtotal,
				'total'    => $total,
				'tax_rate' => $this->calc_line_tax_rate( $subtotal, (float) $item->get_subtotal_tax(), $total, (float) $item->get_total_tax(), $fallback ),
			);
		}

		return array(
			'id'      => (int) $order->get_id(),
			'number'  => (string) $order->get_order_number(),
			'created' => $created ? $created->getTimestamp() : time(),
			'billing' => array(
				'company'    => (string) $order->get_billing_company(),
				'first_name' => (string) $order->get_billing_first_name(),
				'last_name'  => (string) $order->get_billing_last_name(),
				'email'      => (string) $order->get_billing_email(),
				'phone'      => (string) $order->get_billing_phone(),
				'postcode'   => (string) $order->get_billing_postcode(),
				'state'      => (string) $order->get_billing_state(),
				'city'       => (string) $order->get_billing_city(),
				'address_1'  => (string) $order->get_billing_address_1(),
				'address_2'  => (string) $order->get_billing_address_2(),
			),
			'items'   => $items,
		);
	}

	/**
	 * order_payload() の形の注文を KantanPro の受注として追加する（連携済みなら追加しない）。
	 *
	 * @param array $payload 注文（order_payload() の形）。
	 * @return int KantanPro の受注 ID（連携済みならその ID）。追加できなかったら 0。
	 */
	public function import_order_payload( array $payload ): int {
		$wc_order_id = isset( $payload['id'] ) ? (int) $payload['id'] : 0;
		if ( $wc_order_id <= 0 ) {
			return 0;
		}

		// 受注テーブルに order_number / external カラムが無い場合は追加（同期失敗を防ぐ）
		$this->ensure_order_table_ready();

		// 既に連携済みかチェック（external_order_id カラムがある場合のみ）
		$existing_ktp_id = $this->get_ktp_order_id_by_wc_order_id( $wc_order_id );
		if ( $existing_ktp_id ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				ktpwp_debug_log( 'KTPWP WooCommerce: Order ' . $wc_order_id . ' already synced to KantanPro (id=' . $existing_ktp_id . '), skip.' );
			}
			return $existing_ktp_id;
		}

		$order_manager = null;
		if ( class_exists( 'KTPWP_Order' ) ) {
			$order_manager = KTPWP_Order::get_instance();
		}
		if ( ! $order_manager || ! method_exists( $order_manager, 'create_order' ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				ktpwp_debug_log( 'KTPWP WooCommerce: KTPWP_Order not available.' );
			}
			return 0;
		}

		$order_number = sanitize_text_field( (string) ( $payload['number'] ?? '' ) );
		$order_number = $order_number !== '' ? $order_number : (string) $wc_order_id;
		$project_name = 'WC #' . $order_number;
		$time         = ! empty( $payload['created'] ) ? (int) $payload['created'] : time();
		$memo         = sprintf(
			/* translators: 1: WooCommerce order number, 2: order ID */
			__( 'WooCommerce 注文 #%1$s (ID: %2$d)', 'ktpwp' ),
			$order_number,
			$wc_order_id
		);

		$resolved = $this->resolve_client_from_payload( $payload );
		if ( ! $resolved ) {
			ktpwp_debug_log( 'KTPWP WooCommerce: Failed to resolve client for WC order ' . $wc_order_id );
			return 0;
		}

		$client_id     = (int) $resolved['client_id'];
		$department_id = isset( $resolved['department_id'] ) ? (int) $resolved['department_id'] : 0;
		$customer_name = $resolved['customer_name'];
		$user_name     = $resolved['user_name'];

		$search_parts = array_filter( array( $customer_name, $project_name, $memo ) );
		$search_field = implode( ', ', $search_parts );

		$data = array(
			'time'           => $time,
			'client_id'      => $client_id,
			'customer_name'  => $customer_name,
			'user_name'      => $user_name,
			'project_name'   => $project_name,
			'progress'       => 3,
			'invoice_items'  => '',
			'cost_items'     => '',
			'memo'           => $memo,
			'search_field'   => $search_field,
		);

		$ktp_order_id = $order_manager->create_order( $data );
		if ( ! $ktp_order_id ) {
			global $wpdb;
			$err = $wpdb->last_error ? $wpdb->last_error : 'unknown';
			ktpwp_debug_log( 'KTPWP WooCommerce: Failed to create KantanPro order for WC order ' . $wc_order_id . '. DB error: ' . $err );
			set_transient( 'ktpwp_wc_sync_last_error', $err, 60 );
			if ( strpos( $err, 'order_number' ) !== false || strpos( $err, 'external_source' ) !== false ) {
				ktpwp_debug_log( 'KTPWP WooCommerce: ヒント: 受注テーブルに order_number や external_source がない可能性があります。KantanPro を一度無効化して再有効化するか、管理画面で保存してマイグレーションを実行してください。' );
			}
			return 0;
		}

		// 外部連携情報と支払いタイミング（WC受注）を保存（カラムが存在する場合のみ）
		if ( $this->order_table_has_external_columns() ) {
			$external_update = array(
				'external_source'   => 'woocommerce',
				'external_order_id' => (string) $wc_order_id,
				'payment_timing'    => 'prepay',
			);
			if ( $department_id > 0 ) {
				$external_update['client_department_id'] = $department_id;
			}
			$order_manager->update_order(
				$ktp_order_id,
				$external_update
			);
		} elseif ( $department_id > 0 ) {
			$order_manager->update_order(
				$ktp_order_id,
				array(
					'client_department_id' => $department_id,
				)
			);
		}

		// 請求項目: WC のラインアイテムを追加（なければ初期1件）
		$this->insert_invoice_items( (int) $ktp_order_id, isset( $payload['items'] ) && is_array( $payload['items'] ) ? $payload['items'] : array() );

		// 初期コスト項目
		if ( class_exists( 'KTPWP_Order_Items' ) ) {
			$order_items = KTPWP_Order_Items::get_instance();
			if ( method_exists( $order_items, 'create_initial_cost_item' ) ) {
				$order_items->create_initial_cost_item( $ktp_order_id );
			}
		}

		// 初期スタッフチャット（管理者名義）
		if ( class_exists( 'KTPWP_Staff_Chat' ) ) {
			$staff_chat = KTPWP_Staff_Chat::get_instance();
			if ( method_exists( $staff_chat, 'create_inbound_initial_chat' ) ) {
				$staff_chat->create_inbound_initial_chat(
					$ktp_order_id,
					KTPWP_Order_Admin_Notification::SOURCE_WOOCOMMERCE
				);
			}
		}

		if ( class_exists( 'KTPWP_Order_Admin_Notification' ) ) {
			KTPWP_Order_Admin_Notification::get_instance()->notify_new_order(
				$ktp_order_id,
				KTPWP_Order_Admin_Notification::SOURCE_WOOCOMMERCE,
				array(
					'wc_order_number' => $order_number,
				)
			);
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			ktpwp_debug_log( 'KTPWP WooCommerce: Synced WC order ' . $wc_order_id . ' to KantanPro order ' . $ktp_order_id );
		}

		return (int) $ktp_order_id;
	}

	/**
	 * WooCommerce 注文から顧客・部署を解決する（公開商品お問い合わせと同一ロジック）。
	 *
	 * @param WC_Order $order WooCommerce 注文。
	 * @return array{client_id: int, department_id: int|null, customer_name: string, user_name: string}|null
	 */
	private function resolve_client_from_wc_order( WC_Order $order ): ?array {
		return $this->resolve_client_from_payload( $this->order_payload( $order ) );
	}

	/**
	 * order_payload() の形の注文から顧客・部署を解決する。
	 *
	 * @param array $payload 注文（order_payload() の形）。
	 * @return array{client_id: int, department_id: int|null, customer_name: string, user_name: string}|null
	 */
	private function resolve_client_from_payload( array $payload ): ?array {
		if ( ! class_exists( 'KTPWP_Inquiry_Client_Resolver' ) ) {
			require_once dirname( __FILE__ ) . '/class-ktpwp-inquiry-client-resolver.php';
		}

		$billing = $this->payload_billing( $payload );
		$company = $billing['company'];
		$name    = $this->get_billing_full_name( $billing );
		$email   = sanitize_email( $billing['email'] );

		$resolved = KTPWP_Inquiry_Client_Resolver::resolve(
			array(
				'email'        => $email,
				'company_name' => $company,
				'name'         => $name,
			),
			function ( array $data ) use ( $billing ) {
				return $this->create_new_client_from_billing( $billing );
			}
		);

		if ( ! is_array( $resolved ) || empty( $resolved['client_id'] ) ) {
			return null;
		}

		$client_id     = (int) $resolved['client_id'];
		$department_id = ! empty( $resolved['department_id'] ) ? (int) $resolved['department_id'] : null;
		$customer_name = KTPWP_Inquiry_Client_Resolver::resolve_order_customer_name( $client_id, $company, $name );
		$user_name     = $name !== '' ? $name : $customer_name;

		return array(
			'client_id'     => $client_id,
			'department_id' => $department_id,
			'customer_name' => $customer_name,
			'user_name'     => $user_name,
		);
	}

	/**
	 * 注文の請求先を、決まったキーの文字列だけにそろえる（外から届いた注文も同じに扱う）。
	 *
	 * @param array $payload 注文（order_payload() の形）。
	 * @return array<string, string>
	 */
	private function payload_billing( array $payload ): array {
		$raw     = isset( $payload['billing'] ) && is_array( $payload['billing'] ) ? $payload['billing'] : array();
		$billing = array();
		foreach ( array( 'company', 'first_name', 'last_name', 'email', 'phone', 'postcode', 'state', 'city', 'address_1', 'address_2' ) as $key ) {
			$billing[ $key ] = isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) ? trim( sanitize_text_field( (string) $raw[ $key ] ) ) : '';
		}
		return $billing;
	}

	/**
	 * WooCommerce 注文の請求先から KantanPro 顧客を新規作成する。
	 *
	 * @param WC_Order $order WooCommerce 注文。
	 * @return int|false 顧客 ID。失敗時 false。
	 */
	public function create_new_client_from_wc_order( WC_Order $order ) {
		return $this->create_new_client_from_billing( $this->payload_billing( $this->order_payload( $order ) ) );
	}

	/**
	 * 請求先（payload_billing() の形）から KantanPro 顧客を新規作成する。
	 *
	 * @param array<string, string> $billing 請求先。
	 * @return int|false 顧客 ID。失敗時 false。
	 */
	private function create_new_client_from_billing( array $billing ) {
		global $wpdb;
		$table = $wpdb->prefix . 'ktp_client';
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return false;
		}

		$company = $billing['company'];
		$name    = $this->get_billing_full_name( $billing );
		$email   = sanitize_email( $billing['email'] );
		$phone   = $billing['phone'];
		$postal  = $this->normalize_postal_code( $billing['postcode'] );
		$state   = $billing['state'];
		$city    = $billing['city'];
		$addr1   = $billing['address_1'];
		$addr2   = $billing['address_2'];
		$address = trim( $addr1 . ( $addr2 !== '' ? ' ' . $addr2 : '' ) );

		$company_name = $company !== '' ? $company : ( $name !== '' ? $name : __( 'WooCommerce顧客', 'ktpwp' ) );

		$search_parts = array_filter( array( $company_name, $name, $email, $phone, $state, $city, $address ) );
		$search_field = implode( ' ', $search_parts );
		$cols         = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`", 0 );
		$cols         = is_array( $cols ) ? $cols : array();

		$row = array(
			'time'              => current_time( 'mysql' ),
			'company_name'      => $company_name,
			'name'              => $name,
			'email'             => $email,
			'phone'             => $phone,
			'postal_code'       => $postal,
			'prefecture'        => $state,
			'city'              => $city,
			'address'           => $address,
			'tax_category'      => __( '内税', 'ktpwp' ),
			'payment_timing'    => 'prepay',
			'client_status'     => __( '対象', 'ktpwp' ),
			'search_field'      => $search_field,
		);
		$fmt = array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );

		if ( in_array( 'representative_name', $cols, true ) ) {
			$row['representative_name'] = $name;
			$fmt[] = '%s';
		}
		if ( in_array( 'building', $cols, true ) ) {
			$row['building'] = '';
			$fmt[] = '%s';
		}
		if ( in_array( 'url', $cols, true ) ) {
			$row['url'] = '';
			$fmt[] = '%s';
		}
		if ( in_array( 'closing_day', $cols, true ) ) {
			$row['closing_day'] = '';
			$fmt[] = '%s';
		}
		if ( in_array( 'payment_month', $cols, true ) ) {
			$row['payment_month'] = '';
			$fmt[] = '%s';
		}
		if ( in_array( 'payment_day', $cols, true ) ) {
			$row['payment_day'] = '';
			$fmt[] = '%s';
		}
		if ( in_array( 'payment_method', $cols, true ) ) {
			$row['payment_method'] = '';
			$fmt[] = '%s';
		}
		if ( in_array( 'memo', $cols, true ) ) {
			$row['memo'] = __( 'WooCommerce から取り込み', 'ktpwp' );
			$fmt[] = '%s';
		}
		if ( in_array( 'category', $cols, true ) ) {
			$row['category'] = '';
			$fmt[] = '%s';
		}

		$result = $wpdb->insert( $table, $row, $fmt );
		if ( $result === false ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				ktpwp_debug_log( 'KTPWP WooCommerce: Failed to create client. ' . $wpdb->last_error );
			}
			return false;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * WooCommerce の請求先氏名を KantanPro の姓名順に整形
	 *
	 * @param array<string, string> $billing 請求先（payload_billing() の形）
	 * @return string
	 */
	private function get_billing_full_name( array $billing ): string {
		return trim( $billing['last_name'] . ' ' . $billing['first_name'] );
	}

	/**
	 * WooCommerce から受信した郵便番号を KantanPro の保存形式に整形
	 *
	 * @param string $postal_code 郵便番号
	 * @return string
	 */
	private function normalize_postal_code( string $postal_code ): string {
		return str_replace( '-', '', trim( $postal_code ) );
	}

	/**
	 * 受注テーブルに order_number / external カラムが無ければ追加する
	 */
	private function ensure_order_table_ready(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'ktp_order';
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			// 受注テーブルが無い場合は KantanPro のテーブル作成を実行
			if ( function_exists( 'ktpwp_safe_table_setup' ) ) {
				ktpwp_safe_table_setup();
			}
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( $exists !== $table ) {
				return;
			}
		}
		$cols = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`", 0 );
		$cols = is_array( $cols ) ? $cols : array();
		$add = array(
			'order_number'      => "VARCHAR(100) NULL DEFAULT NULL COMMENT '受注番号'",
			'external_source'   => "VARCHAR(50) NULL DEFAULT NULL COMMENT '連携元（例: woocommerce）'",
			'external_order_id' => "VARCHAR(100) NULL DEFAULT NULL COMMENT '外部注文ID'",
		);
		foreach ( $add as $col => $def ) {
			if ( in_array( $col, $cols, true ) ) {
				continue;
			}
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$def}" );
		}
	}

	/**
	 * ktp_order テーブルに external_source / external_order_id があるか
	 *
	 * @return bool
	 */
	private function order_table_has_external_columns(): bool {
		global $wpdb;
		$table  = $wpdb->prefix . 'ktp_order';
		$cols   = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`", 0 );
		$cols   = is_array( $cols ) ? $cols : array();
		return in_array( 'external_source', $cols, true ) && in_array( 'external_order_id', $cols, true );
	}

	/**
	 * external_order_id から KantanPro の order id を取得
	 *
	 * @param int $wc_order_id WooCommerce 注文ID
	 * @return int|null KTP order id or null
	 */
	private function get_ktp_order_id_by_wc_order_id( int $wc_order_id ): ?int {
		if ( ! $this->order_table_has_external_columns() ) {
			return null;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'ktp_order';
		$col   = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM `{$table}` WHERE external_source = 'woocommerce' AND external_order_id = %s LIMIT 1",
				(string) $wc_order_id
			)
		);
		return $col !== null ? (int) $col : null;
	}

	/**
	 * 注文の明細（order_payload() の items）を KantanPro の請求項目として追加
	 *
	 * @param int   $ktp_order_id KantanPro 受注ID
	 * @param array $items        明細（name / quantity / subtotal / total / tax_rate）
	 */
	private function insert_invoice_items( int $ktp_order_id, array $items ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'ktp_order_invoice_items';

		$items = array_values( array_filter( $items, 'is_array' ) );
		if ( empty( $items ) ) {
			if ( class_exists( 'KTPWP_Order_Items' ) ) {
				$order_items = KTPWP_Order_Items::get_instance();
				if ( method_exists( $order_items, 'create_initial_invoice_item' ) ) {
					$order_items->create_initial_invoice_item( $ktp_order_id );
				}
			}
			return;
		}

		$sort_order = 1;
		$now        = current_time( 'mysql' );

		foreach ( $items as $item ) {
			$name     = sanitize_text_field( (string) ( $item['name'] ?? '' ) );
			$qty      = (float) ( $item['quantity'] ?? 0 );
			$total    = (float) ( $item['total'] ?? 0 );
			$subtotal = (float) ( $item['subtotal'] ?? 0 );
			$price    = $qty > 0 ? $subtotal / $qty : 0;
			$amount   = $total;
			// 非課税の行は null（0% にはしない）
			$tax_rate = isset( $item['tax_rate'] ) && is_numeric( $item['tax_rate'] ) && $item['tax_rate'] >= 0 && $item['tax_rate'] < 100 ? (float) $item['tax_rate'] : null;

			$row = array(
				'order_id'     => $ktp_order_id,
				'product_name' => $name,
				'price'        => $price,
				'unit'         => __( '個', 'ktpwp' ),
				'quantity'     => $qty,
				'amount'       => $amount,
				'tax_rate'     => $tax_rate,
				'remarks'      => '',
				'sort_order'   => $sort_order,
				'created_at'   => $now,
				'updated_at'   => $now,
			);
			$fmt = array( '%d', '%s', '%f', '%s', '%f', '%f', '%f', '%s', '%d', '%s', '%s' );
			$wpdb->insert( $table, $row, $fmt );
			$sort_order++;
		}
	}

	/**
	 * 注文からデフォルト税率を1件取得（請求項目で税率が算出できない場合のフォールバック）
	 * get_items('tax') の税行と、WC_Tax::get_rate_percent( rate_id ) の両方から取得を試みる。
	 *
	 * @param WC_Order $order
	 * @return float|null 税率（%）。取得できない場合は null
	 */
	private function get_order_default_tax_rate( WC_Order $order ): ?float {
		// 1) 注文に紐づく税行（order item type = tax）から取得
		$tax_items = $order->get_items( 'tax' );
		if ( ! empty( $tax_items ) ) {
			foreach ( $tax_items as $tax_item ) {
				if ( ! $tax_item instanceof WC_Order_Item_Tax ) {
					continue;
				}
				$rate_percent = $tax_item->get_rate_percent();
				$rate_float   = $this->parse_tax_rate_percent( $rate_percent );
				if ( $rate_float !== null ) {
					return $rate_float;
				}
				// rate_id から WC_Tax で取得を試行（get_rate_percent が空の場合）
				$rate_id = $tax_item->get_rate_id();
				if ( $rate_id && class_exists( 'WC_Tax' ) && method_exists( 'WC_Tax', 'get_rate_percent' ) ) {
					$rate_percent = WC_Tax::get_rate_percent( $rate_id );
					$rate_float   = $this->parse_tax_rate_percent( $rate_percent );
					if ( $rate_float !== null ) {
						return $rate_float;
					}
				}
			}
		}
		return null;
	}

	/**
	 * 税率文字列を float に変換（"10" / "10%" / "10.000" などに対応）
	 *
	 * @param string|float|int $rate_percent
	 * @return float|null
	 */
	private function parse_tax_rate_percent( $rate_percent ): ?float {
		if ( $rate_percent === null || $rate_percent === '' ) {
			return null;
		}
		if ( is_numeric( $rate_percent ) ) {
			$f = (float) $rate_percent;
			return ( $f >= 0 && $f < 100 ) ? round( $f, 1 ) : null;
		}
		$str = trim( (string) $rate_percent );
		$str = str_replace( array( '%', '％' ), '', $str );
		if ( $str === '' || ! is_numeric( $str ) ) {
			return null;
		}
		$f = (float) $str;
		return ( $f >= 0 && $f < 100 ) ? round( $f, 1 ) : null;
	}

	/**
	 * ラインの税率を算出（小計税額・合計税額から。フォールバックあり）
	 *
	 * @param float     $subtotal 小計（税抜）
	 * @param float     $subtax   小計税額
	 * @param float     $total    行合計
	 * @param float     $tax      行税額
	 * @param float|null $fallback 注文のデフォルト税率
	 * @return float|null 税率（%）。非課税の場合は null
	 */
	private function calc_line_tax_rate( float $subtotal, float $subtax, float $total, float $tax, ?float $fallback ): ?float {
		$snap_to_standard_rate = function ( float $rate ): float {
			$r = round( $rate, 1 );
			if ( $r >= 7.5 && $r <= 8.5 ) {
				return 8.0;
			}
			if ( $r >= 9.5 && $r <= 10.5 ) {
				return 10.0;
			}
			return $r;
		};

		// 税額が 0 の行は非課税として null。0% は返さない。
		if ( $subtotal > 0 && $subtax > 0 ) {
			$rate = ( $subtax / $subtotal ) * 100;
			if ( $rate > 0 && $rate < 100 ) {
				return $snap_to_standard_rate( $rate );
			}
		}
		if ( $total > 0 && $tax > 0 ) {
			$rate_incl = ( $tax / ( $total - $tax ) ) * 100;
			if ( $rate_incl > 0 && $rate_incl < 100 ) {
				return $snap_to_standard_rate( $rate_incl );
			}
			$rate_excl = ( $tax / $total ) * 100;
			if ( $rate_excl > 0 && $rate_excl < 100 ) {
				return $snap_to_standard_rate( $rate_excl );
			}
		}
		if ( $tax > 0 ) {
			return $fallback !== null ? $fallback : 10.0;
		}
		// 金額はあるが税額0 → WooCommerce の端数で0円になっている可能性があるため、注文の税率または10%を採用
		if ( $subtotal > 0 || $total > 0 ) {
			return $fallback !== null ? $fallback : 10.0;
		}
		return null;
	}

	/**
	 * 指定 KTP 受注の請求項目を削除し、WooCommerce 注文から再同期する（税率を含む）
	 *
	 * @param int      $ktp_order_id KantanPro 受注ID
	 * @param WC_Order $order        WooCommerce 注文
	 */
	private function replace_invoice_items_from_wc_order( int $ktp_order_id, WC_Order $order ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'ktp_order_invoice_items';
		$wpdb->delete( $table, array( 'order_id' => $ktp_order_id ), array( '%d' ) );
		$this->insert_invoice_items( $ktp_order_id, $this->order_payload( $order )['items'] );
	}

	/**
	 * 管理画面に「未同期注文を同期」メニューを追加
	 */
	public function add_sync_menu(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		add_submenu_page(
			'woocommerce',
			__( 'KantanPro 連携', 'ktpwp' ),
			__( 'KantanPro 連携', 'ktpwp' ),
			'manage_woocommerce',
			'ktpwp-woocommerce-sync',
			array( $this, 'render_sync_page' )
		);
	}

	/**
	 * 未同期注文を KantanPro に同期する画面を表示
	 */
	public function render_sync_page(): void {
		$this->ensure_order_table_ready();
		$message = get_transient( 'ktpwp_wc_sync_message' );
		if ( $message ) {
			delete_transient( 'ktpwp_wc_sync_message' );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		}
		$error = get_transient( 'ktpwp_wc_sync_error' );
		if ( $error ) {
			delete_transient( 'ktpwp_wc_sync_error' );
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $error ) . '</p></div>';
		}
		$url_sync   = wp_nonce_url( admin_url( 'admin-post.php?action=ktpwp_sync_woocommerce_orders' ), 'ktpwp_sync_wc_orders' );
		$url_link   = wp_nonce_url( admin_url( 'admin-post.php?action=ktpwp_link_woocommerce_clients' ), 'ktpwp_link_wc_clients' );
		$url_relink = wp_nonce_url( admin_url( 'admin-post.php?action=ktpwp_relink_woocommerce_clients' ), 'ktpwp_relink_wc_clients' );
		$need_link  = $this->count_orders_without_client();
		$enabled    = $this->is_sync_enabled();
		$url_toggle = wp_nonce_url( admin_url( 'admin-post.php?action=ktpwp_toggle_woocommerce_sync&enable=' . ( $enabled ? '0' : '1' ) ), 'ktpwp_toggle_wc_sync' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'KantanPro 連携', 'ktpwp' ); ?></h1>
			<h2><?php esc_html_e( '連携の状態', 'ktpwp' ); ?></h2>
			<?php if ( $enabled ) : ?>
				<p><?php esc_html_e( '現在: 連携中（新しい注文は自動で KantanPro に取り込まれます）', 'ktpwp' ); ?></p>
				<p><a href="<?php echo esc_url( $url_toggle ); ?>" class="button button-secondary" onclick="return confirm('<?php echo esc_js( __( 'WooCommerce との連携を解除しますか？今後の注文は KantanPro に取り込まれなくなります。取り込み済みの受注は削除されません。', 'ktpwp' ) ); ?>');"><?php esc_html_e( '連携を解除する', 'ktpwp' ); ?></a></p>
			<?php else : ?>
				<p><strong><?php esc_html_e( '現在: 連携を解除中（注文の取り込みは停止しています）', 'ktpwp' ); ?></strong></p>
				<p><a href="<?php echo esc_url( $url_toggle ); ?>" class="button button-primary"><?php esc_html_e( '連携を再開する', 'ktpwp' ); ?></a></p>
			<?php endif; ?>
			<hr />
			<p><?php esc_html_e( 'WooCommerce の注文のうち、まだ KantanPro に取り込まれていないものを一括で同期します。', 'ktpwp' ); ?></p>
			<p><a href="<?php echo esc_url( $url_sync ); ?>" class="button button-primary"><?php esc_html_e( '未同期の注文を KantanPro に同期', 'ktpwp' ); ?></a></p>
			<?php if ( $this->order_table_has_external_columns() ) : ?>
				<hr />
				<h2><?php esc_html_e( '既存受注の顧客紐付け', 'ktpwp' ); ?></h2>
				<p><?php esc_html_e( '既に KantanPro に同期済みの WooCommerce 連携受注について、同じ注文の請求先から顧客を取得または作成して紐付けます。', 'ktpwp' ); ?></p>
				<?php if ( $need_link > 0 ) : ?>
					<p><a href="<?php echo esc_url( $url_link ); ?>" class="button button-secondary"><?php echo esc_html( sprintf( __( '顧客未設定の受注 %d 件を一括で顧客紐付け', 'ktpwp' ), $need_link ) ); ?></a></p>
				<?php else : ?>
					<p><em><?php esc_html_e( '顧客未設定の WooCommerce 連携受注はありません。', 'ktpwp' ); ?></em></p>
				<?php endif; ?>
				<p><a href="<?php echo esc_url( $url_relink ); ?>" class="button button-secondary"><?php esc_html_e( '顧客を再紐付け（全件対象・既存の紐付けも上書き）', 'ktpwp' ); ?></a></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * 未同期の WooCommerce 注文を一括同期
	 */
	public function handle_sync_woocommerce_orders(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( '権限がありません。', 'ktpwp' ) );
		}
		check_admin_referer( 'ktpwp_sync_wc_orders' );
		if ( ! $this->is_sync_enabled() ) {
			set_transient( 'ktpwp_wc_sync_error', __( '連携を解除中のため同期できません。先に連携を再開してください。', 'ktpwp' ), 60 );
			wp_safe_redirect( admin_url( 'admin.php?page=ktpwp-woocommerce-sync' ) );
			exit;
		}
		$this->ensure_order_table_ready();
		$synced = 0;
		$args   = array(
			'limit'    => 500,
			'orderby'  => 'date',
			'order'    => 'DESC',
			'return'   => 'ids',
		);
		$ids = wc_get_orders( $args );
		$ids = is_array( $ids ) ? $ids : array();
		foreach ( $ids as $order_id ) {
			$before = $this->get_ktp_order_id_by_wc_order_id( (int) $order_id );
			$this->sync_order_to_ktp( (int) $order_id, null );
			$after = $this->get_ktp_order_id_by_wc_order_id( (int) $order_id );
			if ( ! $before && $after ) {
				$synced++;
			}
		}
		set_transient( 'ktpwp_wc_sync_message', sprintf( __( '%d 件の注文を KantanPro に同期しました。', 'ktpwp' ), $synced ), 30 );
		if ( $synced === 0 && count( $ids ) > 0 ) {
			$last_err = get_transient( 'ktpwp_wc_sync_last_error' );
			delete_transient( 'ktpwp_wc_sync_last_error' );
			$err_msg = __( '同期できた注文が0件でした。', 'ktpwp' );
			if ( $last_err ) {
				$err_msg .= ' ' . __( 'データベースエラー:', 'ktpwp' ) . ' ' . $last_err;
				$err_msg .= ' ' . __( 'KantanPro を一度無効化して再有効化し、受注テーブルを作成・更新してから再度お試しください。', 'ktpwp' );
			}
			set_transient( 'ktpwp_wc_sync_error', $err_msg, 60 );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=ktpwp-woocommerce-sync' ) );
		exit;
	}

	/**
	 * 顧客未設定の WooCommerce 連携受注件数を取得
	 *
	 * @return int
	 */
	public function count_orders_without_client(): int {
		if ( ! $this->order_table_has_external_columns() ) {
			return 0;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'ktp_order';
		$count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM `{$table}` WHERE external_source = 'woocommerce' AND (client_id = 0 OR client_id IS NULL)"
		);
		return $count;
	}

	/**
	 * 既存の WooCommerce 連携受注（顧客未設定）を一括で顧客紐付け
	 */
	public function handle_link_woocommerce_clients(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( '権限がありません。', 'ktpwp' ) );
		}
		check_admin_referer( 'ktpwp_link_wc_clients' );
		$this->ensure_order_table_ready();
		if ( ! $this->order_table_has_external_columns() ) {
			set_transient( 'ktpwp_wc_sync_error', __( '受注テーブルに external_source / external_order_id がありません。', 'ktpwp' ), 60 );
			wp_safe_redirect( admin_url( 'admin.php?page=ktpwp-woocommerce-sync' ) );
			exit;
		}
		global $wpdb;
		$table   = $wpdb->prefix . 'ktp_order';
		$rows    = $wpdb->get_results(
			"SELECT id, external_order_id FROM `{$table}` WHERE external_source = 'woocommerce' AND (client_id = 0 OR client_id IS NULL)",
			ARRAY_A
		);
		$rows    = is_array( $rows ) ? $rows : array();
		$linked  = 0;
		$order_manager = KTPWP_Order::get_instance();
		foreach ( $rows as $row ) {
			$ktp_id   = (int) $row['id'];
			$wc_id    = (int) $row['external_order_id'];
			$order    = wc_get_order( $wc_id );
			if ( ! $order || ! $order instanceof WC_Order ) {
				continue;
			}

			$resolved = $this->resolve_client_from_wc_order( $order );
			if ( ! $resolved ) {
				continue;
			}

			$client_id     = (int) $resolved['client_id'];
			$department_id = ! empty( $resolved['department_id'] ) ? (int) $resolved['department_id'] : 0;
			$customer_name = $resolved['customer_name'];
			$user_name     = $resolved['user_name'];
			$user_name     = $user_name !== '' ? $user_name : $customer_name;
			$order_number  = $order->get_order_number();
			$project_name  = 'WC #' . $order_number;

			$update_data = array(
				'client_id'      => $client_id,
				'customer_name'  => $customer_name,
				'user_name'      => $user_name,
				'project_name'   => $project_name,
				'progress'       => 3,
				'payment_timing' => 'prepay',
			);
			if ( $department_id > 0 ) {
				$update_data['client_department_id'] = $department_id;
			}

			$ok = $order_manager->update_order( $ktp_id, $update_data );
			if ( $ok ) {
				$linked++;
				$this->replace_invoice_items_from_wc_order( $ktp_id, $order );
			}
		}
		set_transient( 'ktpwp_wc_sync_message', sprintf( __( '顧客未設定の WooCommerce 連携受注 %d 件を顧客に紐付けました。', 'ktpwp' ), $linked ), 30 );
		wp_safe_redirect( admin_url( 'admin.php?page=ktpwp-woocommerce-sync' ) );
		exit;
	}

	/**
	 * WooCommerce 連携受注を全件対象に顧客を再紐付け（既に紐付いている受注も上書き）
	 * 未紐付きが0件でも再度実行できる。
	 */
	public function handle_relink_woocommerce_clients(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( '権限がありません。', 'ktpwp' ) );
		}
		check_admin_referer( 'ktpwp_relink_wc_clients' );
		$this->ensure_order_table_ready();
		if ( ! $this->order_table_has_external_columns() ) {
			set_transient( 'ktpwp_wc_sync_error', __( '受注テーブルに external_source / external_order_id がありません。', 'ktpwp' ), 60 );
			wp_safe_redirect( admin_url( 'admin.php?page=ktpwp-woocommerce-sync' ) );
			exit;
		}
		global $wpdb;
		$table   = $wpdb->prefix . 'ktp_order';
		$rows    = $wpdb->get_results(
			"SELECT id, external_order_id FROM `{$table}` WHERE external_source = 'woocommerce'",
			ARRAY_A
		);
		$rows    = is_array( $rows ) ? $rows : array();
		$linked  = 0;
		$order_manager = KTPWP_Order::get_instance();
		foreach ( $rows as $row ) {
			$ktp_id   = (int) $row['id'];
			$wc_id    = (int) $row['external_order_id'];
			$order    = wc_get_order( $wc_id );
			if ( ! $order || ! $order instanceof WC_Order ) {
				continue;
			}

			$resolved = $this->resolve_client_from_wc_order( $order );
			if ( ! $resolved ) {
				continue;
			}

			$client_id     = (int) $resolved['client_id'];
			$department_id = ! empty( $resolved['department_id'] ) ? (int) $resolved['department_id'] : 0;
			$customer_name = $resolved['customer_name'];
			$user_name     = $resolved['user_name'];
			$user_name     = $user_name !== '' ? $user_name : $customer_name;
			$order_number  = $order->get_order_number();
			$project_name  = 'WC #' . $order_number;

			$update_data = array(
				'client_id'      => $client_id,
				'customer_name'  => $customer_name,
				'user_name'      => $user_name,
				'project_name'   => $project_name,
				'progress'       => 3,
				'payment_timing' => 'prepay',
			);
			if ( $department_id > 0 ) {
				$update_data['client_department_id'] = $department_id;
			}

			$ok = $order_manager->update_order( $ktp_id, $update_data );
			if ( $ok ) {
				$linked++;
				$this->replace_invoice_items_from_wc_order( $ktp_id, $order );
			}
		}
		set_transient( 'ktpwp_wc_sync_message', sprintf( __( 'WooCommerce 連携受注 %d 件を顧客に再紐付けしました。', 'ktpwp' ), $linked ), 30 );
		wp_safe_redirect( admin_url( 'admin.php?page=ktpwp-woocommerce-sync' ) );
		exit;
	}
}
