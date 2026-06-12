<?php
/**
 * Service database management class for KTPWP plugin
 *
 * Handles service data management including table creation,
 * data operations (CRUD), and security implementations.
 *
 * @package KTPWP
 * @subpackage Includes
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'KTPWP_Service_DB' ) ) {
	class KTPWP_Service_DB {
		/**
		 * Instance of this class
		 *
		 * @var KTPWP_Service_DB
		 */
		private static $instance = null;

		/**
		 * Get singleton instance
		 *
		 * @return KTPWP_Service_DB
		 */
		public static function get_instance() {
			if ( self::$instance === null ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Private constructor to prevent creating a new instance directly
		 */
		private function __construct() {
			// シングルトン
		}

		/**
		 * Get the service table schema.
		 *
		 * @return string The SQL for creating the service table.
		 */
		public function get_schema() {
			global $wpdb;
			$table_name = $wpdb->prefix . 'ktp_service';
			$charset_collate = $wpdb->get_charset_collate();

			// Column definitions with internationalization
			$sql = "CREATE TABLE {$table_name} (
				id MEDIUMINT(9) NOT NULL AUTO_INCREMENT,
				time DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
				service_name TINYTEXT,
				price DECIMAL(10,2) DEFAULT 0.00 NOT NULL,
				tax_rate DECIMAL(5,2) NULL DEFAULT NULL,
				unit VARCHAR(50) NOT NULL DEFAULT '',
				image_url VARCHAR(255),
				memo TEXT,
				search_field TEXT,
				frequency INT NOT NULL DEFAULT 0,
				category VARCHAR(100) NOT NULL DEFAULT '" . esc_sql( __( 'General', 'ktpwp' ) ) . "',
				is_public TINYINT(1) NOT NULL DEFAULT 0,
				contract_billing_cycle VARCHAR(20) NOT NULL DEFAULT 'none',
				PRIMARY KEY  (id)
			) {$charset_collate};";

			return $sql;
		}

		/**
		 * Create or update the service table.
		 * This method is kept for backward compatibility and direct calls,
		 * but the main activation hook now uses get_schema().
		 */
		public function create_table() {
			if ( ! function_exists( 'dbDelta' ) ) {
				require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			}
			$schema = $this->get_schema();
			dbDelta( $schema );
		}

		/**
		 * Update service table with POST data
		 *
		 * @param string $tab_name The table name suffix
		 * @return void
		 */
		public function update_table( $tab_name ) {
			if ( empty( $tab_name ) ) {
				return;
			}

			global $wpdb;
			$table_name = $wpdb->prefix . 'ktp_' . sanitize_key( $tab_name );

			// Only process POST requests
			if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
				return;
			}

			// Verify nonce for security
			if ( ! isset( $_POST['_ktp_service_nonce'] ) || ! wp_verify_nonce( $_POST['_ktp_service_nonce'], 'ktp_service_action' ) ) {
				wp_die( esc_html__( 'Security check failed.', 'ktpwp' ) );
			}

			// Sanitize and validate POST data
			$data_id = isset( $_POST['data_id'] ) ? absint( $_POST['data_id'] ) : 0;
			$query_post = isset( $_POST['query_post'] ) ? sanitize_text_field( $_POST['query_post'] ) : '';

			// Empty query_post should not be processed
			if ( empty( $query_post ) ) {
				return;
			}

			// Sanitize form fields
			$service_name = isset( $_POST['service_name'] ) ? sanitize_text_field( $_POST['service_name'] ) : '';
			$price = isset( $_POST['price'] ) ? floatval( $_POST['price'] ) : 0;
			$tax_rate = isset( $_POST['tax_rate'] ) && $_POST['tax_rate'] !== '' ? floatval( $_POST['tax_rate'] ) : null;
			$unit = isset( $_POST['unit'] ) ? sanitize_text_field( $_POST['unit'] ) : '';
			$memo = isset( $_POST['memo'] ) ? sanitize_textarea_field( $_POST['memo'] ) : '';
			$category = isset( $_POST['category'] ) ? sanitize_text_field( $_POST['category'] ) : '';
			$is_public = isset( $_POST['is_public'] ) && '1' === (string) $_POST['is_public'] ? 1 : 0;
			$contract_billing_cycle = class_exists( 'KTPWP_Contract_Billing_Cycle' )
				? KTPWP_Contract_Billing_Cycle::sanitize( isset( $_POST['contract_billing_cycle'] ) ? wp_unslash( $_POST['contract_billing_cycle'] ) : '' )
				: 'none';

			// Create search field value
			$search_field_value = implode(
                ', ',
                array(
					current_time( 'mysql' ),
					$service_name,
					$price,
					$tax_rate,
					$unit,
					$memo,
					$category,
                )
            );

			// Get last ID if data_id is 0
			if ( $data_id === 0 ) {
				$last_id = $wpdb->get_var(
                    $wpdb->prepare( "SELECT id FROM {$table_name} ORDER BY id DESC LIMIT %d", 1 )
				);
				$data_id = $last_id ? $last_id : 1;
			}

			// Handle different operations based on query_post
			switch ( $query_post ) {
				case 'update':
					if ( $data_id > 0 ) {
						$data = array(
							'service_name' => $service_name,
							'price' => $price,
							'tax_rate' => $tax_rate,
							'unit' => $unit,
							'memo' => $memo,
							'category' => $category,
							'is_public' => $is_public,
							'search_field' => $search_field_value,
						);

						if ( $this->service_table_has_contract_billing_cycle_column( $table_name ) ) {
							$data['contract_billing_cycle'] = $contract_billing_cycle;
						}

						$format = array( '%s', '%f', '%f', '%s', '%s', '%s', '%d', '%s' );
						if ( isset( $data['contract_billing_cycle'] ) ) {
							$format[] = '%s';
						}

						$wpdb->update(
                            $table_name,
                            $data,
                            array( 'id' => $data_id ),
                            $format,
                            array( '%d' )
						);
					}
					break;

				case 'new':
					return $this->handle_new_service( $tab_name );

				case 'istmode':
					// 追加モードの場合は何もしない（表示ロジックで処理される）
					return;

				case 'delete':
					return $this->handle_delete_service( $tab_name, $data_id );

				case 'duplicate':
					return $this->handle_duplicate_service( $tab_name, $data_id );

				case 'srcmode':
					// 詳細画面の「検索」ボタンは query_post=srcmode で送るため、検索モードとして扱う
					return $this->handle_search_operations( $tab_name, 'search' );

				case 'search':
				case 'search_execute':
				case 'search_cancel':
					return $this->handle_search_operations( $tab_name, $query_post );

				case 'upload_image':
					return $this->handle_upload_image( $tab_name, $data_id );

				case 'delete_image':
					return $this->handle_delete_image( $tab_name, $data_id );

				default:
					break;
			}
		}

		/**
		 * Handle creating a new service
		 *
		 * @param string $tab_name Table name suffix
		 * @return void
		 */
		private function handle_new_service( $tab_name ) {
			global $wpdb;
			$table_name = $wpdb->prefix . 'ktp_' . sanitize_key( $tab_name );

			// nonceを検証
			if ( ! isset( $_POST['_ktp_service_nonce'] ) || ! wp_verify_nonce( $_POST['_ktp_service_nonce'], 'ktp_service_action' ) ) {
				wp_die( esc_html__( 'Nonce verification failed.', 'ktpwp' ) );
			}

			// 新しいIDを取得
			$new_id_query = "SELECT COALESCE(MAX(id), 0) + 1 as new_id FROM {$table_name}";
			$new_id_result = $wpdb->get_row( $new_id_query );
			$new_id = $new_id_result && isset( $new_id_result->new_id ) ? intval( $new_id_result->new_id ) : 1;

			// フォームからのデータを取得
			$service_name = isset( $_POST['service_name'] ) ? sanitize_text_field( $_POST['service_name'] ) : esc_html__( '新しいサービス', 'ktpwp' );
			$price = isset( $_POST['price'] ) ? floatval( $_POST['price'] ) : 0;
			$tax_rate = isset( $_POST['tax_rate'] ) && $_POST['tax_rate'] !== '' ? floatval( $_POST['tax_rate'] ) : null;
			$unit = isset( $_POST['unit'] ) ? sanitize_text_field( $_POST['unit'] ) : '';
			$memo = isset( $_POST['memo'] ) ? sanitize_textarea_field( $_POST['memo'] ) : '';
			$category = isset( $_POST['category'] ) ? sanitize_text_field( $_POST['category'] ) : '';
			$is_public = isset( $_POST['is_public'] ) && '1' === (string) $_POST['is_public'] ? 1 : 0;
			$contract_billing_cycle = class_exists( 'KTPWP_Contract_Billing_Cycle' )
				? KTPWP_Contract_Billing_Cycle::sanitize( isset( $_POST['contract_billing_cycle'] ) ? wp_unslash( $_POST['contract_billing_cycle'] ) : '' )
				: 'none';

			// 検索フィールド値を作成
			$search_field_value = implode(
                ', ',
                array(
					current_time( 'mysql' ),
					$service_name,
					$price,
					$tax_rate,
					$unit,
					$memo,
					$category,
                )
            );

			// 新規データを挿入
			$insert_data = array(
					'id' => $new_id,
					'time' => current_time( 'mysql' ),
					'service_name' => $service_name,
					'price' => $price,
					'tax_rate' => $tax_rate,
					'unit' => $unit,
					'memo' => $memo,
					'category' => $category,
					'is_public' => $is_public,
					'image_url' => '',
					'frequency' => 0,
					'search_field' => $search_field_value,
			);
			$insert_format = array( '%d', '%s', '%s', '%f', '%f', '%s', '%s', '%s', '%d', '%s', '%d', '%s' );

			if ( $this->service_table_has_contract_billing_cycle_column( $table_name ) ) {
				$insert_data['contract_billing_cycle'] = $contract_billing_cycle;
				$insert_format[] = '%s';
			}

			$insert_result = $wpdb->insert(
                $table_name,
                $insert_data,
                $insert_format
			);

			if ( $insert_result === false ) {
				echo "<script>alert('" . esc_js( esc_html__( '新規追加に失敗しました。', 'ktpwp' ) ) . "');</script>";
			} else {

				// 元のページ（ショートコードが配置された固定ページ）にリダイレクト
				$current_page_url = wp_get_referer();
				if ( ! $current_page_url ) {
					// refererが取得できない場合は、動的パーマリンク取得を使用
					$current_page_url = KTPWP_Main::get_current_page_base_url();
				}

				$redirect_url = add_query_arg(
                    array(
						'tab_name' => $tab_name,
						'data_id' => $new_id,
						'message' => 'added',
                    ),
                    $current_page_url
                );

				// PHPリダイレクトを使用（JavaScriptではなく）
				wp_redirect( $redirect_url );
				exit;
			}
		}

		/**
		 * Handle deleting a service
		 *
		 * @param string $tab_name Table name suffix
		 * @param int    $data_id Data ID
		 * @return void
		 */
		private function handle_delete_service( $tab_name, $data_id ) {
			global $wpdb;
			$table_name = $wpdb->prefix . 'ktp_' . sanitize_key( $tab_name );

			// nonceを検証
			if ( ! isset( $_POST['_ktp_service_nonce'] ) || ! wp_verify_nonce( $_POST['_ktp_service_nonce'], 'ktp_service_action' ) ) {
				wp_die( esc_html__( 'Nonce verification failed.', 'ktpwp' ) );
			}

			if ( $data_id > 0 ) {
				$delete_result = $wpdb->delete(
                    $table_name,
                    array( 'id' => $data_id ),
                    array( '%d' )
				);

				if ( $delete_result === false ) {
					echo "<script>
                document.addEventListener('DOMContentLoaded', function() {
                    showErrorNotification('" . esc_js( esc_html__( '削除に失敗しました。', 'ktpwp' ) ) . "');
                });
                </script>";
				} else {
					// 削除後は最新のレコード（ID降順のトップ）にリダイレクト
					$next_record = $wpdb->get_row( "SELECT id FROM {$table_name} ORDER BY id DESC LIMIT 1" );
					$next_id = $next_record ? $next_record->id : 0;

					// 統一されたリダイレクト処理（wp_redirect使用）
					$redirect_url = add_query_arg(
                        array(
							'tab_name' => $tab_name,
							'data_id' => $next_id,
							'message' => 'deleted',
                        ),
                        wp_get_referer()
                    );

					wp_redirect( $redirect_url );
					exit;
				}
			}

			$wpdb->query( 'UNLOCK TABLES;' );
		}

		/**
		 * Handle duplicating a service
		 *
		 * @param string $tab_name Table name suffix
		 * @param int    $data_id Data ID
		 * @return void
		 */
		private function handle_duplicate_service( $tab_name, $data_id ) {
			global $wpdb;
			$table_name = $wpdb->prefix . 'ktp_' . sanitize_key( $tab_name );

			// POSTリクエストのみ許可
			if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
				wp_die( esc_html__( 'Invalid request method.', 'ktpwp' ) );
			}

			// nonceを検証
			if ( ! isset( $_POST['_ktp_service_nonce'] ) || ! wp_verify_nonce( $_POST['_ktp_service_nonce'], 'ktp_service_action' ) ) {
				wp_die( esc_html__( 'Nonce verification failed.', 'ktpwp' ) );
			}

			if ( $data_id > 0 ) {
				// 元のデータを取得
				$original_data = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $data_id ) );

				if ( $original_data ) {
					// 新しいIDを取得（データが完全に0の場合は1から開始）
					$new_id_query = "SELECT COALESCE(MAX(id), 0) + 1 as new_id FROM {$table_name}";
					$new_id_result = $wpdb->get_row( $new_id_query );
					$new_id = $new_id_result && isset( $new_id_result->new_id ) ? intval( $new_id_result->new_id ) : 1;

					// データを複製して挿入
					$duplicate_data = array(
							'id' => $new_id,
							'time' => current_time( 'mysql' ),
							'service_name' => $original_data->service_name . esc_html__( ' (複製)', 'ktpwp' ),
							'price' => $original_data->price,
							'tax_rate' => $original_data->tax_rate,
							'unit' => $original_data->unit,
							'memo' => $original_data->memo,
							'category' => $original_data->category,
							'is_public' => isset( $original_data->is_public ) ? (int) $original_data->is_public : 0,
							'image_url' => $original_data->image_url,
							'frequency' => $original_data->frequency,
							'search_field' => $original_data->service_name . esc_html__( ' (複製)', 'ktpwp' ) . ', ' . $original_data->price . ', ' . ( $original_data->tax_rate ?? '' ) . ', ' . $original_data->unit . ', ' . $original_data->category,
					);
					$duplicate_format = array( '%d', '%s', '%s', '%f', '%f', '%s', '%s', '%s', '%d', '%s', '%d', '%s' );

					if ( $this->service_table_has_contract_billing_cycle_column( $table_name ) ) {
						$cycle_value = isset( $original_data->contract_billing_cycle ) ? $original_data->contract_billing_cycle : 'none';
						$duplicate_data['contract_billing_cycle'] = class_exists( 'KTPWP_Contract_Billing_Cycle' )
							? KTPWP_Contract_Billing_Cycle::sanitize( $cycle_value )
							: 'none';
						$duplicate_format[] = '%s';
					}

					$insert_result = $wpdb->insert(
                        $table_name,
                        $duplicate_data,
                        $duplicate_format
					);

					if ( $insert_result === false ) {
						// エラー処理は最小限に
					} else {
						// 成功時は複製されたレコードにリダイレクト
						$base_page_url = KTPWP_Main::get_current_page_base_url();
						$redirect_url = add_query_arg(
                            array(
								'tab_name' => $tab_name, // tab_nameを維持
								'data_id' => $new_id,    // 新しいIDに遷移
								'message' => 'duplicated', // 複製成功のメッセージパラメータ
                            ),
                            $base_page_url
                        );
						// 不要なPOST関連パラメータを削除
						$redirect_url = remove_query_arg( array( 'query_post', '_ktp_service_nonce', 'send_post' ), $redirect_url );

						wp_redirect( esc_url_raw( $redirect_url ) );
						exit;
					}
				}
			}

			$wpdb->query( 'UNLOCK TABLES;' );
		}

		/**
		 * Handle search operations
		 *
		 * @param string $tab_name Table name suffix
		 * @param string $query_post Query post type
		 * @return void
		 */
		private function handle_search_operations( $tab_name, $query_post ) {
			global $wpdb;
			$table_name = $wpdb->prefix . 'ktp_' . sanitize_key( $tab_name );

			// nonceを検証
			if ( ! isset( $_POST['_ktp_service_nonce'] ) || ! wp_verify_nonce( $_POST['_ktp_service_nonce'], 'ktp_service_action' ) ) {
				wp_die( esc_html__( 'Nonce verification failed.', 'ktpwp' ) );
			}

			if ( ! session_id() ) {
				ktpwp_safe_session_start();
			}

			if ( $query_post === 'search' ) {
				// 検索モードフラグをセット
				$_SESSION['ktp_service_search_mode'] = true;
				$_SESSION['ktp_service_search_message'] = esc_html__( '検索モードです。条件を入力して検索してください。', 'ktpwp' );
				$wpdb->query( 'UNLOCK TABLES;' );
				return;
			} elseif ( $query_post === 'search_cancel' ) {
				// 検索モードを解除
				unset( $_SESSION['ktp_service_search_mode'] );
				unset( $_SESSION['ktp_service_search_message'] );

				// リダイレクト先を参照元または現在のリクエストURLから組み立て（不正なリダイレクトを防ぐ）
				$redirect_base = wp_get_referer();
				if ( ! $redirect_base || $redirect_base === '' ) {
					$redirect_base = home_url( wp_unslash( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/' ) );
				}
				if ( ! $redirect_base ) {
					$redirect_base = KTPWP_Main::get_current_page_base_url();
				}
				$redirect_base = remove_query_arg( array( 'multiple_results', 'search_service_name', 'search_category', 'query_post', 'no_results' ), $redirect_base );
				$url = add_query_arg(
					array(
						'tab_name' => $tab_name,
						'message' => 'search_cancelled',
					),
					$redirect_base
				);

				$wpdb->query( 'UNLOCK TABLES;' );
				wp_safe_redirect( $url );
				exit;
			} elseif ( $query_post === 'search_execute' ) {
				$search_service_name = isset( $_POST['search_service_name'] ) ? sanitize_text_field( $_POST['search_service_name'] ) : '';
				$search_category = isset( $_POST['search_category'] ) ? sanitize_text_field( $_POST['search_category'] ) : '';

				// 検索条件の構築（顧客タブと同様: search_field が NULL でも service_name / category でヒット）
				$where_conditions = array();
				$where_values = array();

				if ( ! empty( $search_service_name ) ) {
					$where_conditions[] = '(COALESCE(service_name,\'\') LIKE %s OR COALESCE(search_field,\'\') LIKE %s)';
					$name_like = '%' . $wpdb->esc_like( $search_service_name ) . '%';
					$where_values[] = $name_like;
					$where_values[] = $name_like;
				}

				if ( ! empty( $search_category ) ) {
					$where_conditions[] = '(COALESCE(category,\'\') LIKE %s OR COALESCE(search_field,\'\') LIKE %s)';
					$cat_like = '%' . $wpdb->esc_like( $search_category ) . '%';
					$where_values[] = $cat_like;
					$where_values[] = $cat_like;
				}

				if ( empty( $where_conditions ) ) {
					// 未入力で検索実行した場合は0件時と同じ扱い（フォームを維持し該当なしメッセージを表示）
					$_SESSION['ktp_service_search_message'] = esc_html__( '該当するサービスが見つかりませんでした。条件を変更して再検索してください。', 'ktpwp' );
					$_SESSION['ktp_service_search_mode'] = true;
					$redirect_base = wp_get_referer();
					if ( ! $redirect_base || $redirect_base === '' ) {
						$redirect_base = home_url( wp_unslash( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/' ) );
					}
					if ( ! $redirect_base ) {
						$current_page_id = get_queried_object_id();
						$redirect_base = get_permalink( $current_page_id );
						if ( ! $redirect_base ) {
							global $wp;
							$redirect_base = home_url( add_query_arg( array(), $wp->request ) );
						}
					}
					$redirect_base = remove_query_arg( array( 'query_post', 'data_id', 'message', 'multiple_results', 'no_results' ), $redirect_base );
					$url = add_query_arg(
						array(
							'tab_name' => $tab_name,
							'query_post' => 'srcmode',
							'search_service_name' => $search_service_name,
							'search_category' => $search_category,
							'no_results' => '1',
						),
						$redirect_base
					);
					$wpdb->query( 'UNLOCK TABLES;' );
					wp_safe_redirect( $url );
					exit;
				} else {
					// 検索実行
					$where_clause = ' WHERE ' . implode( ' AND ', $where_conditions );
					$search_query = "SELECT * FROM {$table_name}" . $where_clause . ' ORDER BY id DESC';
					$search_results = $wpdb->get_results( $wpdb->prepare( $search_query, $where_values ) );

					$redirect_base = wp_get_referer();
					if ( ! $redirect_base || $redirect_base === '' ) {
						$redirect_base = home_url( wp_unslash( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/' ) );
					}
					if ( ! $redirect_base ) {
						$current_page_id = get_queried_object_id();
						$redirect_base = get_permalink( $current_page_id );
						if ( ! $redirect_base ) {
							global $wp;
							$redirect_base = home_url( add_query_arg( array(), $wp->request ) );
						}
					}

					if ( $search_results ) {
						$result_count = count( $search_results );
						// 検索モードを解除
						unset( $_SESSION['ktp_service_search_mode'] );
						unset( $_SESSION['ktp_service_search_message'] );

						if ( $result_count === 1 ) {
							// 1件のみ: その詳細にリダイレクト
							$first_result = $search_results[0];
							$url = add_query_arg(
								array(
									'tab_name' => $tab_name,
									'data_id' => $first_result->id,
									'message' => 'search_found',
								),
								$redirect_base
							);
							$wpdb->query( 'UNLOCK TABLES;' );
							wp_safe_redirect( $url );
							exit;
						} else {
							// 複数件: 顧客タブと同様にリダイレクトしてダイアログ表示
							$url = add_query_arg(
								array(
									'tab_name' => $tab_name,
									'multiple_results' => '1',
									'search_service_name' => $search_service_name,
									'search_category' => $search_category,
								),
								$redirect_base
							);
							wp_safe_redirect( $url );
							exit;
						}
					} else {
						// 検索結果が無い場合
						$_SESSION['ktp_service_search_message'] = esc_html__( '該当するサービスが見つかりませんでした。条件を変更して再検索してください。', 'ktpwp' );
						$_SESSION['ktp_service_search_mode'] = true;

						// 0件時は必ず検索モードへ戻す（istmode混在を防止）
						$redirect_base = remove_query_arg(
							array( 'query_post', 'data_id', 'message', 'multiple_results', 'no_results' ),
							$redirect_base
						);
						$url = add_query_arg(
							array(
								'tab_name' => $tab_name,
								'query_post' => 'srcmode',
								'search_service_name' => $search_service_name,
								'search_category' => $search_category,
								'no_results' => '1',
							),
							$redirect_base
						);
						$wpdb->query( 'UNLOCK TABLES;' );
						wp_safe_redirect( $url );
						exit;
					}
				}

				$wpdb->query( 'UNLOCK TABLES;' );
			}
		}

		/**
		 * Handle uploading an image
		 *
		 * @param string $tab_name Table name suffix
		 * @param int    $data_id Data ID
		 * @return void
		 */
		private function handle_upload_image( $tab_name, $data_id ) {
			global $wpdb;
			$table_name = $wpdb->prefix . 'ktp_' . sanitize_key( $tab_name );

			// 先にKTPWP_Image_Processorクラスが存在するか確認
			if ( ! class_exists( 'KTPWP_Image_Processor' ) ) {
				require_once __DIR__ . '/class-ktpwp-image-processor.php';
			}

			// 画像URLを取得
			$image_processor = new KTPWP_Image_Processor();
			$default_image_url = plugin_dir_url( __DIR__ ) . 'images/default/no-image-icon.jpg';

			// デフォルト画像のパスが正しいか確認
			$default_image_path = __DIR__ . '/../images/default/no-image-icon.jpg';
			if ( ! file_exists( $default_image_path ) ) {
				// デフォルト画像が存在しない場合のエラーは記録しない（プロダクション環境）
			}

			$image_url = $image_processor->handle_image( $tab_name, $data_id, $default_image_url );

			$update_result = $wpdb->update(
                $table_name,
                array(
					'image_url' => $image_url,
                ),
                array(
					'id' => $data_id,
                ),
                array(
					'%s',
                ),
                array(
					'%d',
                )
			);

			$wpdb->query( 'UNLOCK TABLES;' ); // Unlock before redirect

			// 元のページ（ショートコードが配置された固定ページ）にリダイレクト
			$current_page_url = wp_get_referer();
			if ( ! $current_page_url ) {
				// refererが取得できない場合は、動的パーマリンク取得を使用
				$current_page_url = KTPWP_Main::get_current_page_base_url();
			}

			$redirect_url = add_query_arg(
                array(
					'tab_name' => $tab_name,
					'data_id' => $data_id,
					'message' => 'image_uploaded',
                ),
                $current_page_url
            );

			wp_redirect( $redirect_url );
			exit;
		}

		/**
		 * Handle deleting an image
		 *
		 * @param string $tab_name Table name suffix
		 * @param int    $data_id Data ID
		 * @return void
		 */
		private function handle_delete_image( $tab_name, $data_id ) {
			global $wpdb;
			$table_name = $wpdb->prefix . 'ktp_' . sanitize_key( $tab_name );

			// デフォルト画像のURLを設定
			$default_image_url = plugin_dir_url( __DIR__ ) . 'images/default/no-image-icon.jpg';

			// 既存の画像ファイルを削除する（旧形式・新形式の両方）
			$this->delete_uploaded_image_files( $data_id );

			$wpdb->update(
                $table_name,
                array(
					'image_url' => $default_image_url,
                ),
                array(
					'id' => $data_id,
                ),
                array(
					'%s',
                ),
                array(
					'%d',
                )
			);
			$wpdb->query( 'UNLOCK TABLES;' ); // Unlock before redirect

			// 元のページ（ショートコードが配置された固定ページ）にリダイレクト
			$current_page_url = wp_get_referer();
			if ( ! $current_page_url ) {
				// refererが取得できない場合は、動的パーマリンク取得を使用
				$current_page_url = KTPWP_Main::get_current_page_base_url();
			}

			$redirect_url = add_query_arg(
                array(
					'tab_name' => $tab_name,
					'data_id' => $data_id,
					'message' => 'image_deleted',
                ),
                $current_page_url
            );

			wp_redirect( $redirect_url );
			exit;
		}

		/**
		 * Get the next available ID to display
		 *
		 * @param string $table_name Full table name
		 * @param int    $deleted_id ID that was just deleted
		 * @return int Next available ID
		 */
		public function get_next_display_id( $table_name, $deleted_id ) {
			global $wpdb;

			// Delete succeeded, find next ID to display
			$next_id_query = $wpdb->prepare(
                "SELECT id FROM {$table_name} WHERE id < %d ORDER BY id DESC LIMIT 1",
                $deleted_id
			);
			$next_id = $wpdb->get_var( $next_id_query );

			// If no previous ID, try to get next ID
			if ( null === $next_id ) {
				$next_id_query = $wpdb->prepare(
                    "SELECT id FROM {$table_name} WHERE id > %d ORDER BY id ASC LIMIT 1",
                    $deleted_id
				);
				$next_id = $wpdb->get_var( $next_id_query );

				// If no next ID either, get highest available ID
				if ( null === $next_id ) {
					$next_id_query = "SELECT id FROM {$table_name} ORDER BY id DESC LIMIT 1";
					$next_id = $wpdb->get_var( $next_id_query );
				}
			}

			// Return found ID or 0 if no records left
			return $next_id ? (int) $next_id : 0;
		}

		/**
		 * Get services with filters and pagination
		 *
		 * @param string $tab_name Table name suffix
		 * @param array  $args Query arguments
		 * @return array Services data
		 */
		public function get_services( $tab_name, $args = array() ) {
			if ( empty( $tab_name ) ) {
				return array();
			}

			global $wpdb;
			$table_name = $wpdb->prefix . 'ktp_' . sanitize_key( $tab_name );

			$defaults = array(
				'limit'       => 20,
				'offset'      => 0,
				'order_by'    => 'id',
				'order'       => 'DESC',
				'search'      => '',
				'category'    => '',
				'is_public'   => false,
				'ids'         => array(),
			);

			$args = wp_parse_args( $args, $defaults );

			$where_clauses = array();
			$where_values = array();

			// サイト公開フラグ
			if ( ! empty( $args['is_public'] ) && $this->service_table_has_is_public_column( $table_name ) ) {
				$where_clauses[] = 'is_public = %d';
				$where_values[] = 1;
			}

			// ID 指定
			if ( ! empty( $args['ids'] ) && is_array( $args['ids'] ) ) {
				$ids = array_values( array_filter( array_map( 'absint', $args['ids'] ) ) );
				if ( ! empty( $ids ) ) {
					$placeholders  = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
					$where_clauses[] = "id IN ({$placeholders})";
					$where_values    = array_merge( $where_values, $ids );
				}
			}

			// カテゴリーフィルター
			if ( ! empty( $args['category'] ) ) {
				$where_clauses[] = 'category = %s';
				$where_values[] = $args['category'];
			}

			// 検索フィルター
			if ( ! empty( $args['search'] ) ) {
				$where_clauses[] = '(service_name LIKE %s OR price LIKE %s OR unit LIKE %s OR category LIKE %s OR search_field LIKE %s)';
				$search_term = '%' . $wpdb->esc_like( $args['search'] ) . '%';
				$where_values[] = $search_term;
				$where_values[] = $search_term;
				$where_values[] = $search_term;
				$where_values[] = $search_term;
				$where_values[] = $search_term;
			}

			// WHERE句の構築
			$where_sql = '';
			if ( ! empty( $where_clauses ) ) {
				$where_sql = 'WHERE ' . implode( ' AND ', $where_clauses );
			}

			// ORDER BY句の検証とサニタイズ
			$allowed_order_by = array( 'id', 'service_name', 'price', 'unit', 'frequency', 'time', 'category', 'tax_rate', 'is_public' );
			if ( ! in_array( $args['order_by'], $allowed_order_by ) ) {
				$args['order_by'] = 'id';
			}

			// ORDER句のサニタイズ
			$args['order'] = strtoupper( $args['order'] );
			if ( ! in_array( $args['order'], array( 'ASC', 'DESC' ) ) ) {
				$args['order'] = 'DESC';
			}

			// クエリの構築
			$limit = (int) $args['limit'];
			if ( $limit > 0 ) {
				$sql = "SELECT * FROM `{$table_name}` {$where_sql} ORDER BY {$args['order_by']} {$args['order']} LIMIT %d OFFSET %d";
				$where_values[] = $limit;
				$where_values[] = (int) $args['offset'];
			} else {
				$sql = "SELECT * FROM `{$table_name}` {$where_sql} ORDER BY {$args['order_by']} {$args['order']}";
			}

			// プリペアードステートメントの実行
			if ( ! empty( $where_values ) ) {
				$results = $wpdb->get_results( $wpdb->prepare( $sql, $where_values ) );
			} else {
				$results = $wpdb->get_results( $sql );
			}

			return $results ? $results : array();
		}

		/**
		 * Get services count with filters
		 *
		 * @param string $tab_name Table name suffix
		 * @param array  $args Query arguments
		 * @return int Services count
		 */
		public function get_services_count( $tab_name, $args = array() ) {
			if ( empty( $tab_name ) ) {
				return 0;
			}

			global $wpdb;
			$table_name = $wpdb->prefix . 'ktp_' . sanitize_key( $tab_name );

			$defaults = array(
				'search'      => '',
				'category'    => '',
				'is_public'   => false,
				'ids'         => array(),
			);

			$args = wp_parse_args( $args, $defaults );

			$where_clauses = array();
			$where_values = array();

			if ( ! empty( $args['is_public'] ) && $this->service_table_has_is_public_column( $table_name ) ) {
				$where_clauses[] = 'is_public = %d';
				$where_values[] = 1;
			}

			if ( ! empty( $args['ids'] ) && is_array( $args['ids'] ) ) {
				$ids = array_values( array_filter( array_map( 'absint', $args['ids'] ) ) );
				if ( ! empty( $ids ) ) {
					$placeholders  = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
					$where_clauses[] = "id IN ({$placeholders})";
					$where_values    = array_merge( $where_values, $ids );
				}
			}

			// カテゴリーフィルター
			if ( ! empty( $args['category'] ) ) {
				$where_clauses[] = 'category = %s';
				$where_values[] = $args['category'];
			}

			// 検索フィルター
			if ( ! empty( $args['search'] ) ) {
				$where_clauses[] = '(service_name LIKE %s OR price LIKE %s OR unit LIKE %s OR category LIKE %s OR search_field LIKE %s)';
				$search_term = '%' . $wpdb->esc_like( $args['search'] ) . '%';
				$where_values[] = $search_term;
				$where_values[] = $search_term;
				$where_values[] = $search_term;
				$where_values[] = $search_term;
				$where_values[] = $search_term;
			}

			// WHERE句の構築
			$where_sql = '';
			if ( ! empty( $where_clauses ) ) {
				$where_sql = 'WHERE ' . implode( ' AND ', $where_clauses );
			}

			// カウントクエリの構築
			$sql = "SELECT COUNT(*) FROM `{$table_name}` {$where_sql}";

			// プリペアードステートメントの実行
			if ( ! empty( $where_values ) ) {
				$count = $wpdb->get_var( $wpdb->prepare( $sql, $where_values ) );
			} else {
				$count = $wpdb->get_var( $sql );
			}

			return $count ? (int) $count : 0;
		}

		/**
		 * サービス画像のアップロード先ディレクトリ（物理パス）を返す。
		 *
		 * @return string
		 */
		public function get_upload_dir() {
			return dirname( __DIR__ ) . '/images/upload/';
		}

		/**
		 * サービス画像のアップロード先 URL を返す。
		 *
		 * @return string
		 */
		public function get_upload_url() {
			return plugin_dir_url( dirname( __DIR__ ) . '/ktpwp.php' ) . 'images/upload/';
		}

		/**
		 * デフォルト（ノーイメージ）画像 URL を返す。
		 *
		 * @return string
		 */
		public function get_default_image_url() {
			return plugin_dir_url( dirname( __DIR__ ) . '/ktpwp.php' ) . 'images/default/no-image-icon.jpg';
		}

		/**
		 * サービス ID に紐づくアップロード画像ファイルを検索する。
		 *
		 * 旧形式（{id}.jpeg）と新形式（{id}-{日付}.{ext}）の両方に対応する。
		 *
		 * @param int $service_id サービス ID。
		 * @return string|null 見つかったファイルの物理パス。なければ null。
		 */
		public function find_uploaded_image_file( $service_id ) {
			$service_id = absint( $service_id );
			if ( $service_id <= 0 ) {
				return null;
			}

			$upload_dir = $this->get_upload_dir();
			if ( ! is_dir( $upload_dir ) ) {
				return null;
			}

			foreach ( array( '.jpeg', '.jpg' ) as $ext ) {
				$legacy_file = $upload_dir . $service_id . $ext;
				if ( is_file( $legacy_file ) ) {
					return $legacy_file;
				}
			}

			$pattern = $upload_dir . $service_id . '-*.{jpeg,jpg,png,gif}';
			$files   = glob( $pattern, GLOB_BRACE );
			if ( empty( $files ) ) {
				return null;
			}

			usort(
				$files,
				static function ( $a, $b ) {
					return filemtime( $b ) <=> filemtime( $a );
				}
			);

			return $files[0];
		}

		/**
		 * サービス ID に紐づくアップロード画像ファイルをすべて削除する。
		 *
		 * @param int $service_id サービス ID。
		 * @return void
		 */
		public function delete_uploaded_image_files( $service_id ) {
			$service_id = absint( $service_id );
			if ( $service_id <= 0 ) {
				return;
			}

			$upload_dir = $this->get_upload_dir();
			if ( ! is_dir( $upload_dir ) ) {
				return;
			}

			foreach ( array( '.jpeg', '.jpg', '.png', '.gif' ) as $ext ) {
				$legacy_file = $upload_dir . $service_id . $ext;
				if ( is_file( $legacy_file ) ) {
					@unlink( $legacy_file );
				}
			}

			$files = glob( $upload_dir . $service_id . '-*.{jpeg,jpg,png,gif}', GLOB_BRACE );
			if ( ! empty( $files ) ) {
				foreach ( $files as $file ) {
					if ( is_file( $file ) ) {
						@unlink( $file );
					}
				}
			}
		}

		/**
		 * DB に保存された image_url がデフォルト画像を指しているか判定する。
		 *
		 * @param string $image_url DB の image_url。
		 * @return bool
		 */
		private function is_default_image_url( $image_url ) {
			$image_url = trim( (string) $image_url );
			if ( $image_url === '' ) {
				return true;
			}

			return (bool) preg_match( '/no-image-icon\.jpg$/i', $image_url );
		}

		/**
		 * サービス画像 URL を解決する（アップロード画像 → DB の image_url → デフォルト）。
		 *
		 * @param int    $service_id サービス ID。
		 * @param string $image_url  DB に保存された image_url。
		 * @return string
		 */
		public function resolve_image_url( $service_id, $image_url = '' ) {
			$service_id  = absint( $service_id );
			$default_url = $this->get_default_image_url();
			$upload_url  = $this->get_upload_url();

			$uploaded_file = $this->find_uploaded_image_file( $service_id );
			if ( $uploaded_file ) {
				return $upload_url . basename( $uploaded_file );
			}

			$image_url = trim( (string) $image_url );
			if ( $image_url !== '' && ! $this->is_default_image_url( $image_url ) ) {
				$filename = basename( wp_parse_url( $image_url, PHP_URL_PATH ) );
				if ( $filename !== '' ) {
					$local_file = $this->get_upload_dir() . $filename;
					if ( is_file( $local_file ) ) {
						return $upload_url . $filename;
					}
				}

				return esc_url( $image_url );
			}

			return $default_url;
		}

		/**
		 * 公開中のサービスを ID で取得する。
		 *
		 * @param int $service_id サービス ID。
		 * @return object|null
		 */
		public function get_public_service_by_id( $service_id ) {
			$service_id = absint( $service_id );
			if ( $service_id <= 0 ) {
				return null;
			}

			global $wpdb;
			$table_name = $wpdb->prefix . 'ktp_service';

			if ( ! $this->service_table_has_is_public_column( $table_name ) ) {
				return null;
			}

			return $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table_name} WHERE id = %d AND is_public = 1",
					$service_id
				)
			);
		}

		/**
		 * 公開中サービスのカテゴリー一覧（重複なし）を取得する。
		 *
		 * @return array<int, string>
		 */
		public function get_public_service_categories() {
			global $wpdb;

			$table_name = $wpdb->prefix . 'ktp_service';

			if ( ! $this->service_table_has_is_public_column( $table_name ) ) {
				return array();
			}

			$results = $wpdb->get_col(
				"SELECT DISTINCT category FROM {$table_name} WHERE is_public = 1 AND COALESCE(category, '') <> '' ORDER BY category ASC"
			);

			if ( ! is_array( $results ) ) {
				return array();
			}

			return array_values(
				array_filter(
					array_map(
						static function ( $value ) {
							return sanitize_text_field( (string) $value );
						},
						$results
					)
				)
			);
		}

		/**
		 * サービステーブルに is_public カラムがあるか確認する。
		 *
		 * @param string $table_name テーブル名。
		 * @return bool
		 */
		private function service_table_has_is_public_column( $table_name ) {
			global $wpdb;

			static $cache = array();

			if ( isset( $cache[ $table_name ] ) ) {
				return $cache[ $table_name ];
			}

			$columns = $wpdb->get_col( "SHOW COLUMNS FROM `{$table_name}`" );
			$cache[ $table_name ] = is_array( $columns ) && in_array( 'is_public', $columns, true );

			return $cache[ $table_name ];
		}

		/**
		 * サービステーブルに contract_billing_cycle カラムがあるか確認する。
		 *
		 * @param string $table_name テーブル名。
		 * @return bool
		 */
		private function service_table_has_contract_billing_cycle_column( $table_name ) {
			global $wpdb;

			static $cache = array();

			if ( isset( $cache[ $table_name ] ) ) {
				return $cache[ $table_name ];
			}

			$columns = $wpdb->get_col( "SHOW COLUMNS FROM `{$table_name}`" );
			$cache[ $table_name ] = is_array( $columns ) && in_array( 'contract_billing_cycle', $columns, true );

			return $cache[ $table_name ];
		}
	}
}
