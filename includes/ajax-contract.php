<?php
/**
 * 定期契約 AJAX 処理
 *
 * @package KTPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 権限チェック
 *
 * @return bool
 */
function ktpwp_contract_ajax_can_manage() {
	return current_user_can( 'edit_posts' ) || current_user_can( 'ktpwp_access' );
}

/**
 * 契約保存
 */
function ktp_save_contract_ajax() {
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'ktp_contract_nonce' ) ) {
		wp_send_json_error( __( 'セキュリティチェックに失敗しました。', 'ktpwp' ) );
	}

	if ( ! ktpwp_contract_ajax_can_manage() ) {
		wp_send_json_error( __( '権限がありません。', 'ktpwp' ) );
	}

	if ( ! class_exists( 'KTPWP_Contract_DB' ) ) {
		wp_send_json_error( __( '定期契約機能が利用できません。', 'ktpwp' ) );
	}

	$db = KTPWP_Contract_DB::get_instance();

	$initial_fees_raw = isset( $_POST['initial_fees'] ) ? wp_unslash( $_POST['initial_fees'] ) : '[]';
	$initial_fees     = json_decode( $initial_fees_raw, true );
	if ( ! is_array( $initial_fees ) ) {
		$initial_fees = array();
	}

	$data = array(
		'id'                 => absint( $_POST['contract_id'] ?? 0 ),
		'client_id'          => absint( $_POST['client_id'] ?? 0 ),
		'service_id'         => absint( $_POST['service_id'] ?? 0 ),
		'contract_name'      => sanitize_text_field( wp_unslash( $_POST['contract_name'] ?? '' ) ),
		'amount'             => floatval( $_POST['amount'] ?? 0 ),
		'billing_cycle'      => sanitize_key( wp_unslash( $_POST['billing_cycle'] ?? 'monthly' ) ),
		'billing_day'        => absint( $_POST['billing_day'] ?? 1 ),
		'payment_due_mode'   => sanitize_key( wp_unslash( $_POST['payment_due_mode'] ?? 'contract' ) ),
		'start_date'         => sanitize_text_field( wp_unslash( $_POST['start_date'] ?? '' ) ),
		'end_date'           => sanitize_text_field( wp_unslash( $_POST['end_date'] ?? '' ) ),
		'status'             => sanitize_key( wp_unslash( $_POST['status'] ?? 'active' ) ),
		'send_reminder_mail' => ! empty( $_POST['send_reminder_mail'] ) ? 1 : 0,
		'memo'               => sanitize_textarea_field( wp_unslash( $_POST['memo'] ?? '' ) ),
	);

	$result = $db->save_contract( $data, $initial_fees );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( $result->get_error_message() );
	}

	wp_send_json_success(
		array(
			'contract_id' => (int) $result,
			'message'       => __( '定期契約を保存しました。', 'ktpwp' ),
		)
	);
}

/**
 * 契約取得（編集用）
 */
function ktp_get_contract_ajax() {
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'ktp_contract_nonce' ) ) {
		wp_send_json_error( __( 'セキュリティチェックに失敗しました。', 'ktpwp' ) );
	}

	if ( ! ktpwp_contract_ajax_can_manage() ) {
		wp_send_json_error( __( '権限がありません。', 'ktpwp' ) );
	}

	$contract_id = absint( $_POST['contract_id'] ?? 0 );
	$client_id   = absint( $_POST['client_id'] ?? 0 );

	if ( $contract_id <= 0 || $client_id <= 0 || ! class_exists( 'KTPWP_Contract_DB' ) ) {
		wp_send_json_error( __( '契約が見つかりません。', 'ktpwp' ) );
	}

	$db       = KTPWP_Contract_DB::get_instance();
	$payload  = $db->get_contract_payload( $contract_id );

	if ( ! $payload || (int) $payload['client_id'] !== $client_id ) {
		wp_send_json_error( __( '契約が見つかりません。', 'ktpwp' ) );
	}

	wp_send_json_success( $payload );
}

/**
 * 契約削除
 */
function ktp_delete_contract_ajax() {
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'ktp_contract_nonce' ) ) {
		wp_send_json_error( __( 'セキュリティチェックに失敗しました。', 'ktpwp' ) );
	}

	if ( ! ktpwp_contract_ajax_can_manage() ) {
		wp_send_json_error( __( '権限がありません。', 'ktpwp' ) );
	}

	$contract_id = absint( $_POST['contract_id'] ?? 0 );
	$client_id   = absint( $_POST['client_id'] ?? 0 );

	if ( $contract_id <= 0 || $client_id <= 0 || ! class_exists( 'KTPWP_Contract_DB' ) ) {
		wp_send_json_error( __( '契約が見つかりません。', 'ktpwp' ) );
	}

	$db     = KTPWP_Contract_DB::get_instance();
	$result = $db->delete_contract( $contract_id, $client_id );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( $result->get_error_message() );
	}

	wp_send_json_success( array( 'message' => __( '定期契約を削除しました。', 'ktpwp' ) ) );
}

add_action( 'wp_ajax_ktp_save_contract', 'ktp_save_contract_ajax' );
add_action( 'wp_ajax_ktp_get_contract', 'ktp_get_contract_ajax' );
add_action( 'wp_ajax_ktp_delete_contract', 'ktp_delete_contract_ajax' );
