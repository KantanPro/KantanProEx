<?php
/**
 * KantanProEX（WP）のユーザー登録（任意）
 *
 * 登録すると、顧客・協力会社へのメールの署名の後ろに最初から入る宣伝文が入らなくなる。
 * ライセンス管理ではない。登録しなくても機能の制限はなく、検証も行わない。
 * 登録したメールアドレスは、kantanpro.com のニュースレター（MailPoet「KantanProEX（WP）」）に裏で登録する。
 * 登録していないサイトからは、何も送らない。
 *
 * デスクトップ版（KTP_DESKTOP）は同梱の mu-plugin が同じ仕組みを持つため、ここでは何もしない。
 *
 * @package KantanPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KTPWP_User_Registration {

	const OPTION         = 'ktpwp_user_registration';
	const PAGE_SLUG      = 'ktp-user-registration';
	const CRON_HOOK      = 'ktpwp_user_registration_sync';
	const ENDPOINT       = 'https://www.kantanpro.com/wp-json/kpdg/v1/subscribe';
	const LIST_ID        = 8; // MailPoet「KantanProEX（WP）」。この秘密はこのリストにしか登録できない。
	// リリース zip を作るとき（create_release_zip.sh）に実際の値へ差し替える。ソースには秘密を置かない。
	const SHARED_SECRET  = '__KTPWP_REG_SECRET__';

	/**
	 * フックを登録する。
	 */
	public static function init() {
		if ( defined( 'KTP_DESKTOP' ) ) {
			return;
		}
		add_filter( 'ktpwp_mail_signature', array( __CLASS__, 'append_promotion' ) );
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 99 );
		add_action( self::CRON_HOOK, array( __CLASS__, 'sync_newsletter' ) );
		add_action( 'wp_footer', array( __CLASS__, 'print_hint_script' ), 99 );
	}

	/**
	 * 登録済みなら登録したメールアドレス、未登録なら空文字。
	 *
	 * @return string
	 */
	public static function registered_email() {
		$saved = get_option( self::OPTION );
		$email = is_array( $saved ) && ! empty( $saved['email'] ) ? sanitize_email( $saved['email'] ) : '';
		return is_email( $email ) ? $email : '';
	}

	/**
	 * 未登録のときだけ、署名の後ろに宣伝文を足す。
	 *
	 * @param string $signature 署名（会社情報）。
	 * @return string
	 */
	public static function append_promotion( $signature ) {
		if ( '' !== self::registered_email() ) {
			return $signature;
		}
		return $signature . "\n\n――\nこのメールは「KantanProEX（WP）」で作成しています。\nhttps://www.kantanpro.com/";
	}

	/**
	 * 登録メールアドレスを kantanpro.com のニュースレターに登録する。
	 *
	 * @param string $email メールアドレス。
	 * @return bool
	 */
	private static function subscribe( $email ) {
		if ( false !== strpos( self::SHARED_SECRET, '__KTPWP_REG_SECRET__' ) ) {
			return false; // 差し替え前（開発中のソース）は送らない。
		}
		$res = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => 15,
				'body'    => array(
					'email'  => $email,
					'list'   => self::LIST_ID,
					'secret' => self::SHARED_SECRET,
					'source' => 'KantanProEX-WP',
				),
			)
		);
		return ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res );
	}

	/**
	 * 登録済みでニュースレターに未送信なら送る。失敗したら1時間後に再試行する。
	 */
	public static function sync_newsletter() {
		$saved = get_option( self::OPTION );
		if ( ! is_array( $saved ) || empty( $saved['email'] ) || ! empty( $saved['newsletter'] ) ) {
			return;
		}
		if ( self::subscribe( $saved['email'] ) ) {
			$saved['newsletter'] = true;
			update_option( self::OPTION, $saved, false );
		} elseif ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + HOUR_IN_SECONDS, self::CRON_HOOK );
		}
	}

	/**
	 * 設定メニューに「ユーザー登録」を追加する。
	 */
	public static function add_menu() {
		add_submenu_page(
			'ktp-settings',
			__( 'ユーザー登録', 'ktpwp' ),
			__( 'ユーザー登録', 'ktpwp' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * 登録画面。
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$message  = '';
		$is_error = false;
		if ( isset( $_POST['ktpwp_reg_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ktpwp_reg_nonce'] ) ), 'ktpwp_user_registration' ) ) {
			if ( isset( $_POST['ktpwp_reg_clear'] ) ) {
				delete_option( self::OPTION );
				$message = __( '登録を解除しました。', 'ktpwp' );
			} else {
				$email = isset( $_POST['ktpwp_reg_email'] ) ? sanitize_email( wp_unslash( $_POST['ktpwp_reg_email'] ) ) : '';
				if ( is_email( $email ) ) {
					// 宣伝文を消す登録は、通信できなくてもその場で完了させる。ニュースレターへの登録は裏で行い、失敗したら後で再送する。
					update_option( self::OPTION, array( 'email' => $email, 'newsletter' => false ), false );
					self::sync_newsletter();
					$message = __( '登録しました。以後、メールの署名の後ろに宣伝文は入りません。', 'ktpwp' );
				} else {
					$message  = __( 'メールアドレスを正しく入力してください。', 'ktpwp' );
					$is_error = true;
				}
			}
		}
		$registered = self::registered_email();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'ユーザー登録', 'ktpwp' ); ?></h1>
			<?php if ( $message ) : ?>
				<div class="notice <?php echo $is_error ? 'notice-error' : 'notice-success'; ?>"><p><?php echo esc_html( $message ); ?></p></div>
			<?php endif; ?>
			<p><?php esc_html_e( 'ユーザー登録は任意です。登録しなくても、すべての機能をそのまま使えます。', 'ktpwp' ); ?></p>
			<p><?php esc_html_e( 'ユーザー登録をすると、次のメリットがあります。', 'ktpwp' ); ?></p>
			<ul style="list-style:disc;margin-left:1.5em">
				<li><?php esc_html_e( '顧客・協力会社へのメールの署名の後ろに入る「このメールは KantanProEX（WP）で作成しています」の宣伝文が、最初から入らなくなります（毎回消さなくて済みます）。', 'ktpwp' ); ?></li>
				<li><?php esc_html_e( 'アップデート情報や、便利な活用方法などをメールでお届けします（配信の停止は、届いたメールのリンクからいつでもできます）。', 'ktpwp' ); ?></li>
			</ul>
			<form method="post">
				<?php wp_nonce_field( 'ktpwp_user_registration', 'ktpwp_reg_nonce' ); ?>
				<?php if ( '' !== $registered ) : ?>
					<p><strong><?php esc_html_e( '登録済み：', 'ktpwp' ); ?></strong><?php echo esc_html( $registered ); ?></p>
					<p><button type="submit" name="ktpwp_reg_clear" value="1" class="button"><?php esc_html_e( '登録を解除する', 'ktpwp' ); ?></button></p>
				<?php else : ?>
					<p><input type="email" name="ktpwp_reg_email" class="regular-text" placeholder="<?php esc_attr_e( 'メールアドレス', 'ktpwp' ); ?>" required></p>
					<p><button type="submit" class="button button-primary"><?php esc_html_e( '登録する', 'ktpwp' ); ?></button></p>
				<?php endif; ?>
			</form>
		</div>
		<?php
	}

	/**
	 * メール本文ポップアップに、登録で宣伝文が入らなくなることを小さく案内する（未登録のときだけ）。
	 */
	public static function print_hint_script() {
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) || '' !== self::registered_email() ) {
			return;
		}
		$url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		?>
<script id="ktpwp-reg-hint">
(function () {
	var url = <?php echo wp_json_encode( $url ); ?>;
	function add() {
		var ta = document.getElementById('email-body');
		if (!ta || document.getElementById('ktpwp-reg-hint-note')) { return; }
		var p = document.createElement('p');
		p.id = 'ktpwp-reg-hint-note';
		p.style.cssText = 'margin:6px 0 0;font-size:12px;color:#666';
		p.innerHTML = '署名の後ろの宣伝文は、ユーザー登録で入らなくなります（アップデート情報もお届け）→ <a href="' + url + '" style="color:#0073aa">登録する</a>';
		ta.parentNode.appendChild(p);
	}
	new MutationObserver(add).observe(document.body, { childList: true, subtree: true });
})();
</script>
		<?php
	}
}
