<?php
/**
 * ナンス管理クラス
 *
 * プラグイン全体でナンス値を統一管理
 *
 * @package KTPWP
 * @since 1.0.0
 */

// セキュリティ: 直接アクセスを防止
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ナンス管理クラス
 */
class KTPWP_Nonce_Manager {

    /**
     * シングルトンインスタンス
     *
     * @var KTPWP_Nonce_Manager
     */
    private static $instance = null;

    /**
     * ナンス値キャッシュ
     *
     * @var array
     */
    private static $nonce_cache = array();

    /**
     * シングルトンインスタンス取得
     *
     * @return KTPWP_Nonce_Manager
     */
    public static function get_instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * シングルトンインスタンス取得（エイリアス）
     * 一般的な命名規則との互換性のため
     *
     * @return KTPWP_Nonce_Manager
     */
    public static function getInstance() {
        return self::get_instance();
    }

    /**
     * コンストラクタ
     */
    private function __construct() {
        // シングルトンパターンのため、コンストラクタは非公開
    }

    /**
     * 業務用ナンスを発行してよいユーザーか
     *
     * 業務用 Ajax エンドポイントはすべてログイン＋権限を必須としているため、
     * 未ログイン訪問者にナンスを出力する必要はない。
     * 未ログイン用ナンスは user_id=0 で生成され、誰の値でも検証を通過してしまうため、
     * 公開ページに出力してしまうと権限チェック漏れのハンドラがそのまま攻撃面になる。
     *
     * @return bool
     */
    public static function can_issue_business_nonce() {
        return function_exists( 'is_user_logged_in' ) && is_user_logged_in();
    }

    /**
     * 業務用ナンスを生成（未ログイン時は空文字）
     *
     * @param string $action ナンスアクション名。
     * @return string ナンス値。
     */
    public static function create_business_nonce( $action ) {
        if ( ! self::can_issue_business_nonce() ) {
            return '';
        }
        return wp_create_nonce( $action );
    }

    /**
     * 統一されたstaff_chatナンス値を取得
     *
     * @return string ナンス値
     */
    public function get_staff_chat_nonce() {
        if ( ! isset( self::$nonce_cache['staff_chat'] ) ) {
            self::$nonce_cache['staff_chat'] = self::create_business_nonce( 'ktpwp_staff_chat_nonce' );
        }
        return self::$nonce_cache['staff_chat'];
    }

    /**
     * 統一されたauto_saveナンス値を取得
     *
     * @return string ナンス値
     */
    public function get_auto_save_nonce() {
        if ( ! isset( self::$nonce_cache['auto_save'] ) ) {
            self::$nonce_cache['auto_save'] = self::create_business_nonce( 'ktpwp_auto_save_nonce' );
        }
        return self::$nonce_cache['auto_save'];
    }

    /**
     * 統一されたktp_ajaxナンス値を取得
     *
     * @return string ナンス値
     */
    public function get_ktp_ajax_nonce() {
        if ( ! isset( self::$nonce_cache['ktp_ajax'] ) ) {
            self::$nonce_cache['ktp_ajax'] = self::create_business_nonce( 'ktp_ajax_nonce' );
        }
        return self::$nonce_cache['ktp_ajax'];
    }

    /**
     * 統一されたAJAX設定データを取得
     *
     * @return array AJAX設定配列
     */
    public function get_unified_ajax_config() {
        return array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce' => $this->get_ktp_ajax_nonce(),
            'nonces' => array(
                'staff_chat' => $this->get_staff_chat_nonce(),
                'auto_save' => $this->get_auto_save_nonce(),
            ),
        );
    }

    /**
     * ナンスキャッシュをクリア（テスト用）
     */
    public function clear_cache() {
        self::$nonce_cache = array();
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            ktpwp_debug_log( 'KTPWP Nonce Manager: Cache cleared' );
        }
    }
}
