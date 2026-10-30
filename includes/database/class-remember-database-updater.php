<?php
/**
 * Database updater class
 *
 * Handles database schema updates and migrations.
 *
 * @package    reMember
 * @subpackage reMember/includes/database
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Database updater class.
 *
 * @package    reMember
 * @subpackage reMember/includes/database
 */
class Remember_Database_Updater {

	/**
	 * Update database schema if needed.
	 */
	public static function update_schema() {
		global $wpdb;
		require_once plugin_dir_path( __FILE__ ) . '../utilities/class-remember-logger.php';
		// Migrations may create tables via dbDelta outside plugin activation.
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		Remember_Logger::activation_debug(
			'update_schema: enter',
			array( 'remember_db_version' => get_option( 'remember_db_version', '0.0.0' ) )
		);

		// Migrations compare fresh DB version each time so only the next pending migration runs per request.
		// Update to 1.1.0 (unvetted status)
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '1.1.0', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '1.1.0' ) );
			
			// Update members table to include 'unvetted' status
			$table_name = $wpdb->prefix . 'remember_members';
			$column_exists = $wpdb->get_results( "SHOW COLUMNS FROM {$table_name} LIKE 'status'" );
			
			if ( ! empty( $column_exists ) ) {
				// Check current ENUM values
				$column_info = $wpdb->get_row( "SHOW COLUMNS FROM {$table_name} WHERE Field = 'status'" );
				if ( $column_info && strpos( $column_info->Type, 'unvetted' ) === false ) {
					// Add 'unvetted' to the ENUM
					$result = $wpdb->query( "ALTER TABLE {$table_name} MODIFY COLUMN status ENUM('pending_vetting', 'unvetted', 'in_vetting', 'vetted', 'rejected', 'inactive') DEFAULT 'pending_vetting'" );
					
					if ( $result !== false ) {
						Remember_Logger::info( 'Members table updated with unvetted status' );
					} else {
						Remember_Logger::error( 'Failed to update members table', array( 'error' => $wpdb->last_error ) );
					}
				}
			}
			
			// Update vetting table to allow nullable primary_vetter_id
			$vetting_table = $wpdb->prefix . 'remember_vetting';
			$vetting_column = $wpdb->get_row( "SHOW COLUMNS FROM {$vetting_table} WHERE Field = 'primary_vetter_id'" );
			
			if ( $vetting_column && strpos( $vetting_column->Null, 'YES' ) === false ) {
				$result = $wpdb->query( "ALTER TABLE {$vetting_table} MODIFY COLUMN primary_vetter_id BIGINT(20) UNSIGNED DEFAULT NULL" );
				
				if ( $result !== false ) {
					Remember_Logger::info( 'Vetting table updated to allow nullable primary_vetter_id' );
				} else {
					Remember_Logger::error( 'Failed to update vetting table', array( 'error' => $wpdb->last_error ) );
				}
			}
			
			// Update version
			update_option( 'remember_db_version', '1.1.0' );
			Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '1.1.0' ) );
		}
		
		// Update to 1.2.0 (privacy fields)
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '1.2.0', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '1.2.0' ) );
			
			// Add privacy fields to member_profiles table
			$profiles_table = $wpdb->prefix . 'remember_member_profiles';
			
			// Check if columns already exist
			$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$profiles_table}" );
			
			$privacy_fields = array(
				'share_email_with_events'      => "TINYINT(1) DEFAULT 0",
				'share_phone_with_events'      => "TINYINT(1) DEFAULT 0",
				'share_location_with_events'   => "TINYINT(1) DEFAULT 0",
				'share_im_with_events'         => "TINYINT(1) DEFAULT 0",
				'share_interests_with_events'  => "TINYINT(1) DEFAULT 0",
			);
			
			$previous_field = 'emergency_contact_relationship';
			foreach ( $privacy_fields as $field_name => $field_type ) {
				if ( ! in_array( $field_name, $columns, true ) ) {
					// Escape field names for SQL
					$field_name_safe = esc_sql( $field_name );
					$previous_field_safe = esc_sql( $previous_field );
					
					$result = $wpdb->query( "ALTER TABLE {$profiles_table} ADD COLUMN `{$field_name_safe}` {$field_type} AFTER `{$previous_field_safe}`" );
					
					if ( $result !== false ) {
						Remember_Logger::info( "Added {$field_name} column to member_profiles table" );
					} else {
						Remember_Logger::error( "Failed to add {$field_name} column", array( 'error' => $wpdb->last_error ) );
					}
				}
				$previous_field = $field_name;
			}
			
			// Update version
			update_option( 'remember_db_version', '1.2.0' );
			Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '1.2.0' ) );
		}
		
		// Update to 1.3.0 (remove unique constraint on vetting.member_id to allow multiple cases)
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '1.3.0', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '1.3.0' ) );
			
			$vetting_table = $wpdb->prefix . 'remember_vetting';
			
			// Check if unique key exists
			$indexes = $wpdb->get_results( "SHOW INDEX FROM {$vetting_table} WHERE Key_name = 'member_id' AND Non_unique = 0" );
			
			if ( ! empty( $indexes ) ) {
				// Drop the unique key
				$result = $wpdb->query( "ALTER TABLE {$vetting_table} DROP INDEX member_id" );
				
				if ( $result !== false ) {
					// Add back as a regular index (non-unique)
					$result2 = $wpdb->query( "ALTER TABLE {$vetting_table} ADD INDEX member_id (member_id)" );
					
					if ( $result2 !== false ) {
						Remember_Logger::info( 'Removed unique constraint on vetting.member_id, allowing multiple cases per member' );
					} else {
						Remember_Logger::error( 'Failed to add regular index on member_id', array( 'error' => $wpdb->last_error ) );
					}
				} else {
					Remember_Logger::error( 'Failed to drop unique key on member_id', array( 'error' => $wpdb->last_error ) );
				}
			}
			
			// Update version
			update_option( 'remember_db_version', '1.3.0' );
			Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '1.3.0' ) );
		}
		
		// Update to 1.4.0 (auto-assign default timezone to existing users)
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '1.4.0', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '1.4.0' ) );
			
			// Get all users without timezone_string meta
			$users_without_timezone = $wpdb->get_col(
				"SELECT u.ID FROM {$wpdb->users} u
				LEFT JOIN {$wpdb->usermeta} um ON u.ID = um.user_id AND um.meta_key = 'timezone_string'
				WHERE um.meta_value IS NULL"
			);
			
			$default_timezone = 'America/Los_Angeles';
			$updated_count = 0;
			
			foreach ( $users_without_timezone as $user_id ) {
				update_user_meta( $user_id, 'timezone_string', $default_timezone );
				$updated_count++;
			}
			
			if ( $updated_count > 0 ) {
				Remember_Logger::info( "Assigned default timezone to {$updated_count} users" );
			}
			
			// Update version
			update_option( 'remember_db_version', '1.4.0' );
			Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '1.4.0' ) );
		}
		
		// Update to 1.5.0 (add show_in_frontend field to roles table)
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '1.5.0', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '1.5.0' ) );
			
			$roles_table = $wpdb->prefix . 'remember_roles';
			
			// Check if column already exists
			$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$roles_table}" );
			
			if ( ! in_array( 'show_in_frontend', $columns, true ) ) {
				// Add show_in_frontend column with default value of 1 (true)
				$result = $wpdb->query( "ALTER TABLE {$roles_table} ADD COLUMN show_in_frontend BOOLEAN DEFAULT 1 AFTER is_event_role" );
				
				if ( $result !== false ) {
					Remember_Logger::info( 'Added show_in_frontend column to roles table' );
				} else {
					Remember_Logger::error( 'Failed to add show_in_frontend column', array( 'error' => $wpdb->last_error ) );
				}
			}
			
			// Set Event Administrator role to not show in frontend (always do this, even if column already existed)
			$event_admin_role_id = $wpdb->get_var( $wpdb->prepare(
				"SELECT role_id FROM {$roles_table} WHERE role_name = %s",
				'Event Administrator'
			) );
			
			if ( $event_admin_role_id ) {
				$update_result = $wpdb->update(
					$roles_table,
					array( 'show_in_frontend' => 0 ),
					array( 'role_id' => $event_admin_role_id ),
					array( '%d' ),
					array( '%d' )
				);
				
				if ( $update_result !== false ) {
					Remember_Logger::info( 'Set Event Administrator role to not show in frontend' );
				} else {
					Remember_Logger::error( 'Failed to update Event Administrator role', array( 'error' => $wpdb->last_error ) );
				}
			}
			
			// Update version
			update_option( 'remember_db_version', '1.5.0' );
			Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '1.5.0' ) );
		}

		// Update to 1.6.0 (multiple reMember line items may map to the same QuickBooks item — drop UNIQUE on quickbooks_product_id).
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '1.6.0', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '1.6.0' ) );

			$products_table = $wpdb->prefix . 'remember_products';
			$table_exists   = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $products_table ) );

			if ( $table_exists === $products_table ) {
				$qb_indexes = $wpdb->get_results( "SHOW INDEX FROM {$products_table} WHERE Key_name = 'quickbooks_product_id'" );
				if ( ! empty( $qb_indexes ) ) {
					$drop = $wpdb->query( "ALTER TABLE {$products_table} DROP INDEX quickbooks_product_id" );
					if ( false !== $drop ) {
						$wpdb->query( "ALTER TABLE {$products_table} ADD KEY idx_quickbooks_product_id (quickbooks_product_id)" );
						Remember_Logger::info( 'remember_products: replaced UNIQUE(quickbooks_product_id) with non-unique index' );
					} else {
						Remember_Logger::error( 'remember_products: failed to drop UNIQUE(quickbooks_product_id)', array( 'error' => $wpdb->last_error ) );
					}
				} else {
					$idx_rows = $wpdb->get_results( "SHOW INDEX FROM {$products_table} WHERE Key_name = 'idx_quickbooks_product_id'" );
					if ( empty( $idx_rows ) ) {
						$wpdb->query( "ALTER TABLE {$products_table} ADD KEY idx_quickbooks_product_id (quickbooks_product_id)" );
					}
				}
			}

			update_option( 'remember_db_version', '1.6.0' );
			Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '1.6.0' ) );
		}

		// Update to 1.7.0 (track when applications are added to waitlist).
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '1.7.0', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '1.7.0' ) );

			$applications_table = $wpdb->prefix . 'remember_event_applications';
			$table_exists       = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $applications_table ) );

			if ( $table_exists === $applications_table ) {
				$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$applications_table}" );
				if ( ! in_array( 'waitlisted_at', $columns, true ) ) {
					$add_column = $wpdb->query( "ALTER TABLE {$applications_table} ADD COLUMN waitlisted_at DATETIME DEFAULT NULL AFTER applied_at" );
					if ( false !== $add_column ) {
						Remember_Logger::info( 'Added waitlisted_at column to remember_event_applications' );
					} else {
						Remember_Logger::error( 'Failed to add waitlisted_at column', array( 'error' => $wpdb->last_error ) );
					}
				}
			}

			update_option( 'remember_db_version', '1.7.0' );
			Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '1.7.0' ) );
		}

		// Update to 1.8.0 (QuickBooks mappings in remember_qb_item_mappings; remember_products = catalog only).
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '1.8.0', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '1.8.0' ) );

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			require_once plugin_dir_path( __FILE__ ) . 'class-remember-database.php';
			$db = new Remember_Database();
			Remember_Logger::activation_debug( 'migration 1.8.0: create_qb_item_mappings_table' );
			$db->create_qb_item_mappings_table();

			$products_table = $wpdb->prefix . 'remember_products';
			$roles_table    = $wpdb->prefix . 'remember_roles';
			$map_table      = $wpdb->prefix . 'remember_qb_item_mappings';

			$product_cols = $wpdb->get_col( "SHOW COLUMNS FROM {$products_table}", 0 );
			if ( is_array( $product_cols ) && in_array( 'quickbooks_product_id', $product_cols, true ) ) {
				Remember_Logger::activation_debug( 'migration 1.8.0: INSERT role mappings into qb_item_mappings' );
				// Role mappings: product rows whose name matched an event role.
				$wpdb->query(
					"INSERT INTO {$map_table} (entity_type, entity_id, quickbooks_product_id, quickbooks_product_name, last_sync_at, created_at, updated_at)
					SELECT 'role', r.role_id, p.quickbooks_product_id, p.quickbooks_product_name, p.last_sync_at, p.created_at, p.updated_at
					FROM {$products_table} p
					INNER JOIN {$roles_table} r ON r.role_name = p.product_name AND r.is_event_role = 1
					WHERE p.quickbooks_product_id IS NOT NULL AND p.quickbooks_product_id != ''
					ON DUPLICATE KEY UPDATE
						quickbooks_product_id = VALUES(quickbooks_product_id),
						quickbooks_product_name = VALUES(quickbooks_product_name),
						last_sync_at = VALUES(last_sync_at),
						updated_at = VALUES(updated_at)"
				);

				Remember_Logger::activation_debug( 'migration 1.8.0: INSERT product mappings into qb_item_mappings' );
				// Product (catalog) mappings: remaining product rows with QB ids not consumed as roles.
				$wpdb->query(
					"INSERT INTO {$map_table} (entity_type, entity_id, quickbooks_product_id, quickbooks_product_name, last_sync_at, created_at, updated_at)
					SELECT 'product', p.product_id, p.quickbooks_product_id, p.quickbooks_product_name, p.last_sync_at, p.created_at, p.updated_at
					FROM {$products_table} p
					LEFT JOIN {$roles_table} r ON r.role_name = p.product_name AND r.is_event_role = 1
					WHERE r.role_id IS NULL
					AND p.quickbooks_product_id IS NOT NULL AND p.quickbooks_product_id != ''
					ON DUPLICATE KEY UPDATE
						quickbooks_product_id = VALUES(quickbooks_product_id),
						quickbooks_product_name = VALUES(quickbooks_product_name),
						last_sync_at = VALUES(last_sync_at),
						updated_at = VALUES(updated_at)"
				);

				Remember_Logger::activation_debug( 'migration 1.8.0: DELETE legacy product role mirrors' );
				// Remove legacy rows that were only role-name mirrors in the products table.
				$wpdb->query(
					"DELETE p FROM {$products_table} p
					INNER JOIN {$roles_table} r ON r.role_name = p.product_name AND r.is_event_role = 1"
				);

				Remember_Logger::activation_debug( 'migration 1.8.0: ALTER remember_products DROP QB columns' );
				// Drop QuickBooks columns from remember_products (indexes on those columns drop with the columns).
				$wpdb->query( "ALTER TABLE {$products_table} DROP COLUMN quickbooks_product_id, DROP COLUMN quickbooks_product_name, DROP COLUMN last_sync_at" );
			}

			update_option( 'remember_db_version', '1.8.0' );
			Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '1.8.0' ) );
			Remember_Logger::activation_debug( 'migration 1.8.0: finished' );
		}

		// Update to 1.9.0 (QuickBooks invoice DocNumber for display; distinct from QBO entity Id in quickbooks_invoice_id).
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '1.9.0', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '1.9.0' ) );

			$payments_table = $wpdb->prefix . 'remember_payments';
			$table_exists   = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $payments_table ) ) === $payments_table;
			if ( $table_exists ) {
				$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$payments_table}", 0 );
				if ( is_array( $cols ) && ! in_array( 'quickbooks_invoice_number', $cols, true ) ) {
					$ok = $wpdb->query(
						"ALTER TABLE {$payments_table} ADD COLUMN quickbooks_invoice_number VARCHAR(50) DEFAULT NULL AFTER quickbooks_invoice_id"
					);
					if ( false === $ok ) {
						Remember_Logger::error(
							'Failed to add quickbooks_invoice_number to remember_payments',
							array( 'error' => $wpdb->last_error )
						);
					} else {
						Remember_Logger::info( 'Added quickbooks_invoice_number to remember_payments' );
					}
				}
			}

			update_option( 'remember_db_version', '1.9.0' );
			Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '1.9.0' ) );
		}

		// Update to 1.10.0 (catalog default_price for add-on products).
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '1.10.0', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '1.10.0' ) );

			$products_table = $wpdb->prefix . 'remember_products';
			$table_exists   = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $products_table ) ) === $products_table;
			if ( $table_exists ) {
				$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$products_table}", 0 );
				if ( is_array( $cols ) && ! in_array( 'default_price', $cols, true ) ) {
					$ok = $wpdb->query(
						"ALTER TABLE {$products_table} ADD COLUMN default_price DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER product_type"
					);
					if ( false === $ok ) {
						Remember_Logger::error(
							'Failed to add default_price to remember_products',
							array( 'error' => $wpdb->last_error )
						);
					} else {
						Remember_Logger::info( 'Added default_price to remember_products' );
					}
				}
			}

			update_option( 'remember_db_version', '1.10.0' );
			Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '1.10.0' ) );
		}

		// Update to 1.11.0 (QuickBooks: one JSON row per Payment applied to an invoice for billing register).
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '1.11.0', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '1.11.0' ) );

			$payments_table = $wpdb->prefix . 'remember_payments';
			$table_exists   = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $payments_table ) ) === $payments_table;
			if ( $table_exists ) {
				$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$payments_table}", 0 );
				if ( is_array( $cols ) && ! in_array( 'quickbooks_payment_lines', $cols, true ) ) {
					$ok = $wpdb->query(
						"ALTER TABLE {$payments_table} ADD COLUMN quickbooks_payment_lines LONGTEXT DEFAULT NULL AFTER quickbooks_invoice_number"
					);
					if ( false === $ok ) {
						Remember_Logger::error(
							'Failed to add quickbooks_payment_lines to remember_payments',
							array( 'error' => $wpdb->last_error )
						);
					} else {
						Remember_Logger::info( 'Added quickbooks_payment_lines to remember_payments' );
					}
				}
			}

			update_option( 'remember_db_version', '1.11.0' );
			Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '1.11.0' ) );
		}

		// Update to 1.12.0 (QuickBooks invoice sort timestamp for billing register ordering).
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '1.12.0', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '1.12.0' ) );

			$payments_table = $wpdb->prefix . 'remember_payments';
			$table_exists   = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $payments_table ) ) === $payments_table;
			if ( $table_exists ) {
				$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$payments_table}", 0 );
				if ( is_array( $cols ) && ! in_array( 'quickbooks_invoice_sort_ts', $cols, true ) ) {
					$ok = $wpdb->query(
						"ALTER TABLE {$payments_table} ADD COLUMN quickbooks_invoice_sort_ts BIGINT(20) UNSIGNED DEFAULT NULL AFTER quickbooks_invoice_number"
					);
					if ( false === $ok ) {
						Remember_Logger::error(
							'Failed to add quickbooks_invoice_sort_ts to remember_payments',
							array( 'error' => $wpdb->last_error )
						);
					} else {
						Remember_Logger::info( 'Added quickbooks_invoice_sort_ts to remember_payments' );
					}
				}
			}

			update_option( 'remember_db_version', '1.12.0' );
			Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '1.12.0' ) );
		}

		// Update to 1.13.0 — ensure member registration page exists and is stored in remember_created_pages.
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '1.13.0', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '1.13.0' ) );

			require_once plugin_dir_path( __FILE__ ) . '../utilities/class-remember-page-creator.php';
			Remember_Logger::activation_debug( 'migration 1.13.0: ensure member_register page' );
			Remember_Page_Creator::create_pages( array( 'member_register' ) );

			update_option( 'remember_db_version', '1.13.0' );
			Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '1.13.0' ) );
		}

		// Update to 1.14.0 (QuickBooks: refund receipts linked to an invoice for billing register + member billing).
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '1.14.0', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '1.14.0' ) );

			$payments_table = $wpdb->prefix . 'remember_payments';
			$table_exists   = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $payments_table ) ) === $payments_table;
			if ( $table_exists ) {
				$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$payments_table}", 0 );
				if ( is_array( $cols ) && ! in_array( 'quickbooks_refund_lines', $cols, true ) ) {
					$ok = $wpdb->query(
						"ALTER TABLE {$payments_table} ADD COLUMN quickbooks_refund_lines LONGTEXT DEFAULT NULL AFTER quickbooks_payment_lines"
					);
					if ( false === $ok ) {
						Remember_Logger::error(
							'Failed to add quickbooks_refund_lines to remember_payments',
							array( 'error' => $wpdb->last_error )
						);
					} else {
						Remember_Logger::info( 'Added quickbooks_refund_lines to remember_payments' );
					}
				}
			}

			update_option( 'remember_db_version', '1.14.0' );
			Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '1.14.0' ) );
		}

		// Update to 1.15.0 — share profile photo with event members (privacy toggle).
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '1.15.0', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '1.15.0' ) );

			$profiles_table = $wpdb->prefix . 'remember_member_profiles';
			$table_exists   = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $profiles_table ) ) === $profiles_table;
			if ( $table_exists ) {
				$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$profiles_table}", 0 );
				if ( is_array( $columns ) && ! in_array( 'share_photo_with_events', $columns, true ) ) {
					$ok = $wpdb->query(
						"ALTER TABLE {$profiles_table} ADD COLUMN share_photo_with_events TINYINT(1) DEFAULT 0 AFTER share_interests_with_events"
					);
					if ( false === $ok ) {
						Remember_Logger::error(
							'Failed to add share_photo_with_events to member_profiles',
							array( 'error' => $wpdb->last_error )
						);
					} else {
						Remember_Logger::info( 'Added share_photo_with_events to member_profiles' );
					}
				}
			}

			update_option( 'remember_db_version', '1.15.0' );
			Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '1.15.0' ) );
		}

		// Update to 1.16.0 — Xero billing plumbing (parallel columns, mappings, processor).
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '1.16.0', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '1.16.0' ) );

			require_once plugin_dir_path( __FILE__ ) . 'class-remember-database.php';
			$db = new Remember_Database();
			$db->create_xero_item_mappings_table();

			$processors_table = $wpdb->prefix . 'remember_payment_processors';
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $processors_table ) ) === $processors_table ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is plugin-controlled.
				$wpdb->query( "ALTER TABLE {$processors_table} MODIFY processor_type ENUM('manual','quickbooks','xero') NOT NULL" );

				$xero_exists = $wpdb->get_var( $wpdb->prepare( "SELECT processor_id FROM {$processors_table} WHERE processor_type = %s", 'xero' ) );
				if ( ! $xero_exists ) {
					$wpdb->insert(
						$processors_table,
						array(
							'processor_type' => 'xero',
							'processor_name' => 'Xero',
							'is_active'      => 0,
							'settings'       => '',
							'created_at'     => current_time( 'mysql' ),
							'updated_at'     => current_time( 'mysql' ),
						)
					);
				}
			}

			$payments_table = $wpdb->prefix . 'remember_payments';
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $payments_table ) ) === $payments_table ) {
				$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$payments_table}", 0 );
				if ( is_array( $cols ) ) {
					$xero_cols = array(
						'xero_invoice_id'         => "ADD COLUMN xero_invoice_id VARCHAR(100) DEFAULT NULL AFTER quickbooks_refund_lines",
						'xero_invoice_number'     => "ADD COLUMN xero_invoice_number VARCHAR(50) DEFAULT NULL AFTER xero_invoice_id",
						'xero_invoice_sort_ts'    => "ADD COLUMN xero_invoice_sort_ts BIGINT(20) UNSIGNED DEFAULT NULL AFTER xero_invoice_number",
						'xero_payment_lines'      => "ADD COLUMN xero_payment_lines LONGTEXT DEFAULT NULL AFTER xero_invoice_sort_ts",
						'xero_refund_lines'       => "ADD COLUMN xero_refund_lines LONGTEXT DEFAULT NULL AFTER xero_payment_lines",
					);
					foreach ( $xero_cols as $col_name => $alter_sql ) {
						if ( ! in_array( $col_name, $cols, true ) ) {
							$ok = $wpdb->query( "ALTER TABLE {$payments_table} {$alter_sql}" );
							if ( false === $ok ) {
								Remember_Logger::error(
									"Failed to add {$col_name} to remember_payments",
									array( 'error' => $wpdb->last_error )
								);
							} else {
								Remember_Logger::info( "Added {$col_name} to remember_payments" );
								$cols[] = $col_name;
							}
						}
					}
				}
			}

			$options = get_option( 'remember_options', array() );
			if ( ! is_array( $options ) ) {
				$options = array();
			}
			if ( empty( $options['billing_provider'] ) ) {
				$options['billing_provider'] = 'none';
				update_option( 'remember_options', $options );
			}

			update_option( 'remember_db_version', '1.16.0' );
			Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '1.16.0' ) );
		}

		// Update to 1.17.0 — store Xero customer online-invoice URL on payment rows.
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '1.17.0', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '1.17.0' ) );

			$payments_table = $wpdb->prefix . 'remember_payments';
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $payments_table ) ) === $payments_table ) {
				$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$payments_table}", 0 );
				if ( is_array( $cols ) && ! in_array( 'xero_online_invoice_url', $cols, true ) ) {
					$ok = $wpdb->query(
						"ALTER TABLE {$payments_table} ADD COLUMN xero_online_invoice_url VARCHAR(500) DEFAULT NULL AFTER xero_invoice_number"
					);
					if ( false === $ok ) {
						Remember_Logger::error(
							'Failed to add xero_online_invoice_url to remember_payments',
							array( 'error' => $wpdb->last_error )
						);
					} else {
						Remember_Logger::info( 'Added xero_online_invoice_url to remember_payments' );
					}
				}
			}

			update_option( 'remember_db_version', '1.17.0' );
			Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '1.17.0' ) );
		}

		// Update to 1.18.0 — admission ticket columns + notification seed types.
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '1.18.0', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '1.18.0' ) );

			$apps_table = $wpdb->prefix . 'remember_event_applications';
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $apps_table ) ) === $apps_table ) {
				$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$apps_table}", 0 );
				if ( is_array( $cols ) && ! in_array( 'ticket_voided', $cols, true ) ) {
					$ok = $wpdb->query(
						"ALTER TABLE {$apps_table} ADD COLUMN ticket_voided TINYINT(1) NOT NULL DEFAULT 0 AFTER notes"
					);
					if ( false === $ok ) {
						Remember_Logger::error( 'Failed to add ticket_voided', array( 'error' => $wpdb->last_error ) );
					}
				}
				$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$apps_table}", 0 );
				if ( is_array( $cols ) && ! in_array( 'ticket_ready_emailed_at', $cols, true ) ) {
					$ok = $wpdb->query(
						"ALTER TABLE {$apps_table} ADD COLUMN ticket_ready_emailed_at DATETIME DEFAULT NULL AFTER ticket_voided"
					);
					if ( false === $ok ) {
						Remember_Logger::error( 'Failed to add ticket_ready_emailed_at', array( 'error' => $wpdb->last_error ) );
					}
				}
			}

			$payments_table = $wpdb->prefix . 'remember_payments';
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $payments_table ) ) === $payments_table ) {
				$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$payments_table}", 0 );
				if ( is_array( $cols ) && ! in_array( 'ticket_paid_emailed_at', $cols, true ) ) {
					$ok = $wpdb->query(
						"ALTER TABLE {$payments_table} ADD COLUMN ticket_paid_emailed_at DATETIME DEFAULT NULL AFTER notes"
					);
					if ( false === $ok ) {
						Remember_Logger::error( 'Failed to add ticket_paid_emailed_at', array( 'error' => $wpdb->last_error ) );
					}
				}
			}

			require_once plugin_dir_path( __FILE__ ) . 'class-remember-seeder.php';
			$seeder = new Remember_Seeder();
			$seeder->ensure_notification_settings();

			update_option( 'remember_db_version', '1.18.0' );
			Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '1.18.0' ) );
		}

		// Update to 1.19.0 — clothing size options + profile shirt/pants/shoe columns.
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '1.19.0', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '1.19.0' ) );

			require_once plugin_dir_path( __FILE__ ) . 'class-remember-database.php';
			$db = new Remember_Database();
			$db->create_clothing_size_options_table();

			$profiles_table = $wpdb->prefix . 'remember_member_profiles';
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $profiles_table ) ) === $profiles_table ) {
				$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$profiles_table}", 0 );
				if ( is_array( $cols ) ) {
					$size_cols = array(
						'shirt_size' => "ADD COLUMN shirt_size VARCHAR(20) DEFAULT NULL AFTER interests",
						'pants_size' => "ADD COLUMN pants_size VARCHAR(20) DEFAULT NULL AFTER shirt_size",
						'shoe_size'  => "ADD COLUMN shoe_size VARCHAR(20) DEFAULT NULL AFTER pants_size",
					);
					foreach ( $size_cols as $col_name => $alter_sql ) {
						if ( ! in_array( $col_name, $cols, true ) ) {
							$ok = $wpdb->query( "ALTER TABLE {$profiles_table} {$alter_sql}" );
							if ( false === $ok ) {
								Remember_Logger::error(
									"Failed to add {$col_name} to remember_member_profiles",
									array( 'error' => $wpdb->last_error )
								);
							} else {
								Remember_Logger::info( "Added {$col_name} to remember_member_profiles" );
								$cols[] = $col_name;
							}
						}
					}
				}
			}

			require_once plugin_dir_path( __FILE__ ) . 'class-remember-seeder.php';
			$seeder = new Remember_Seeder();
			$seeder->ensure_clothing_size_options();

			update_option( 'remember_db_version', '1.19.0' );
			Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '1.19.0' ) );
		}

		// Update to 1.20.0 — per event-role add-on max qty (0 = hide).
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '1.20.0', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '1.20.0' ) );

			require_once plugin_dir_path( __FILE__ ) . 'class-remember-database.php';
			$db = new Remember_Database();
			$db->create_event_merchandise_role_limits_table();

			$limits_table = $wpdb->prefix . 'remember_event_merchandise_role_limits';
			$merch_table  = $wpdb->prefix . 'remember_event_merchandise';
			$roles_table  = $wpdb->prefix . 'remember_event_roles';

			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $limits_table ) ) === $limits_table
				&& $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $merch_table ) ) === $merch_table
				&& $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $roles_table ) ) === $roles_table ) {
				// Backfill: each existing add-on × event role gets max_qty from legacy max_quantity (NULL → 1).
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$merch_rows = $wpdb->get_results( "SELECT merchandise_id, event_id, max_quantity FROM {$merch_table}" );
				foreach ( (array) $merch_rows as $merch ) {
					$max_qty = ( null === $merch->max_quantity || '' === $merch->max_quantity )
						? 1
						: max( 0, absint( $merch->max_quantity ) );
					$event_roles = $wpdb->get_results(
						$wpdb->prepare(
							"SELECT event_role_id FROM {$roles_table} WHERE event_id = %d",
							absint( $merch->event_id )
						)
					);
					foreach ( (array) $event_roles as $er ) {
						$exists = $wpdb->get_var(
							$wpdb->prepare(
								"SELECT limit_id FROM {$limits_table} WHERE merchandise_id = %d AND event_role_id = %d",
								absint( $merch->merchandise_id ),
								absint( $er->event_role_id )
							)
						);
						if ( $exists ) {
							continue;
						}
						$wpdb->insert(
							$limits_table,
							array(
								'merchandise_id' => absint( $merch->merchandise_id ),
								'event_role_id'  => absint( $er->event_role_id ),
								'max_qty'        => $max_qty,
								'created_at'     => current_time( 'mysql' ),
								'updated_at'     => current_time( 'mysql' ),
							),
							array( '%d', '%d', '%d', '%s', '%s' )
						);
					}
				}
			}

			update_option( 'remember_db_version', '1.20.0' );
			Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '1.20.0' ) );
		}

		// Update to 1.21.0 — shirt/pants size options through 6XL.
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '1.21.0', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '1.21.0' ) );

			require_once plugin_dir_path( __FILE__ ) . 'class-remember-seeder.php';
			$seeder = new Remember_Seeder();
			$seeder->ensure_clothing_size_options();

			update_option( 'remember_db_version', '1.21.0' );
			Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '1.21.0' ) );
		}

		// Update to 1.22.0 — attendee-only event details.
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '1.22.0', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '1.22.0' ) );

			$events_table = $wpdb->prefix . 'remember_events';
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $events_table ) ) === $events_table ) {
				$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$events_table}", 0 );
				if ( is_array( $cols ) && ! in_array( 'attendee_details', $cols, true ) ) {
					$ok = $wpdb->query(
						"ALTER TABLE {$events_table} ADD COLUMN attendee_details TEXT DEFAULT NULL AFTER event_description"
					);
					if ( false === $ok ) {
						Remember_Logger::error( 'Failed to add attendee_details', array( 'error' => $wpdb->last_error ) );
					} else {
						Remember_Logger::info( 'Added attendee_details to remember_events' );
					}
				}
			}

			update_option( 'remember_db_version', '1.22.0' );
			Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '1.22.0' ) );
		}

		// Update to 1.23.0 — profile custom questions (admin-defined fields).
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '1.23.0', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '1.23.0' ) );

			require_once plugin_dir_path( __FILE__ ) . 'class-remember-database.php';
			$db = new Remember_Database();
			$db->create_profile_questions_table();
			$db->create_profile_question_responses_table();

			update_option( 'remember_db_version', '1.23.0' );
			Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '1.23.0' ) );
		}

		// Update to 1.24.0 — event agreements library (versioned) + acceptances.
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '1.24.0', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '1.24.0' ) );

			require_once plugin_dir_path( __FILE__ ) . 'class-remember-database.php';
			$db = new Remember_Database();
			$db->create_agreements_table();
			$db->create_agreement_revisions_table();
			$db->create_event_agreements_table();
			$db->create_agreement_acceptances_table();

			update_option( 'remember_db_version', '1.24.0' );
			Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '1.24.0' ) );
		}

		// Update to 1.25.0 — allow admin to supersede closed applications for reapply.
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '1.25.0', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '1.25.0' ) );

			global $wpdb;
			$apps_table = $wpdb->prefix . 'remember_event_applications';
			$col        = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM `' . $apps_table . '` LIKE %s', 'superseded_at' ) );
			if ( empty( $col ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is plugin-controlled.
				$ok = $wpdb->query( "ALTER TABLE {$apps_table} ADD COLUMN superseded_at DATETIME DEFAULT NULL AFTER ticket_ready_emailed_at" );
				if ( false === $ok ) {
					Remember_Logger::error( 'Failed to add superseded_at', array( 'error' => $wpdb->last_error ) );
				}
			}

			$indexes = $wpdb->get_results( "SHOW INDEX FROM {$apps_table} WHERE Key_name = 'event_member_role'" );
			if ( ! empty( $indexes ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$drop = $wpdb->query( "ALTER TABLE {$apps_table} DROP INDEX event_member_role" );
				if ( false === $drop ) {
					Remember_Logger::error( 'Failed to drop event_member_role unique index', array( 'error' => $wpdb->last_error ) );
				}
			}

			$new_idx = $wpdb->get_results( "SHOW INDEX FROM {$apps_table} WHERE Key_name = 'event_member_role_active'" );
			if ( empty( $new_idx ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$add_idx = $wpdb->query( "ALTER TABLE {$apps_table} ADD KEY event_member_role_active (event_id, member_id, event_role_id, superseded_at)" );
				if ( false === $add_idx ) {
					Remember_Logger::error( 'Failed to add event_member_role_active index', array( 'error' => $wpdb->last_error ) );
				}
			}

			update_option( 'remember_db_version', '1.25.0' );
			Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '1.25.0' ) );
		}

		// Update to 1.26.0 — event participant CSV export capability.
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '1.26.0', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '1.26.0' ) );

			require_once plugin_dir_path( __FILE__ ) . '../utilities/class-remember-capabilities.php';
			require_once plugin_dir_path( __FILE__ ) . '../models/class-role.php';

			Remember_Capabilities::setup_capabilities();

			$role_model = new Remember_Role();
			$cap        = 'remember_event_data_export';
			$role_names = array( 'Event Administrator', 'System Administrator' );
			$role_ids   = array();

			foreach ( $role_names as $role_name ) {
				$role_id = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT role_id FROM {$wpdb->prefix}remember_roles WHERE role_name = %s",
						$role_name
					)
				);
				if ( $role_id > 0 ) {
					$role_model->add_capability( $role_id, $cap );
					$role_ids[] = $role_id;
				}
			}

			if ( ! empty( $role_ids ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $role_ids ), '%d' ) );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built from count.
				$member_ids = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT DISTINCT member_id FROM {$wpdb->prefix}remember_member_roles WHERE role_id IN ($placeholders)",
						$role_ids
					)
				);
				if ( is_array( $member_ids ) ) {
					foreach ( $member_ids as $member_id ) {
						Remember_Capabilities::sync_user_capabilities_from_roles( (int) $member_id );
					}
				}
			}

			update_option( 'remember_db_version', '1.26.0' );
			Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '1.26.0' ) );
		}

		// Update to 1.27.0 — bulk Import/Export capability (System Admin; assignable).
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '1.27.0', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '1.27.0' ) );

			require_once plugin_dir_path( __FILE__ ) . '../utilities/class-remember-capabilities.php';
			require_once plugin_dir_path( __FILE__ ) . '../models/class-role.php';

			Remember_Capabilities::setup_capabilities();

			$role_model = new Remember_Role();
			$cap        = 'remember_import_export';
			$role_id    = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT role_id FROM {$wpdb->prefix}remember_roles WHERE role_name = %s",
					'System Administrator'
				)
			);
			if ( $role_id > 0 ) {
				$role_model->add_capability( $role_id, $cap );
				$member_ids = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT DISTINCT member_id FROM {$wpdb->prefix}remember_member_roles WHERE role_id = %d",
						$role_id
					)
				);
				if ( is_array( $member_ids ) ) {
					foreach ( $member_ids as $member_id ) {
						Remember_Capabilities::sync_user_capabilities_from_roles( (int) $member_id );
					}
				}
			}

			update_option( 'remember_db_version', '1.27.0' );
			Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '1.27.0' ) );
		}

		// Update to 1.28.0 — None options for dietary / allergy / medical catalogs.
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '1.28.0', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '1.28.0' ) );

			require_once plugin_dir_path( __FILE__ ) . 'class-remember-seeder.php';
			$seeder = new Remember_Seeder();
			$seeder->ensure_health_catalog_options();

			update_option( 'remember_db_version', '1.28.0' );
			Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '1.28.0' ) );
		}

		// Update to 1.29.0 — expanded allergies catalog (incl. pomegranate, grapefruit).
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '1.29.0', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '1.29.0' ) );

			require_once plugin_dir_path( __FILE__ ) . 'class-remember-seeder.php';
			$seeder = new Remember_Seeder();
			$seeder->ensure_health_catalog_options();

			update_option( 'remember_db_version', '1.29.0' );
			Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '1.29.0' ) );
		}

		// Update to 1.30.0 — expanded dietary + medical accommodation catalogs.
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '1.30.0', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '1.30.0' ) );

			require_once plugin_dir_path( __FILE__ ) . 'class-remember-seeder.php';
			$seeder = new Remember_Seeder();
			$seeder->ensure_health_catalog_options();

			update_option( 'remember_db_version', '1.30.0' );
			Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '1.30.0' ) );
		}

		// Update to 1.31.0 — conditional required_when for custom profile fields (#11).
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '1.31.0', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '1.31.0' ) );

			$table = $wpdb->prefix . 'remember_profile_questions';
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
				$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
				if ( is_array( $cols ) && ! in_array( 'required_when_json', $cols, true ) ) {
					$ok = $wpdb->query(
						"ALTER TABLE {$table} ADD COLUMN required_when_json LONGTEXT DEFAULT NULL AFTER is_required"
					);
					if ( false === $ok ) {
						Remember_Logger::error(
							'Failed to add required_when_json to remember_profile_questions',
							array( 'error' => $wpdb->last_error )
						);
					} else {
						Remember_Logger::info( 'Added required_when_json to remember_profile_questions' );
					}
				}
			}

			update_option( 'remember_db_version', '1.31.0' );
			Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '1.31.0' ) );
		}

		// Update to 1.32.0 — admin-managed IM platforms (#20).
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '1.32.0', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '1.32.0' ) );

			require_once plugin_dir_path( __FILE__ ) . 'class-remember-database.php';
			$db = new Remember_Database();
			$db->create_im_platforms_table();

			require_once plugin_dir_path( __FILE__ ) . 'class-remember-seeder.php';
			$seeder = new Remember_Seeder();
			$seeder->ensure_im_platforms();

			update_option( 'remember_db_version', '1.32.0' );
			Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '1.32.0' ) );
		}

		// Update to 1.33.0 — unique alphanumeric member_number (#21).
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '1.33.0', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '1.33.0' ) );

			$profiles_table = $wpdb->prefix . 'remember_member_profiles';
			$col            = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM `' . $profiles_table . '` LIKE %s', 'member_number' ) );
			if ( empty( $col ) ) {
				$ok = $wpdb->query(
					"ALTER TABLE {$profiles_table} ADD COLUMN member_number VARCHAR(50) DEFAULT NULL AFTER share_photo_with_events"
				);
				if ( false === $ok ) {
					Remember_Logger::error(
						'Failed to add member_number to remember_member_profiles',
						array( 'error' => $wpdb->last_error )
					);
				} else {
					Remember_Logger::info( 'Added member_number to remember_member_profiles' );
				}
			}

			$index = $wpdb->get_results( "SHOW INDEX FROM `{$profiles_table}` WHERE Key_name = 'member_number'" );
			if ( empty( $index ) ) {
				$ok = $wpdb->query( "ALTER TABLE {$profiles_table} ADD UNIQUE KEY member_number (member_number)" );
				if ( false === $ok ) {
					Remember_Logger::error(
						'Failed to add unique key on member_number',
						array( 'error' => $wpdb->last_error )
					);
				} else {
					Remember_Logger::info( 'Added unique key on member_number' );
				}
			}

			update_option( 'remember_db_version', '1.33.0' );
			Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '1.33.0' ) );
		}

		// Update to 1.34.0 — profile updated_by for audit / apply confirmation (#22).
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '1.34.0', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '1.34.0' ) );

			$profiles_table = $wpdb->prefix . 'remember_member_profiles';
			$col            = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM `' . $profiles_table . '` LIKE %s', 'updated_by' ) );
			if ( empty( $col ) ) {
				$ok = $wpdb->query(
					"ALTER TABLE {$profiles_table} ADD COLUMN updated_by BIGINT(20) UNSIGNED DEFAULT NULL AFTER updated_at"
				);
				if ( false === $ok ) {
					Remember_Logger::error(
						'Failed to add updated_by to remember_member_profiles',
						array( 'error' => $wpdb->last_error )
					);
				} else {
					Remember_Logger::info( 'Added updated_by to remember_member_profiles' );
				}
			}

			$index = $wpdb->get_results( "SHOW INDEX FROM `{$profiles_table}` WHERE Key_name = 'updated_by'" );
			if ( empty( $index ) ) {
				$ok = $wpdb->query( "ALTER TABLE {$profiles_table} ADD KEY updated_by (updated_by)" );
				if ( false === $ok ) {
					Remember_Logger::error(
						'Failed to add key on updated_by',
						array( 'error' => $wpdb->last_error )
					);
				} else {
					Remember_Logger::info( 'Added key on updated_by' );
				}
			}

			update_option( 'remember_db_version', '1.34.0' );
			Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '1.34.0' ) );
		}

		// Update to 1.35.0 — opt custom fields into the public event card printout.
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '1.35.0', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '1.35.0' ) );

			$table = $wpdb->prefix . 'remember_profile_questions';
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
				$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
				// Defaults to 0: nothing reaches a publicly posted card until an admin says so.
				if ( is_array( $cols ) && ! in_array( 'show_on_event_card', $cols, true ) ) {
					$ok = $wpdb->query(
						"ALTER TABLE {$table} ADD COLUMN show_on_event_card TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active"
					);
					if ( false === $ok ) {
						Remember_Logger::error(
							'Failed to add show_on_event_card to remember_profile_questions',
							array( 'error' => $wpdb->last_error )
						);
					} else {
						Remember_Logger::info( 'Added show_on_event_card to remember_profile_questions' );
					}
				}
			}

			update_option( 'remember_db_version', '1.35.0' );
			Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '1.35.0' ) );
		}

		// Update to 1.36.0 — gate emergency contact and health (dietary / allergy / medical).
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '1.36.0', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '1.36.0' ) );

			require_once plugin_dir_path( __FILE__ ) . '../utilities/class-remember-capabilities.php';
			require_once plugin_dir_path( __FILE__ ) . '../models/class-role.php';

			Remember_Capabilities::setup_capabilities();

			$role_model = new Remember_Role();
			$grants     = array(
				'System Administrator' => array( 'remember_access_emergency_contact', 'remember_access_health' ),
				'Vetting'              => array( 'remember_access_emergency_contact', 'remember_access_health' ),
				'Event Administrator'  => array( 'remember_access_health' ),
			);
			$role_ids = array();

			foreach ( $grants as $role_name => $caps ) {
				$role_id = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT role_id FROM {$wpdb->prefix}remember_roles WHERE role_name = %s",
						$role_name
					)
				);
				if ( $role_id <= 0 ) {
					continue;
				}
				$role_ids[] = $role_id;
				foreach ( $caps as $cap ) {
					$role_model->add_capability( $role_id, $cap );
				}
			}

			if ( ! empty( $role_ids ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $role_ids ), '%d' ) );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built from count.
				$member_ids = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT DISTINCT member_id FROM {$wpdb->prefix}remember_member_roles WHERE role_id IN ($placeholders)",
						$role_ids
					)
				);
				if ( is_array( $member_ids ) ) {
					foreach ( $member_ids as $member_id ) {
						Remember_Capabilities::sync_user_capabilities_from_roles( (int) $member_id );
					}
				}
			}

			update_option( 'remember_db_version', '1.36.0' );
			Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '1.36.0' ) );
		}

		// Update to 1.37.0 — rename emergency/health caps from read_* to access_*.
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '1.37.0', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '1.37.0' ) );

			require_once plugin_dir_path( __FILE__ ) . '../utilities/class-remember-capabilities.php';
			require_once plugin_dir_path( __FILE__ ) . '../models/class-role.php';

			Remember_Capabilities::setup_capabilities();

			$renames = array(
				'remember_read_emergency_contact' => 'remember_access_emergency_contact',
				'remember_read_health'            => 'remember_access_health',
			);

			// Drop obsolete keys from the WP administrator role if they were added earlier.
			$admin_role = get_role( 'administrator' );
			if ( $admin_role ) {
				foreach ( array_keys( $renames ) as $old_cap ) {
					$admin_role->remove_cap( $old_cap );
				}
			}

			$role_model = new Remember_Role();
			$role_ids   = array();
			$cap_table  = $wpdb->prefix . 'remember_role_capabilities';

			foreach ( $renames as $old_cap => $new_cap ) {
				$holder_ids = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT DISTINCT role_id FROM {$cap_table} WHERE capability = %s",
						$old_cap
					)
				);
				if ( ! is_array( $holder_ids ) ) {
					continue;
				}
				foreach ( $holder_ids as $role_id ) {
					$role_id = (int) $role_id;
					if ( $role_id <= 0 ) {
						continue;
					}
					$role_model->add_capability( $role_id, $new_cap );
					$role_model->remove_capability( $role_id, $old_cap );
					$role_ids[] = $role_id;
				}
			}

			$role_ids = array_values( array_unique( $role_ids ) );
			if ( ! empty( $role_ids ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $role_ids ), '%d' ) );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built from count.
				$member_ids = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT DISTINCT member_id FROM {$wpdb->prefix}remember_member_roles WHERE role_id IN ($placeholders)",
						$role_ids
					)
				);
				if ( is_array( $member_ids ) ) {
					foreach ( $member_ids as $member_id ) {
						Remember_Capabilities::sync_user_capabilities_from_roles( (int) $member_id );
					}
				}
			}

			update_option( 'remember_db_version', '1.37.0' );
			Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '1.37.0' ) );
		}

		// Update to 1.38.0 — pin "None" to the top of health catalog lists.
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '1.38.0', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '1.38.0' ) );

			require_once plugin_dir_path( __FILE__ ) . 'class-remember-seeder.php';
			$seeder = new Remember_Seeder();
			$seeder->ensure_none_options_sort_first();

			update_option( 'remember_db_version', '1.38.0' );
			Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '1.38.0' ) );
		}

		// Update to 1.39.0 — gate member profile printouts behind dedicated caps.
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '1.39.0', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '1.39.0' ) );

			require_once plugin_dir_path( __FILE__ ) . '../utilities/class-remember-capabilities.php';
			require_once plugin_dir_path( __FILE__ ) . '../models/class-role.php';

			Remember_Capabilities::setup_capabilities();

			$role_model = new Remember_Role();
			$print_caps = array( 'remember_print_confidential', 'remember_print_event_card' );
			$grants     = array(
				'System Administrator' => $print_caps,
				'Event Administrator'  => $print_caps,
			);
			$role_ids = array();

			foreach ( $grants as $role_name => $caps ) {
				$role_id = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT role_id FROM {$wpdb->prefix}remember_roles WHERE role_name = %s",
						$role_name
					)
				);
				if ( $role_id <= 0 ) {
					continue;
				}
				$role_ids[] = $role_id;
				foreach ( $caps as $cap ) {
					$role_model->add_capability( $role_id, $cap );
				}
			}

			if ( ! empty( $role_ids ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $role_ids ), '%d' ) );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built from count.
				$member_ids = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT DISTINCT member_id FROM {$wpdb->prefix}remember_member_roles WHERE role_id IN ($placeholders)",
						$role_ids
					)
				);
				if ( is_array( $member_ids ) ) {
					foreach ( $member_ids as $member_id ) {
						Remember_Capabilities::sync_user_capabilities_from_roles( (int) $member_id );
					}
				}
			}

			update_option( 'remember_db_version', '1.39.0' );
			Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '1.39.0' ) );
		}

		// Update to 1.40.0 — clothing inventory mapping (stocked + issued size).
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '1.40.0', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '1.40.0' ) );

			require_once plugin_dir_path( __FILE__ ) . 'class-remember-database.php';
			$db = new Remember_Database();
			$db->create_clothing_size_options_table();

			$sizes_table = $wpdb->prefix . 'remember_clothing_size_options';
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sizes_table ) ) === $sizes_table ) {
				$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$sizes_table}", 0 );
				if ( is_array( $cols ) ) {
					if ( ! in_array( 'is_stocked', $cols, true ) ) {
						$ok = $wpdb->query( "ALTER TABLE {$sizes_table} ADD COLUMN is_stocked TINYINT(1) DEFAULT 1 AFTER is_active" );
						if ( false === $ok ) {
							Remember_Logger::error( 'Failed to add is_stocked to clothing_size_options', array( 'error' => $wpdb->last_error ) );
						} else {
							$cols[] = 'is_stocked';
						}
					}
					if ( ! in_array( 'issued_size_code', $cols, true ) ) {
						$ok = $wpdb->query( "ALTER TABLE {$sizes_table} ADD COLUMN issued_size_code VARCHAR(20) DEFAULT NULL AFTER is_stocked" );
						if ( false === $ok ) {
							Remember_Logger::error( 'Failed to add issued_size_code to clothing_size_options', array( 'error' => $wpdb->last_error ) );
						} else {
							$cols[] = 'issued_size_code';
						}
					}
				}
				if ( is_array( $cols ) && in_array( 'issued_size_code', $cols, true ) ) {
					$wpdb->query( "UPDATE {$sizes_table} SET issued_size_code = size_code WHERE issued_size_code IS NULL OR issued_size_code = ''" );
				}
				if ( is_array( $cols ) && in_array( 'is_stocked', $cols, true ) ) {
					$wpdb->query( "UPDATE {$sizes_table} SET is_stocked = 1 WHERE is_stocked IS NULL" );
				}
			}

			require_once plugin_dir_path( __FILE__ ) . 'class-remember-seeder.php';
			$seeder = new Remember_Seeder();
			$seeder->ensure_clothing_size_options();

			update_option( 'remember_db_version', '1.40.0' );
			Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '1.40.0' ) );
		}

		// Update to 1.41.0 — write-in clothing stock table (qty later).
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '1.41.0', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '1.41.0' ) );

			require_once plugin_dir_path( __FILE__ ) . 'class-remember-database.php';
			$db = new Remember_Database();
			$db->create_clothing_stock_table();

			update_option( 'remember_db_version', '1.41.0' );
			Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '1.41.0' ) );
		}

		// Update to 2.0.0 — profile-level notes (member-visible + admin-private).
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '2.0.0', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '2.0.0' ) );

			require_once plugin_dir_path( __FILE__ ) . 'class-remember-database.php';
			$db = new Remember_Database();
			$db->create_profile_notes_table();

			update_option( 'remember_db_version', '2.0.0' );
			Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '2.0.0' ) );
		}

		// Update to 2.1.0 — duplicate profile hits + merged member status.
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '2.1.0', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '2.1.0' ) );

			require_once plugin_dir_path( __FILE__ ) . 'class-remember-database.php';
			$db = new Remember_Database();
			$db->create_profile_duplicate_hits_table();
			$db->add_merged_member_status();

			update_option( 'remember_db_version', '2.1.0' );
			Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '2.1.0' ) );
		}

		// Update to 2.1.1 — Merge Duplicate Profiles capability (default: System Administrator).
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '2.1.1', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '2.1.1' ) );

			require_once plugin_dir_path( __FILE__ ) . '../utilities/class-remember-capabilities.php';
			require_once plugin_dir_path( __FILE__ ) . '../models/class-role.php';
			require_once plugin_dir_path( __FILE__ ) . '../utilities/class-remember-profile-duplicates.php';

			Remember_Capabilities::setup_capabilities();

			$role_model = new Remember_Role();
			$cap        = Remember_Profile_Duplicates::MERGE_CAP;
			$role_id    = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT role_id FROM {$wpdb->prefix}remember_roles WHERE role_name = %s",
					'System Administrator'
				)
			);
			if ( $role_id > 0 ) {
				$role_model->add_capability( $role_id, $cap );
				$member_ids = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT DISTINCT member_id FROM {$wpdb->prefix}remember_member_roles WHERE role_id = %d",
						$role_id
					)
				);
				if ( is_array( $member_ids ) ) {
					foreach ( $member_ids as $member_id ) {
						Remember_Capabilities::sync_user_capabilities_from_roles( (int) $member_id );
					}
				}
			}

			update_option( 'remember_db_version', '2.1.1' );
			Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '2.1.1' ) );
		}

		// Update to 2.1.2 — closed status for leftover duplicate hits after a merge.
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '2.1.2', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '2.1.2' ) );

			require_once plugin_dir_path( __FILE__ ) . 'class-remember-database.php';
			$db = new Remember_Database();
			$db->add_closed_duplicate_hit_status();

			update_option( 'remember_db_version', '2.1.2' );
			Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '2.1.2' ) );
		}

		// Update to 2.1.3 — pre-merge snapshot for undo.
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '2.1.3', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '2.1.3' ) );

			require_once plugin_dir_path( __FILE__ ) . 'class-remember-database.php';
			$db = new Remember_Database();
			$db->add_duplicate_hit_undo_snapshot();

			update_option( 'remember_db_version', '2.1.3' );
			Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '2.1.3' ) );
		}

		// Update to 2.2.0 — saved reports + View Reports on Event Admin / Vetting.
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '2.2.0', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '2.2.0' ) );

			require_once plugin_dir_path( __FILE__ ) . 'class-remember-database.php';
			require_once plugin_dir_path( __FILE__ ) . '../utilities/class-remember-capabilities.php';
			require_once plugin_dir_path( __FILE__ ) . '../models/class-role.php';
			$db = new Remember_Database();
			$db->create_saved_reports_table();

			$role_model = new Remember_Role();
			$cap        = 'remember_view_reports';
			Remember_Capabilities::setup_capabilities();
			foreach ( array( 'Event Administrator', 'Vetting' ) as $role_name ) {
				$role_id = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT role_id FROM {$wpdb->prefix}remember_roles WHERE role_name = %s",
						$role_name
					)
				);
				if ( $role_id > 0 ) {
					$role_model->add_capability( $role_id, $cap );
					$member_ids = $wpdb->get_col(
						$wpdb->prepare(
							"SELECT DISTINCT member_id FROM {$wpdb->prefix}remember_member_roles WHERE role_id = %d",
							$role_id
						)
					);
					if ( is_array( $member_ids ) ) {
						foreach ( $member_ids as $member_id ) {
							Remember_Capabilities::sync_user_capabilities_from_roles( (int) $member_id );
						}
					}
				}
			}

			update_option( 'remember_db_version', '2.2.0' );
			Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '2.2.0' ) );
		}

		// Update to 2.2.1 (retry nullable primary vetter and non-unique member_id).
		// 1.1.0 and 1.3.0 advanced remember_db_version even when those ALTERs failed.
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '2.2.1', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '2.2.1' ) );

			$vetting_table = $wpdb->prefix . 'remember_vetting';
			$schema_ok     = true;
			$vetting_column = $wpdb->get_row( "SHOW COLUMNS FROM {$vetting_table} WHERE Field = 'primary_vetter_id'" );

			if ( $vetting_column && isset( $vetting_column->Null ) && false === strpos( $vetting_column->Null, 'YES' ) ) {
				$result = $wpdb->query( "ALTER TABLE {$vetting_table} MODIFY COLUMN primary_vetter_id BIGINT(20) UNSIGNED DEFAULT NULL" );
				if ( false === $result ) {
					$schema_ok = false;
					Remember_Logger::error( 'Failed to allow an unassigned primary vetter', array( 'error' => $wpdb->last_error ) );
				} else {
					Remember_Logger::info( 'Vetting table updated to allow an unassigned primary vetter' );
				}
			} elseif ( ! $vetting_column ) {
				$schema_ok = false;
				Remember_Logger::error( 'Vetting table is missing primary_vetter_id' );
			}

			$unique_member = $wpdb->get_results( "SHOW INDEX FROM {$vetting_table} WHERE Key_name = 'member_id' AND Non_unique = 0" );
			if ( ! empty( $unique_member ) ) {
				$dropped = $wpdb->query( "ALTER TABLE {$vetting_table} DROP INDEX member_id" );
				if ( false === $dropped ) {
					$schema_ok = false;
					Remember_Logger::error( 'Failed to drop unique key on vetting.member_id', array( 'error' => $wpdb->last_error ) );
				} else {
					$added = $wpdb->query( "ALTER TABLE {$vetting_table} ADD INDEX member_id (member_id)" );
					if ( false === $added ) {
						Remember_Logger::error( 'Failed to add regular index on vetting.member_id', array( 'error' => $wpdb->last_error ) );
					} else {
						Remember_Logger::info( 'Removed unique constraint on vetting.member_id' );
					}
				}
			}

			if ( $schema_ok ) {
				update_option( 'remember_db_version', '2.2.1' );
				Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '2.2.1' ) );
			}
		}

		// Update to 2.2.2 (optional event registration open and close times).
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '2.2.2', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '2.2.2' ) );

			$events_table = $wpdb->prefix . 'remember_events';
			$schema_ok    = true;
			$event_columns = $wpdb->get_col( "SHOW COLUMNS FROM {$events_table}", 0 );
			if ( ! is_array( $event_columns ) ) {
				$event_columns = array();
				$schema_ok     = false;
				Remember_Logger::error( 'Could not read remember_events columns', array( 'error' => $wpdb->last_error ) );
			}

			$registration_columns = array(
				'registration_opens_at'  => 'end_date',
				'registration_closes_at' => 'registration_opens_at',
			);
			foreach ( $registration_columns as $column_name => $after_column ) {
				if ( in_array( $column_name, $event_columns, true ) ) {
					continue;
				}
				$added = $wpdb->query( "ALTER TABLE {$events_table} ADD COLUMN {$column_name} DATETIME NULL DEFAULT NULL AFTER {$after_column}" );
				if ( false === $added ) {
					$schema_ok = false;
					Remember_Logger::error(
						'Failed to add event registration column',
						array(
							'column' => $column_name,
							'error'  => $wpdb->last_error,
						)
					);
				} else {
					$event_columns[] = $column_name;
					Remember_Logger::info( 'Added event registration column', array( 'column' => $column_name ) );
				}
			}

			if ( $schema_ok ) {
				update_option( 'remember_db_version', '2.2.2' );
				Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '2.2.2' ) );
			}
		}

		// Update to 2.2.3 (allergy reaction text on the member profile).
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '2.2.3', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '2.2.3' ) );
			require_once plugin_dir_path( __FILE__ ) . '../utilities/class-remember-profile-fields.php';
			if ( Remember_Profile_Fields::ensure_allergy_reaction_column() ) {
				update_option( 'remember_db_version', '2.2.3' );
				Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '2.2.3' ) );
			}
		}

		// Update to 2.3.0 (event surveys).
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '2.3.0', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '2.3.0' ) );
			require_once plugin_dir_path( __FILE__ ) . 'class-remember-database.php';
			require_once plugin_dir_path( __FILE__ ) . '../utilities/class-remember-page-creator.php';
			$db = new Remember_Database();
			$db->create_surveys_table();
			$db->create_survey_questions_table();
			$db->create_survey_responses_table();
			$db->create_survey_answers_table();
			Remember_Page_Creator::create_pages( array( 'survey' ) );
			update_option( 'remember_db_version', '2.3.0' );
			Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '2.3.0' ) );
		}

		// Update to 2.3.1 (survey questions match custom-field shape).
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '2.3.1', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '2.3.1' ) );
			global $wpdb;
			$table   = $wpdb->prefix . 'remember_survey_questions';
			$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
			if ( ! is_array( $columns ) ) {
				$columns = array();
				Remember_Logger::error( 'Could not read survey question columns', array( 'error' => $wpdb->last_error ) );
			}
			$add = array(
				'field_key'           => "ALTER TABLE {$table} ADD COLUMN field_key VARCHAR(64) NOT NULL DEFAULT '' AFTER label",
				'is_required'         => "ALTER TABLE {$table} ADD COLUMN is_required TINYINT(1) NOT NULL DEFAULT 0 AFTER options_text",
				'required_when_json'  => "ALTER TABLE {$table} ADD COLUMN required_when_json TEXT NULL AFTER is_required",
			);
			$schema_ok = true;
			foreach ( $add as $column_name => $sql ) {
				if ( in_array( $column_name, $columns, true ) ) {
					continue;
				}
				$added = $wpdb->query( $sql );
				if ( false === $added ) {
					$schema_ok = false;
					Remember_Logger::error( 'Failed to add survey question column', array( 'column' => $column_name, 'error' => $wpdb->last_error ) );
				} else {
					Remember_Logger::info( 'Added survey question column', array( 'column' => $column_name ) );
				}
			}
			if ( $schema_ok ) {
				update_option( 'remember_db_version', '2.3.1' );
				Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '2.3.1' ) );
			}
		}

		// Update to 2.3.2 (survey header instructions).
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '2.3.2', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '2.3.2' ) );
			global $wpdb;
			$table   = $wpdb->prefix . 'remember_surveys';
			$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
			if ( ! is_array( $columns ) ) {
				$columns = array();
				Remember_Logger::error( 'Could not read survey columns', array( 'error' => $wpdb->last_error ) );
			}
			$schema_ok = true;
			if ( ! in_array( 'instructions', $columns, true ) ) {
				$added = $wpdb->query( "ALTER TABLE {$table} ADD COLUMN instructions TEXT NULL AFTER title" );
				if ( false === $added ) {
					$schema_ok = false;
					Remember_Logger::error( 'Failed to add survey instructions column', array( 'error' => $wpdb->last_error ) );
				} else {
					Remember_Logger::info( 'Added survey column', array( 'column' => 'instructions' ) );
				}
			}
			if ( $schema_ok ) {
				update_option( 'remember_db_version', '2.3.2' );
				Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '2.3.2' ) );
			}
		}

		// Update to 2.3.3 (optional event role on a survey).
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '2.3.3', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '2.3.3' ) );
			global $wpdb;
			$table   = $wpdb->prefix . 'remember_surveys';
			$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
			if ( ! is_array( $columns ) ) {
				$columns = array();
				Remember_Logger::error( 'Could not read survey columns', array( 'error' => $wpdb->last_error ) );
			}
			$schema_ok = true;
			if ( ! in_array( 'event_role_id', $columns, true ) ) {
				$added = $wpdb->query( "ALTER TABLE {$table} ADD COLUMN event_role_id BIGINT(20) UNSIGNED DEFAULT NULL AFTER event_id" );
				if ( false === $added ) {
					$schema_ok = false;
					Remember_Logger::error( 'Failed to add survey role column', array( 'error' => $wpdb->last_error ) );
				} else {
					Remember_Logger::info( 'Added survey column', array( 'column' => 'event_role_id' ) );
				}
			}
			if ( $schema_ok ) {
				$index = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'event_role'" );
				if ( empty( $index ) ) {
					$indexed = $wpdb->query( "ALTER TABLE {$table} ADD KEY event_role (event_id, event_role_id, placement)" );
					if ( false === $indexed ) {
						Remember_Logger::error( 'Failed to add survey role index', array( 'error' => $wpdb->last_error ) );
					}
				}
				update_option( 'remember_db_version', '2.3.3' );
				Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '2.3.3' ) );
			}
		}

		// Update to 2.3.4 (a survey can include several roles; none selected means every role).
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '2.3.4', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '2.3.4' ) );
			require_once plugin_dir_path( __FILE__ ) . 'class-remember-database.php';
			$db = new Remember_Database();
			$db->create_survey_roles_table();
			global $wpdb;
			$surveys = $wpdb->prefix . 'remember_surveys';
			$roles   = $wpdb->prefix . 'remember_survey_roles';
			$copied  = $wpdb->query( "INSERT INTO {$roles} (survey_id, event_role_id) SELECT survey_id, event_role_id FROM {$surveys} s WHERE s.event_role_id IS NOT NULL AND s.event_role_id > 0 AND NOT EXISTS (SELECT 1 FROM {$roles} sr WHERE sr.survey_id = s.survey_id AND sr.event_role_id = s.event_role_id)" );
			if ( false === $copied ) {
				Remember_Logger::error( 'Failed to copy survey roles', array( 'error' => $wpdb->last_error ) );
			} else {
				$wpdb->query( "UPDATE {$surveys} SET event_role_id = NULL WHERE event_role_id IS NOT NULL" );
				update_option( 'remember_db_version', '2.3.4' );
				Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '2.3.4' ) );
			}
		}

		// Update to 2.3.5 (billing secrets no longer use a key stored in the database).
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '2.3.5', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '2.3.5' ) );
			require_once plugin_dir_path( __FILE__ ) . '../utilities/class-remember-billing-crypto.php';
			require_once plugin_dir_path( __FILE__ ) . '../integrations/class-remember-quickbooks-oauth.php';
			require_once plugin_dir_path( __FILE__ ) . '../integrations/class-remember-xero-oauth.php';
			Remember_QuickBooks_OAuth::get_settings();
			Remember_Xero_OAuth::get_settings();
			if ( ! Remember_Billing_Crypto::legacy_secrets_remain() ) {
				delete_option( 'remember_qb_encryption_key' );
				delete_option( 'remember_xero_encryption_key' );
				update_option( 'remember_db_version', '2.3.5' );
				Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '2.3.5' ) );
			}
		}

		// Update to 2.3.6 (log of who viewed or exported health and emergency-contact data).
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '2.3.6', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '2.3.6' ) );
			require_once plugin_dir_path( __FILE__ ) . 'class-remember-database.php';
			$db = new Remember_Database();
			$db->create_sensitive_access_log_table();
			$log_table = $wpdb->prefix . 'remember_sensitive_access_log';
			$exists    = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $log_table ) );
			if ( $exists === $log_table ) {
				update_option( 'remember_db_version', '2.3.6' );
				Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '2.3.6' ) );
			} else {
				Remember_Logger::error( 'Failed to create sensitive access log table' );
			}
		}

		// Update to 2.3.7 (per-event waitlist action when an accepted spot opens).
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '2.3.7', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '2.3.7' ) );
			$events_table = $wpdb->prefix . 'remember_events';
			$column       = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$events_table} LIKE %s", 'waitlist_mode' ) );
			if ( 'waitlist_mode' !== $column ) {
				$added = $wpdb->query( "ALTER TABLE {$events_table} ADD COLUMN waitlist_mode VARCHAR(20) NOT NULL DEFAULT 'off' AFTER registration_closes_at" );
				if ( false === $added ) {
					Remember_Logger::error( 'Failed to add waitlist mode column', array( 'error' => $wpdb->last_error ) );
				}
			}
			$column = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$events_table} LIKE %s", 'waitlist_mode' ) );
			if ( 'waitlist_mode' === $column ) {
				update_option( 'remember_db_version', '2.3.7' );
				Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '2.3.7' ) );
			}
		}

		// Update to 2.3.8 (optional door check-in on the existing admission ticket).
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '2.3.8', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '2.3.8' ) );
			$events_table = $wpdb->prefix . 'remember_events';
			$apps_table   = $wpdb->prefix . 'remember_event_applications';
			$schema_ok    = true;
			$event_col    = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$events_table} LIKE %s", 'checkin_enabled' ) );
			if ( 'checkin_enabled' !== $event_col ) {
				$added = $wpdb->query( "ALTER TABLE {$events_table} ADD COLUMN checkin_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER waitlist_mode" );
				if ( false === $added ) {
					$schema_ok = false;
					Remember_Logger::error( 'Failed to add check-in column', array( 'error' => $wpdb->last_error ) );
				}
			}
			foreach ( array( 'checked_in_at' => 'DATETIME NULL DEFAULT NULL', 'checked_in_by' => 'BIGINT(20) UNSIGNED NULL DEFAULT NULL' ) as $column_name => $definition ) {
				$found = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$apps_table} LIKE %s", $column_name ) );
				if ( $column_name === $found ) {
					continue;
				}
				$added = $wpdb->query( "ALTER TABLE {$apps_table} ADD COLUMN {$column_name} {$definition}" );
				if ( false === $added ) {
					$schema_ok = false;
					Remember_Logger::error( 'Failed to add check-in application column', array( 'column' => $column_name, 'error' => $wpdb->last_error ) );
				}
			}
			if ( $schema_ok ) {
				$admin = get_role( 'administrator' );
				if ( $admin ) {
					$admin->add_cap( 'remember_checkin_attendees' );
				}
				require_once plugin_dir_path( __FILE__ ) . '../models/class-role.php';
				$role_model = new Remember_Role();
				$event_admin = $wpdb->get_var( $wpdb->prepare( "SELECT role_id FROM {$wpdb->prefix}remember_roles WHERE role_name = %s", 'Event Administrator' ) );
				if ( $event_admin ) {
					$role_model->add_capability( (int) $event_admin, 'remember_checkin_attendees' );
				}
				update_option( 'remember_db_version', '2.3.8' );
				Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '2.3.8' ) );
			}
		}

		// Update to 2.3.9 (email a saved report on a schedule).
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '2.3.9', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '2.3.9' ) );
			require_once plugin_dir_path( __FILE__ ) . 'class-remember-database.php';
			$db = new Remember_Database();
			$db->create_report_schedules_table();
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'remember_report_schedules' ) );
			if ( $found === $wpdb->prefix . 'remember_report_schedules' ) {
				update_option( 'remember_db_version', '2.3.9' );
				Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '2.3.9' ) );
			} else {
				Remember_Logger::error( 'Failed to add report schedule table', array( 'error' => $wpdb->last_error ) );
			}
		}

		// Update to 2.4.0 (private event invitees).
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '2.4.0', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '2.4.0' ) );
			require_once plugin_dir_path( __FILE__ ) . 'class-remember-database.php';
			$db = new Remember_Database();
			$db->create_event_invites_table();
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'remember_event_invites' ) );
			if ( $found === $wpdb->prefix . 'remember_event_invites' ) {
				update_option( 'remember_db_version', '2.4.0' );
				Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '2.4.0' ) );
			} else {
				Remember_Logger::error( 'Failed to add event invites table', array( 'error' => $wpdb->last_error ) );
			}
		}

		// Update to 2.4.1 (Vetting Case Assigned email names the assigned vetter).
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '2.4.1', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '2.4.1' ) );
			if ( self::reset_unedited_vetting_assigned_body() ) {
				update_option( 'remember_db_version', '2.4.1' );
				Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '2.4.1' ) );
			}
		}

		// Update to 2.4.2 (survey roles move from the survey to each question; one application survey per event).
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '2.4.2', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '2.4.2' ) );
			require_once plugin_dir_path( __FILE__ ) . 'class-remember-database.php';
			$db = new Remember_Database();
			$db->create_survey_question_roles_table();
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'remember_survey_question_roles' ) );
			if ( $found !== $wpdb->prefix . 'remember_survey_question_roles' ) {
				Remember_Logger::error( 'Failed to add survey question roles table', array( 'error' => $wpdb->last_error ) );
			} elseif ( self::move_survey_roles_to_questions() ) {
				update_option( 'remember_db_version', '2.4.2' );
				Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '2.4.2' ) );
			}
		}

		// Update to 2.4.3 (platform profile addresses, and Telegram names stored without @).
		if ( version_compare( get_option( 'remember_db_version', '0.0.0' ), '2.4.3', '<' ) ) {
			Remember_Logger::info( 'Updating database schema', array( 'from' => get_option( 'remember_db_version', '0.0.0' ), 'to' => '2.4.3' ) );
			require_once plugin_dir_path( __FILE__ ) . '../utilities/class-remember-platform-handles.php';
			if ( Remember_Platform_Handles::install() ) {
				update_option( 'remember_db_version', '2.4.3' );
				Remember_Logger::info( 'Database schema updated successfully', array( 'version' => '2.4.3' ) );
			} else {
				Remember_Logger::error( 'Failed to add platform profile addresses', array( 'error' => $wpdb->last_error ) );
			}
		}

		// Always re-ensure health catalogs (idempotent). Catches sites that stalled mid-migration
		// or activated before catalog seed rows were added.
		require_once plugin_dir_path( __FILE__ ) . 'class-remember-seeder.php';
		$seeder = new Remember_Seeder();
		$seeder->ensure_health_catalog_options();
		$seeder->ensure_im_platforms();
		$seeder->ensure_clothing_size_options();
		$seeder->ensure_notification_settings();

		Remember_Logger::activation_debug(
			'update_schema: exit',
			array( 'remember_db_version' => get_option( 'remember_db_version', '0.0.0' ) )
		);
	}

	/**
	 * Clear a saved Vetting Case Assigned body that still matches the old default.
	 *
	 * The old default told every recipient "You have been assigned", even when
	 * the whole vetting team gets the email. A cleared body falls back to the
	 * shipped default. Bodies an admin edited are kept.
	 *
	 * @return bool False only when the database update fails.
	 */
	private static function reset_unedited_vetting_assigned_body() {
		global $wpdb;
		$table = $wpdb->prefix . 'remember_notification_settings';
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT setting_id, body_template FROM {$table} WHERE notification_type = %s", 'vetting_assigned' ) );
		if ( ! $row || '' === trim( (string) $row->body_template ) ) {
			return true;
		}

		$normalize = static function ( $text ) {
			$text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, 'UTF-8' );
			return trim( preg_replace( '/\s+/u', ' ', $text ) );
		};
		$old_default = "Hello,\n\nYou have been assigned as the primary vetter for a vetting case.\n\nMember: {member_name}\nVetting Case ID: {vetting_id}\nDate: {date}\n\nPlease review the case and begin the vetting process.";
		if ( $normalize( $row->body_template ) !== $normalize( $old_default ) ) {
			Remember_Logger::info( 'Kept edited Vetting Case Assigned email body', array( 'setting_id' => (int) $row->setting_id ) );
			return true;
		}

		$updated = $wpdb->update(
			$table,
			array(
				'body_template' => '',
				'updated_at'    => current_time( 'mysql' ),
			),
			array( 'setting_id' => (int) $row->setting_id )
		);
		if ( false === $updated ) {
			Remember_Logger::error( 'Failed to reset Vetting Case Assigned email body', array( 'error' => $wpdb->last_error ) );
			return false;
		}
		Remember_Logger::info( 'Reset Vetting Case Assigned email body to the new default', array( 'setting_id' => (int) $row->setting_id ) );
		return true;
	}

	/**
	 * Give every survey question the roles its survey had, then merge each event's application surveys into one.
	 *
	 * The survey kept is the one for every role, or else the oldest. Questions from the others move onto it,
	 * keeping their roles and answers; a short name that clashes gets a numbered suffix. A member who answered
	 * two of the merged surveys ends up with one response holding both sets of answers. Runs in one transaction.
	 *
	 * @return bool False when the database refused a step; nothing is changed in that case.
	 */
	private static function move_survey_roles_to_questions() {
		global $wpdb;
		require_once plugin_dir_path( __FILE__ ) . '../utilities/class-remember-profile-questions.php';
		$surveys   = $wpdb->prefix . 'remember_surveys';
		$roles     = $wpdb->prefix . 'remember_survey_roles';
		$questions = $wpdb->prefix . 'remember_survey_questions';
		$qroles    = $wpdb->prefix . 'remember_survey_question_roles';
		$responses = $wpdb->prefix . 'remember_survey_responses';
		$answers   = $wpdb->prefix . 'remember_survey_answers';
		$has_roles = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $roles ) ) === $roles;

		$failed = static function ( $step ) use ( $wpdb ) {
			Remember_Logger::error( 'Failed to move survey roles to questions', array( 'step' => $step, 'error' => $wpdb->last_error ) );
			$wpdb->query( 'ROLLBACK' );
			return false;
		};

		$wpdb->query( 'START TRANSACTION' );

		if ( $has_roles ) {
			$copied = $wpdb->query( "INSERT INTO {$qroles} (question_id, event_role_id) SELECT q.question_id, sr.event_role_id FROM {$questions} q INNER JOIN {$roles} sr ON sr.survey_id = q.survey_id WHERE NOT EXISTS (SELECT 1 FROM {$qroles} x WHERE x.question_id = q.question_id AND x.event_role_id = sr.event_role_id)" );
			if ( false === $copied ) {
				return $failed( 'copy_roles' );
			}
		}

		$role_count = $has_roles ? "(SELECT COUNT(*) FROM {$roles} sr WHERE sr.survey_id = s.survey_id)" : '0';
		$rows       = $wpdb->get_results( $wpdb->prepare( "SELECT s.survey_id, s.event_id, s.title, s.instructions, {$role_count} AS role_count FROM {$surveys} s WHERE s.placement = %s ORDER BY s.event_id ASC, s.survey_id ASC", 'application' ) );
		if ( ! is_array( $rows ) ) {
			return $failed( 'read_application_surveys' );
		}
		$by_event = array();
		foreach ( $rows as $row ) {
			$by_event[ (int) $row->event_id ][] = $row;
		}

		$merged  = 0;
		$notices = array();
		foreach ( $by_event as $event_id => $event_surveys ) {
			if ( count( $event_surveys ) < 2 ) {
				continue;
			}
			usort(
				$event_surveys,
				static function ( $a, $b ) {
					$a_all = 0 === (int) $a->role_count ? 0 : 1;
					$b_all = 0 === (int) $b->role_count ? 0 : 1;
					return $a_all !== $b_all ? $a_all - $b_all : (int) $a->survey_id - (int) $b->survey_id;
				}
			);
			$keep              = array_shift( $event_surveys );
			$keep_id           = (int) $keep->survey_id;
			$used              = array_flip( (array) $wpdb->get_col( $wpdb->prepare( "SELECT field_key FROM {$questions} WHERE survey_id = %d", $keep_id ) ) );
			$keep_instructions = trim( (string) $keep->instructions );
			$merged_away       = array();

			foreach ( $event_surveys as $other ) {
				$other_id  = (int) $other->survey_id;
				$other_qs  = $wpdb->get_results( $wpdb->prepare( "SELECT question_id, field_key, required_when_json FROM {$questions} WHERE survey_id = %d", $other_id ) );
				$renamed   = array();
				foreach ( (array) $other_qs as $question ) {
					$key = (string) $question->field_key;
					if ( '' === $key || ! isset( $used[ $key ] ) ) {
						$used[ $key ] = true;
						continue;
					}
					$n = 2;
					do {
						$new_key = substr( $key, 0, 60 ) . '_' . $n;
						++$n;
					} while ( isset( $used[ $new_key ] ) );
					$used[ $new_key ] = true;
					$renamed[ $key ]  = $new_key;
					if ( false === $wpdb->update( $questions, array( 'field_key' => $new_key ), array( 'question_id' => (int) $question->question_id ) ) ) {
						return $failed( 'rename_field_key' );
					}
				}
				if ( $renamed ) {
					foreach ( (array) $other_qs as $question ) {
						$rule = Remember_Profile_Questions::parse_required_when( $question->required_when_json );
						if ( ! $rule || ! isset( $renamed[ $rule['field_key'] ] ) ) {
							continue;
						}
						$json = Remember_Profile_Questions::encode_required_when( $renamed[ $rule['field_key'] ], $rule['values'] );
						if ( false === $wpdb->update( $questions, array( 'required_when_json' => $json ), array( 'question_id' => (int) $question->question_id ) ) ) {
							return $failed( 'rename_required_when' );
						}
					}
				}

				$offset = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(sort_order), -1) + 1 FROM {$questions} WHERE survey_id = %d", $keep_id ) );
				$low    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MIN(sort_order), 0) FROM {$questions} WHERE survey_id = %d", $other_id ) );
				if ( false === $wpdb->query( $wpdb->prepare( "UPDATE {$questions} SET survey_id = %d, sort_order = sort_order - %d + %d WHERE survey_id = %d", $keep_id, $low, $offset, $other_id ) ) ) {
					return $failed( 'move_questions' );
				}

				$other_responses = $wpdb->get_results( $wpdb->prepare( "SELECT response_id, member_id, submitted_at FROM {$responses} WHERE survey_id = %d", $other_id ) );
				$combined        = 0;
				foreach ( (array) $other_responses as $response ) {
					$kept = $wpdb->get_row( $wpdb->prepare( "SELECT response_id, submitted_at FROM {$responses} WHERE survey_id = %d AND member_id = %d", $keep_id, (int) $response->member_id ) );
					if ( ! $kept ) {
						if ( false === $wpdb->update( $responses, array( 'survey_id' => $keep_id ), array( 'response_id' => (int) $response->response_id ) ) ) {
							return $failed( 'move_response' );
						}
						continue;
					}
					if ( false === $wpdb->update( $answers, array( 'response_id' => (int) $kept->response_id ), array( 'response_id' => (int) $response->response_id ) ) ) {
						return $failed( 'combine_answers' );
					}
					if ( strcmp( (string) $response->submitted_at, (string) $kept->submitted_at ) > 0
						&& false === $wpdb->update( $responses, array( 'submitted_at' => $response->submitted_at ), array( 'response_id' => (int) $kept->response_id ) ) ) {
						return $failed( 'combine_response_date' );
					}
					if ( false === $wpdb->delete( $responses, array( 'response_id' => (int) $response->response_id ) ) ) {
						return $failed( 'combine_response' );
					}
					++$combined;
				}

				if ( $has_roles && false === $wpdb->delete( $roles, array( 'survey_id' => $other_id ) ) ) {
					return $failed( 'delete_survey_roles' );
				}
				if ( false === $wpdb->delete( $surveys, array( 'survey_id' => $other_id ) ) ) {
					return $failed( 'delete_survey' );
				}
				++$merged;
				$other_instructions = trim( (string) $other->instructions );
				if ( '' !== $other_instructions ) {
					$heading           = '' !== trim( (string) $other->title ) ? trim( (string) $other->title ) : sprintf( 'Survey #%d', $other_id );
					$keep_instructions = ltrim( $keep_instructions . "\n\n" . $heading . ":\n" . $other_instructions );
				}
				$merged_away[] = array(
					'survey_id'    => $other_id,
					'title'        => (string) $other->title,
					'instructions' => (string) $other->instructions,
				);
				Remember_Logger::info(
					'Merged application survey',
					array(
						'event_id'            => (int) $event_id,
						'survey_id'           => $keep_id,
						'merged_survey_id'    => $other_id,
						'merged_title'        => (string) $other->title,
						'merged_instructions' => (string) $other->instructions,
						'renamed_keys'        => $renamed,
						'responses_moved'     => count( (array) $other_responses ) - $combined,
						'responses_combined'  => $combined,
					)
				);
			}

			if ( $keep_instructions !== trim( (string) $keep->instructions )
				&& false === $wpdb->update( $surveys, array( 'instructions' => $keep_instructions ), array( 'survey_id' => $keep_id ) ) ) {
				return $failed( 'append_instructions' );
			}
			$notices[] = array(
				'event_id'  => (int) $event_id,
				'survey_id' => $keep_id,
				'title'     => (string) $keep->title,
				'merged'    => $merged_away,
			);
		}

		if ( $has_roles && false === $wpdb->query( "DELETE FROM {$roles}" ) ) {
			return $failed( 'clear_survey_roles' );
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			return $failed( 'commit' );
		}
		if ( $notices ) {
			$previous = get_option( 'remember_survey_merge_notice', array() );
			$notices  = array_merge( is_array( $previous ) ? $previous : array(), $notices );
			if ( ! update_option( 'remember_survey_merge_notice', $notices, false ) ) {
				Remember_Logger::warning( 'Failed to save the merged surveys notice', array( 'notices' => $notices ) );
			}
		}
		Remember_Logger::info( 'Survey roles moved to questions', array( 'surveys_merged' => $merged ) );
		return true;
	}
}
