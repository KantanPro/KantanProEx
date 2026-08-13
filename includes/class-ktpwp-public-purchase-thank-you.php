<?php
/**
 * 公開商品の Stripe 決済完了（サンクス）ページ。
 *
 * @package KTPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'KTPWP_Public_Purchase_Thank_You' ) ) {

	/**
	 * サンクス固定ページの自動生成とショートコード表示。
	 */
	class KTPWP_Public_Purchase_Thank_You {

		const OPTION_PAGE_ID = 'ktpwp_public_purchase_thank_you_page_id';

		const PAGE_SLUG = 'ktpwp-purchase-thank-you';

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
			add_action( 'init', array( $this, 'maybe_ensure_page' ), 20 );
		}

		/**
		 * Stripe 有効時にサンクスページを用意する。
		 *
		 * @return void
		 */
		public function maybe_ensure_page() {
			if ( ! class_exists( 'KTPWP_Stripe_Billing' ) || ! KTPWP_Stripe_Billing::is_enabled() ) {
				return;
			}

			self::ensure_page();
		}

		/**
		 * サンクスページ ID を返す（なければ作成）。
		 *
		 * @return int
		 */
		public static function ensure_page() {
			$page_id = absint( get_option( self::OPTION_PAGE_ID, 0 ) );
			if ( $page_id > 0 ) {
				$post = get_post( $page_id );
				if ( $post instanceof WP_Post && $post->post_status !== 'trash' ) {
					return $page_id;
				}
			}

			$existing = get_page_by_path( self::PAGE_SLUG, OBJECT, 'page' );
			if ( $existing instanceof WP_Post ) {
				update_option( self::OPTION_PAGE_ID, (int) $existing->ID, false );
				return (int) $existing->ID;
			}

			$page_id = wp_insert_post(
				array(
					'post_title'   => __( 'ご購入ありがとうございました', 'ktpwp' ),
					'post_name'    => self::PAGE_SLUG,
					'post_content' => '[ktpwp_public_purchase_thank_you]',
					'post_status'  => 'publish',
					'post_type'    => 'page',
					'post_author'  => self::resolve_page_author_id(),
				),
				true
			);

			if ( is_wp_error( $page_id ) || ! $page_id ) {
				return 0;
			}

			update_option( self::OPTION_PAGE_ID, (int) $page_id, false );

			return (int) $page_id;
		}

		/**
		 * サンクスページ URL。
		 *
		 * @return string
		 */
		public static function get_page_url() {
			$page_id = self::ensure_page();
			if ( $page_id <= 0 ) {
				return home_url( '/' );
			}

			$url = get_permalink( $page_id );
			return is_string( $url ) && $url !== '' ? $url : home_url( '/' );
		}

		/**
		 * Stripe success_url 用（{CHECKOUT_SESSION_ID} は Stripe が展開する）。
		 *
		 * @return string
		 */
		public static function get_success_url() {
			return add_query_arg(
				array(
					'session_id' => '{CHECKOUT_SESSION_ID}',
				),
				self::get_page_url()
			);
		}

		/**
		 * Stripe cancel_url 用（キャンセル時はサンクスページで失敗表示）。
		 *
		 * @param string $return_url 「戻る」リンク先（商品一覧など）。
		 * @return string
		 */
		public static function get_cancel_url( $return_url = '' ) {
			$args = array(
				'ktp_checkout' => 'cancelled',
			);

			$return_url = esc_url_raw( (string) $return_url );
			if ( $return_url !== '' ) {
				$args['return_url'] = $return_url;
			}

			return add_query_arg( $args, self::get_page_url() );
		}

		/**
		 * ショートコード出力。
		 *
		 * @param array<string, string> $atts Attributes.
		 * @return string
		 */
		public function render_shortcode( $atts = array() ) {
			unset( $atts );

			$checkout_flag = isset( $_GET['ktp_checkout'] ) ? sanitize_text_field( wp_unslash( $_GET['ktp_checkout'] ) ) : '';
			$session_id    = isset( $_GET['session_id'] ) ? sanitize_text_field( wp_unslash( $_GET['session_id'] ) ) : '';
			$return_url    = isset( $_GET['return_url'] ) ? esc_url_raw( wp_unslash( $_GET['return_url'] ) ) : '';

			$is_failure = in_array( $checkout_flag, array( 'cancelled', 'failed' ), true );

			if ( $session_id !== '' ) {
				$context = $this->resolve_session_context( $session_id );
				if ( $return_url === '' && ! empty( $context['return_url'] ) ) {
					$return_url = (string) $context['return_url'];
				}
				if ( ! $is_failure && empty( $context['paid'] ) ) {
					$is_failure = true;
				}
			}

			$modifier = $is_failure ? ' ktpwp-purchase-thank-you--failed' : '';
			$html     = '<div class="ktpwp-purchase-thank-you' . $modifier . '">';

			if ( $is_failure ) {
				$html .= '<p class="ktpwp-purchase-thank-you__lead ktpwp-purchase-thank-you__lead--error">' . esc_html__( '決済できませんでした。もう一度お試しください。', 'ktpwp' ) . '</p>';
			} else {
				$html .= '<h2 class="ktpwp-purchase-thank-you__title">' . esc_html__( 'ご購入ありがとうございました', 'ktpwp' ) . '</h2>';
			}

			if ( $return_url !== '' ) {
				$html .= '<p class="ktpwp-purchase-thank-you__actions"><a class="ktpwp-purchase-thank-you__back" href="' . esc_url( $return_url ) . '">' . esc_html__( '商品一覧へ戻る', 'ktpwp' ) . '</a></p>';
			}

			$html .= '</div>';

			return $html;
		}

		/**
		 * Checkout Session から表示用コンテキストを組み立てる。
		 *
		 * @param string $session_id Stripe Checkout Session ID.
		 * @return array{paid:bool,order_id:int,product_label:string,return_url:string}
		 */
		private function resolve_session_context( $session_id ) {
			$context = array(
				'paid'          => false,
				'order_id'      => 0,
				'product_label' => '',
				'return_url'    => '',
			);

			if ( $session_id === '' || ! class_exists( 'KTPWP_Stripe_Billing' ) || ! KTPWP_Stripe_Billing::is_enabled() ) {
				return $context;
			}

			if ( ! class_exists( '\Stripe\StripeClient' ) ) {
				return $context;
			}

			$stripe_billing = KTPWP_Stripe_Billing::get_instance();

			try {
				$stripe  = new \Stripe\StripeClient( KTPWP_Stripe_Billing::get_secret_key() );
				$session = $stripe->checkout->sessions->retrieve(
					$session_id,
					array(
						'expand' => array( 'payment_intent' ),
					)
				);
			} catch ( Exception $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					ktpwp_debug_log( 'KTPWP purchase thank you session: ' . $e->getMessage() );
				}
				return $context;
			}

			$metadata = $stripe_billing->get_checkout_session_metadata( $session );
			$order_id = isset( $metadata['ktp_order_id'] ) ? absint( $metadata['ktp_order_id'] ) : 0;
			if ( $order_id <= 0 ) {
				$order_id = $stripe_billing->find_order_id_by_checkout_session_id( $session_id );
			}

			$context['order_id']   = $order_id;
			$context['return_url'] = isset( $metadata['ktp_return_url'] ) ? esc_url_raw( (string) $metadata['ktp_return_url'] ) : '';

			$is_public_purchase = ! empty( $metadata['ktp_public_purchase'] ) || $order_id > 0;
			if ( ! $is_public_purchase ) {
				return $context;
			}

			if ( $order_id > 0 ) {
				$stripe_billing->sync_public_checkout_session_for_order( $order_id, $session );
				$context['paid'] = $this->is_order_paid( $order_id ) || $stripe_billing->is_checkout_session_paid( $session );
			} else {
				$context['paid'] = $stripe_billing->is_checkout_session_paid( $session );
			}

			return $context;
		}

		/**
		 * 受注が入金済みかどうか。
		 *
		 * @param int $order_id 受注 ID。
		 * @return bool
		 */
		private function is_order_paid( $order_id ) {
			global $wpdb;

			$order_id = absint( $order_id );
			if ( $order_id <= 0 ) {
				return false;
			}

			$table = $wpdb->prefix . 'ktp_order';
			$cols  = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`", 0 );
			if ( ! is_array( $cols ) || ! in_array( 'stripe_paid_at', $cols, true ) ) {
				return false;
			}

			$paid_at = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT stripe_paid_at FROM {$table} WHERE id = %d",
					$order_id
				)
			);

			return ! empty( $paid_at );
		}

		/**
		 * 固定ページ作成時の著者 ID。
		 *
		 * @return int
		 */
		private static function resolve_page_author_id() {
			$users = get_users(
				array(
					'role'   => 'administrator',
					'number' => 1,
					'fields' => array( 'ID' ),
				)
			);

			if ( ! empty( $users[0]->ID ) ) {
				return (int) $users[0]->ID;
			}

			return (int) get_current_user_id();
		}
	}
}
