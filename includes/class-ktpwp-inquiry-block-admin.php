<?php
/**
 * 公開商品問い合わせブロックリスト（管理画面）
 *
 * @package KTPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class KTPWP_Inquiry_Block_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 20 );
	}

	public static function register_menu() {
		add_submenu_page(
			'ktp-settings',
			__( '問い合わせブロックリスト', 'ktpwp' ),
			__( '問い合わせブロック', 'ktpwp' ),
			'manage_options',
			'ktp-inquiry-block-list',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'この設定ページにアクセスする権限がありません。', 'ktpwp' ) );
		}

		if ( ! class_exists( 'KTPWP_Inquiry_Block' ) ) {
			require_once dirname( __FILE__ ) . '/class-ktpwp-inquiry-block.php';
		}

		KTPWP_Inquiry_Block::ensure_schema();

		if (
			isset( $_POST['ktp_unblock_client_id'], $_POST['ktp_inquiry_block_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ktp_inquiry_block_nonce'] ) ), 'ktp_inquiry_block_list' )
		) {
			$client_id = absint( $_POST['ktp_unblock_client_id'] );
			if ( $client_id > 0 && KTPWP_Inquiry_Block::unblock_for_client( $client_id ) ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'ブロックを解除しました。顧客タブに再表示されます。', 'ktpwp' ) . '</p></div>';
			} else {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'ブロック解除に失敗しました。', 'ktpwp' ) . '</p></div>';
			}
		}

		$rows = KTPWP_Inquiry_Block::get_blocked_list();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( '問い合わせブロックリスト', 'ktpwp' ); ?></h1>
			<p><?php esc_html_e( '公開商品からの問い合わせをブロックしたメールアドレスの一覧です。間違えてブロックした場合は「復活」で顧客タブに戻せます。', 'ktpwp' ); ?></p>
			<?php if ( empty( $rows ) ) : ?>
				<p><?php esc_html_e( '現在、ブロック中のメールアドレスはありません。', 'ktpwp' ); ?></p>
			<?php else : ?>
				<table class="widefat fixed striped">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'ID', 'ktpwp' ); ?></th>
							<th scope="col"><?php esc_html_e( '会社名', 'ktpwp' ); ?></th>
							<th scope="col"><?php esc_html_e( '担当者', 'ktpwp' ); ?></th>
							<th scope="col"><?php esc_html_e( 'メール', 'ktpwp' ); ?></th>
							<th scope="col"><?php esc_html_e( '該当顧客数', 'ktpwp' ); ?></th>
							<th scope="col"><?php esc_html_e( '操作', 'ktpwp' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<td><?php echo esc_html( (string) $row->id ); ?></td>
								<td><?php echo esc_html( (string) $row->company_name ); ?></td>
								<td><?php echo esc_html( (string) $row->name ); ?></td>
								<td><?php echo esc_html( (string) $row->email ); ?></td>
								<td><?php echo esc_html( (string) (int) $row->blocked_count ); ?></td>
								<td>
									<form method="post" style="margin:0;" onsubmit="return confirm('<?php echo esc_js( __( 'このメールアドレスのブロックを解除し、顧客タブに再表示しますか？', 'ktpwp' ) ); ?>');">
										<?php wp_nonce_field( 'ktp_inquiry_block_list', 'ktp_inquiry_block_nonce' ); ?>
										<input type="hidden" name="ktp_unblock_client_id" value="<?php echo esc_attr( (string) $row->id ); ?>">
										<button type="submit" class="button button-secondary"><?php esc_html_e( '復活', 'ktpwp' ); ?></button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
