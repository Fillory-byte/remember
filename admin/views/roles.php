<?php
/**
 * Roles view
 *
 * @package    reMember
 * @subpackage reMember/admin/views
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-logger.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/models/class-role.php';
require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-capabilities.php';

Remember_Logger::debug( 'Roles page loaded' );

$role_model = new Remember_Role();

// Handle delete (GET request)
if ( isset( $_GET['delete'] ) && isset( $_GET['remember_role_nonce'] ) && check_admin_referer( 'remember_role_action', 'remember_role_nonce' ) ) {
	// Check capability
	if ( ! current_user_can( 'remember_delete_roles' ) ) {
		wp_die( __( 'You do not have sufficient permissions to perform this action.', 'remember' ), __( 'Access Denied', 'remember' ), array( 'response' => 403 ) );
	}
	
	$role_id = absint( $_GET['delete'] );
	$role_to_delete = $role_model->get( $role_id );
	if ( Remember_Capabilities::is_protected_system_role( $role_to_delete ) ) {
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'The System Administrator role cannot be deleted.', 'remember' ) . '</p></div>';
	} else {
		global $wpdb;
		$member_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT member_id FROM {$wpdb->prefix}remember_member_roles WHERE role_id = %d",
				$role_id
			)
		);
		$wpdb->delete( $wpdb->prefix . 'remember_role_capabilities', array( 'role_id' => $role_id ), array( '%d' ) );
		$result = $role_model->delete( $role_id );
		if ( $result !== false ) {
			Remember_Capabilities::sync_users( is_array( $member_ids ) ? $member_ids : array() );
			Remember_Logger::info( 'Role deleted', array( 'role_id' => $role_id ) );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Role deleted successfully.', 'remember' ) . '</p></div>';
		} else {
			Remember_Logger::error( 'Failed to delete role', array( 'role_id' => $role_id ) );
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Failed to delete role.', 'remember' ) . '</p></div>';
		}
	}
}

// Handle form submissions (POST requests)
if ( isset( $_POST['remember_role_action'] ) && check_admin_referer( 'remember_role_action', 'remember_role_nonce' ) ) {
	$action = sanitize_text_field( $_POST['remember_role_action'] );
	
	if ( 'add' === $action ) {
		// Check capability
		if ( ! current_user_can( 'remember_create_roles' ) ) {
			wp_die( __( 'You do not have sufficient permissions to perform this action.', 'remember' ), __( 'Access Denied', 'remember' ), array( 'response' => 403 ) );
		}
		
		$data = array(
			'role_name'     => sanitize_text_field( wp_unslash( $_POST['role_name'] ) ),
			'role_type'     => sanitize_text_field( $_POST['role_type'] ),
			'is_event_role' => ( 'event' === $_POST['role_type'] ) ? 1 : 0,
			'description'   => isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '',
		);
		$role_id = $role_model->create( $data );
		if ( $role_id ) {
			// Set capabilities if provided — only caps the actor can grant.
			if ( isset( $_POST['capabilities'] ) && is_array( $_POST['capabilities'] ) ) {
				$capabilities = Remember_Capabilities::filter_grantable_capabilities( $_POST['capabilities'] );
				$role_model->set_capabilities( $role_id, $capabilities );
			}
			Remember_Logger::info( 'Role created', array( 'role_id' => $role_id ) );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Role created successfully.', 'remember' ) . '</p></div>';
		} else {
			Remember_Logger::error( 'Failed to create role' );
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Failed to create role.', 'remember' ) . '</p></div>';
		}
	} elseif ( 'update_role' === $action && isset( $_POST['role_id'] ) ) {
		// Check capability
		if ( ! current_user_can( 'remember_update_roles' ) ) {
			wp_die( __( 'You do not have sufficient permissions to perform this action.', 'remember' ), __( 'Access Denied', 'remember' ), array( 'response' => 403 ) );
		}
		
		$role_id = absint( $_POST['role_id'] );
		$existing_role = $role_model->get( $role_id );
		if ( ! $existing_role ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Role not found.', 'remember' ) . '</p></div>';
		} elseif ( Remember_Capabilities::is_protected_system_role( $existing_role ) && ! current_user_can( 'manage_options' ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Only a WordPress administrator can edit the System Administrator role.', 'remember' ) . '</p></div>';
		} else {
			$role_name = sanitize_text_field( wp_unslash( $_POST['role_name'] ) );
			// Keep the protected role name stable.
			if ( Remember_Capabilities::is_protected_system_role( $existing_role ) ) {
				$role_name = 'System Administrator';
			}

			// Update role details
			$data = array(
				'role_name'        => $role_name,
				'role_type'        => sanitize_text_field( $_POST['role_type'] ),
				'is_event_role'    => ( 'event' === $_POST['role_type'] ) ? 1 : 0,
				'show_in_frontend' => isset( $_POST['show_in_frontend'] ) && $_POST['show_in_frontend'] ? 1 : 0,
				'description'      => isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '',
			);
			$update_result = $role_model->update( $role_id, $data );
			
			// Update capabilities — preserve caps the actor cannot grant.
			$posted_caps = isset( $_POST['capabilities'] ) && is_array( $_POST['capabilities'] )
				? $_POST['capabilities']
				: array();
			$capabilities = Remember_Capabilities::merge_role_capabilities(
				$role_model->get_capabilities( $role_id ),
				$posted_caps
			);
			$capabilities_result = $role_model->set_capabilities( $role_id, $capabilities );
			
			if ( $update_result !== false && $capabilities_result ) {
				Remember_Logger::info( 'Role updated', array( 'role_id' => $role_id ) );
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Role updated successfully.', 'remember' ) . '</p></div>';
				// Refresh the role data
				$editing_role = $role_model->get( $role_id );
			} else {
				Remember_Logger::error( 'Failed to update role', array( 'role_id' => $role_id ) );
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Failed to update role.', 'remember' ) . '</p></div>';
			}
		}
	} elseif ( 'update_capabilities' === $action && isset( $_POST['role_id'] ) ) {
		if ( ! current_user_can( 'remember_update_roles' ) ) {
			wp_die( __( 'You do not have sufficient permissions to perform this action.', 'remember' ), __( 'Access Denied', 'remember' ), array( 'response' => 403 ) );
		}
		$role_id = absint( $_POST['role_id'] );
		$existing_role = $role_model->get( $role_id );
		if ( Remember_Capabilities::is_protected_system_role( $existing_role ) && ! current_user_can( 'manage_options' ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Only a WordPress administrator can edit the System Administrator role.', 'remember' ) . '</p></div>';
		} else {
			$posted_caps = isset( $_POST['capabilities'] ) && is_array( $_POST['capabilities'] )
				? $_POST['capabilities']
				: array();
			$capabilities = Remember_Capabilities::merge_role_capabilities(
				$role_model->get_capabilities( $role_id ),
				$posted_caps
			);
			$result = $role_model->set_capabilities( $role_id, $capabilities );
			if ( $result ) {
				Remember_Logger::info( 'Role capabilities updated', array( 'role_id' => $role_id ) );
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Capabilities updated successfully.', 'remember' ) . '</p></div>';
			} else {
				Remember_Logger::error( 'Failed to update capabilities', array( 'role_id' => $role_id ) );
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Failed to update capabilities.', 'remember' ) . '</p></div>';
			}
		}
	}
}

// Get roles
$event_roles = $role_model->get_event_roles();
$system_roles = $role_model->get_system_roles();

// Capability matrix data for add / edit forms
$capability_modules   = Remember_Capabilities::get_capability_modules();
$capability_actions   = Remember_Capabilities::get_capability_actions();
$special_capabilities = Remember_Capabilities::get_special_capabilities();

/**
 * Render the Create / Read / Edit / Delete matrix plus special caps.
 *
 * @param array<int, string> $selected Capability keys currently granted.
 * @return void
 */
$remember_render_capability_matrix = function ( $selected = array() ) use ( $capability_modules, $capability_actions, $special_capabilities ) {
	$selected = is_array( $selected ) ? $selected : array();
	?>
	<div class="remember-cap-matrix-wrap">
		<table class="remember-cap-matrix" role="grid">
			<thead>
				<tr>
					<th scope="col" class="remember-cap-matrix__module"><?php esc_html_e( 'Capability', 'remember' ); ?></th>
					<?php foreach ( $capability_actions as $action_label ) : ?>
						<th scope="col" class="remember-cap-matrix__action"><?php echo esc_html( $action_label ); ?></th>
					<?php endforeach; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $capability_modules as $module => $module_label ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $module_label ); ?></th>
						<?php foreach ( $capability_actions as $action => $action_label ) : ?>
							<?php
							$cap       = "remember_{$action}_{$module}";
							$id        = 'cap_' . $cap;
							$can_grant = Remember_Capabilities::current_user_can_grant( $cap );
							?>
							<td>
								<label class="screen-reader-text" for="<?php echo esc_attr( $id ); ?>">
									<?php
									printf(
										/* translators: 1: action (Create/Read/Edit/Delete), 2: module name */
										esc_html__( '%1$s %2$s', 'remember' ),
										esc_html( $action_label ),
										esc_html( $module_label )
									);
									?>
								</label>
								<input
									type="checkbox"
									id="<?php echo esc_attr( $id ); ?>"
									name="capabilities[]"
									value="<?php echo esc_attr( $cap ); ?>"
									<?php checked( in_array( $cap, $selected, true ) ); ?>
									<?php disabled( ! $can_grant ); ?>
								>
							</td>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( ! empty( $special_capabilities ) ) : ?>
			<div class="remember-cap-special">
				<h4 class="remember-cap-special__title"><?php esc_html_e( 'Other', 'remember' ); ?></h4>
				<ul class="remember-cap-special__list">
					<?php foreach ( $special_capabilities as $cap => $label ) : ?>
						<?php
						$id        = 'cap_' . $cap;
						$can_grant = Remember_Capabilities::current_user_can_grant( $cap );
						?>
						<li>
							<label for="<?php echo esc_attr( $id ); ?>">
								<input
									type="checkbox"
									id="<?php echo esc_attr( $id ); ?>"
									name="capabilities[]"
									value="<?php echo esc_attr( $cap ); ?>"
									<?php checked( in_array( $cap, $selected, true ) ); ?>
									<?php disabled( ! $can_grant ); ?>
								>
								<?php echo esc_html( $label ); ?>
							</label>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>
	</div>
	<?php
};

// Check if editing capabilities for a role
$editing_role_id = isset( $_GET['edit_capabilities'] ) ? absint( $_GET['edit_capabilities'] ) : 0;
$editing_role = $editing_role_id > 0 ? $role_model->get( $editing_role_id ) : null;
$editing_role_capabilities = $editing_role ? $role_model->get_capabilities( $editing_role_id ) : array();

if ( $editing_role ) {
	if ( ! current_user_can( 'remember_update_roles' ) ) {
		wp_die( __( 'You do not have sufficient permissions to edit roles.', 'remember' ), __( 'Access Denied', 'remember' ), array( 'response' => 403 ) );
	}
	if ( Remember_Capabilities::is_protected_system_role( $editing_role ) && ! current_user_can( 'manage_options' ) ) {
		wp_die( __( 'Only a WordPress administrator can edit the System Administrator role.', 'remember' ), __( 'Access Denied', 'remember' ), array( 'response' => 403 ) );
	}
}
?>

<div class="wrap remember-roles">
	<h1 class="wp-heading-inline"><?php echo esc_html( get_admin_page_title() ); ?></h1>
	<?php if ( ! $editing_role ) : ?>
		<?php if ( current_user_can( 'remember_create_roles' ) ) : ?>
			<button type="button" class="page-title-action" onclick="document.getElementById('remember-add-role').style.display='block'; this.style.display='none';"><?php esc_html_e( 'Add New', 'remember' ); ?></button>
		<?php endif; ?>
	<?php else : ?>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=remember-roles' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Back to Roles', 'remember' ); ?></a>
	<?php endif; ?>
	<hr class="wp-header-end">

	<?php if ( ! $editing_role ) : ?>
		<!-- Add Form -->
		<?php if ( current_user_can( 'remember_create_roles' ) ) : ?>
		<div id="remember-add-role" style="display:none; margin: 20px 0; padding: 20px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px;">
			<h2><?php esc_html_e( 'Add New Role', 'remember' ); ?></h2>
			<form method="post" action="">
				<?php wp_nonce_field( 'remember_role_action', 'remember_role_nonce' ); ?>
				<input type="hidden" name="remember_role_action" value="add">
				
				<table class="form-table">
					<tr>
						<th><label for="role_name"><?php esc_html_e( 'Role Name', 'remember' ); ?> <span class="description">(required)</span></label></th>
						<td><input type="text" id="role_name" name="role_name" class="regular-text" required></td>
					</tr>
					<tr>
						<th><label for="role_type"><?php esc_html_e( 'Role Type', 'remember' ); ?></label></th>
						<td>
							<select id="role_type" name="role_type" class="regular-text">
								<option value="event"><?php esc_html_e( 'Event Role', 'remember' ); ?></option>
								<option value="system"><?php esc_html_e( 'System Role', 'remember' ); ?></option>
							</select>
							<p class="description">
								<strong><?php esc_html_e( 'Event Roles:', 'remember' ); ?></strong> <?php esc_html_e( 'Roles that members can be assigned to for specific events (e.g., "Guard", "Inmate"). Members can have multiple event roles and can play different roles at different events.', 'remember' ); ?><br>
								<strong><?php esc_html_e( 'System Roles:', 'remember' ); ?></strong> <?php esc_html_e( 'Administrative roles for managing the system (e.g., "System Administrator", "Vetting Team Member"). System roles grant capabilities to manage the plugin.', 'remember' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th><label for="description"><?php esc_html_e( 'Description', 'remember' ); ?></label></th>
						<td><textarea id="description" name="description" class="large-text" rows="3"></textarea></td>
					</tr>
					<tr>
						<th><label for="show_in_frontend"><?php esc_html_e( 'Show in Front End', 'remember' ); ?></label></th>
						<td>
							<label>
								<input type="checkbox" id="show_in_frontend" name="show_in_frontend" value="1" checked>
								<?php esc_html_e( 'Show this role in the front-end application form', 'remember' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'If unchecked, this role will not appear as an option when members apply for events on the front end. It can still be assigned manually in the admin.', 'remember' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label><?php esc_html_e( 'Capabilities', 'remember' ); ?></label></th>
						<td>
							<p class="description"><?php esc_html_e( 'Select capabilities for this role. You can only grant capabilities you already hold.', 'remember' ); ?></p>
							<?php $remember_render_capability_matrix( array() ); ?>
						</td>
					</tr>
				</table>
				
				<p class="submit">
					<input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Add Role', 'remember' ); ?>">
					<button type="button" class="button" onclick="document.getElementById('remember-add-role').style.display='none'; document.querySelector('.page-title-action').style.display='inline-block';"><?php esc_html_e( 'Cancel', 'remember' ); ?></button>
				</p>
			</form>
		</div>
		<?php endif; ?>

		<!-- Event Roles -->
		<h2><?php esc_html_e( 'Event Roles', 'remember' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Event roles are assigned to members for specific events. Members can have multiple event roles and can play different roles at different events. Event roles typically do not have capabilities.', 'remember' ); ?></p>
		<?php if ( ! empty( $event_roles ) ) : ?>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th class="column-name"><?php esc_html_e( 'Role Name', 'remember' ); ?></th>
						<th class="column-description"><?php esc_html_e( 'Description', 'remember' ); ?></th>
						<th class="column-capabilities"><?php esc_html_e( 'Capabilities', 'remember' ); ?></th>
						<th class="column-actions"><?php esc_html_e( 'Actions', 'remember' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $event_roles as $role ) : 
						$role_capabilities = $role_model->get_capabilities( $role->role_id );
						// Get member count for this role
						global $wpdb;
						$member_count = $wpdb->get_var( $wpdb->prepare(
							"SELECT COUNT(*) FROM {$wpdb->prefix}remember_member_roles WHERE role_id = %d",
							$role->role_id
						) );
					?>
						<tr>
							<td class="column-name"><strong><?php echo esc_html( $role->role_name ); ?></strong></td>
							<td class="column-description"><?php echo esc_html( $role->description ); ?></td>
							<td class="column-capabilities">
								<?php if ( ! empty( $role_capabilities ) ) : 
									$cap_count = count( $role_capabilities );
									$cap_id = 'capabilities-' . $role->role_id;
								?>
									<a href="#" class="remember-toggle-capabilities" data-target="<?php echo esc_attr( $cap_id ); ?>" style="text-decoration: none;">
										<span class="dashicons dashicons-arrow-down-alt2" style="font-size: 16px; vertical-align: middle;"></span>
										<strong><?php echo esc_html( sprintf( _n( '%d capability', '%d capabilities', $cap_count, 'remember' ), $cap_count ) ); ?></strong>
									</a>
									<ul id="<?php echo esc_attr( $cap_id ); ?>" class="remember-capabilities-list" style="display: none; margin: 10px 0 0 20px; padding-left: 20px;">
										<?php foreach ( $role_capabilities as $cap ) : ?>
											<li><?php echo esc_html( Remember_Capabilities::get_capability_label( $cap ) ); ?></li>
										<?php endforeach; ?>
									</ul>
								<?php else : ?>
									<span class="description"><?php esc_html_e( 'No capabilities', 'remember' ); ?></span>
								<?php endif; ?>
							</td>
							<td class="column-actions">
								<?php if ( current_user_can( 'remember_update_roles' ) ) : ?>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=remember-roles&edit_capabilities=' . $role->role_id ) ); ?>"><?php esc_html_e( 'Edit Capabilities', 'remember' ); ?></a>
								<?php endif; ?>
								<?php if ( current_user_can( 'remember_update_roles' ) && current_user_can( 'remember_delete_roles' ) ) : ?>
									|
								<?php endif; ?>
								<?php if ( current_user_can( 'remember_delete_roles' ) ) : ?>
									<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=remember-roles&delete=' . $role->role_id ), 'remember_role_action', 'remember_role_nonce' ) ); ?>" onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to delete this role?', 'remember' ); ?>');"><?php esc_html_e( 'Delete', 'remember' ); ?></a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p><?php esc_html_e( 'No event roles found.', 'remember' ); ?></p>
		<?php endif; ?>

		<!-- System Roles -->
		<h2 style="margin-top: 30px;"><?php esc_html_e( 'System Roles', 'remember' ); ?></h2>
		<p class="description"><?php esc_html_e( 'System roles grant capabilities for managing the plugin. Members with system roles can perform administrative tasks. Members can have multiple system roles.', 'remember' ); ?></p>
		<?php if ( ! empty( $system_roles ) ) : ?>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th class="column-name"><?php esc_html_e( 'Role Name', 'remember' ); ?></th>
						<th class="column-description"><?php esc_html_e( 'Description', 'remember' ); ?></th>
						<th class="column-capabilities"><?php esc_html_e( 'Capabilities', 'remember' ); ?></th>
						<th class="column-actions"><?php esc_html_e( 'Actions', 'remember' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $system_roles as $role ) : 
						$role_capabilities = $role_model->get_capabilities( $role->role_id );
						// Get member count for this role
						global $wpdb;
						$member_count = $wpdb->get_var( $wpdb->prepare(
							"SELECT COUNT(*) FROM {$wpdb->prefix}remember_member_roles WHERE role_id = %d",
							$role->role_id
						) );
						$is_protected = Remember_Capabilities::is_protected_system_role( $role );
						$can_edit_role = current_user_can( 'remember_update_roles' )
							&& ( ! $is_protected || current_user_can( 'manage_options' ) );
					?>
						<tr>
							<td class="column-name"><strong><?php echo esc_html( $role->role_name ); ?></strong></td>
							<td class="column-description"><?php echo esc_html( $role->description ); ?></td>
							<td class="column-capabilities">
								<?php if ( ! empty( $role_capabilities ) ) : 
									$cap_count = count( $role_capabilities );
									$cap_id = 'capabilities-' . $role->role_id;
								?>
									<a href="#" class="remember-toggle-capabilities" data-target="<?php echo esc_attr( $cap_id ); ?>" style="text-decoration: none;">
										<span class="dashicons dashicons-arrow-down-alt2" style="font-size: 16px; vertical-align: middle;"></span>
										<strong><?php echo esc_html( sprintf( _n( '%d capability', '%d capabilities', $cap_count, 'remember' ), $cap_count ) ); ?></strong>
									</a>
									<ul id="<?php echo esc_attr( $cap_id ); ?>" class="remember-capabilities-list" style="display: none; margin: 10px 0 0 20px; padding-left: 20px;">
										<?php foreach ( $role_capabilities as $cap ) : ?>
											<li><?php echo esc_html( Remember_Capabilities::get_capability_label( $cap ) ); ?></li>
										<?php endforeach; ?>
									</ul>
								<?php else : ?>
									<span class="description"><?php esc_html_e( 'No capabilities', 'remember' ); ?></span>
								<?php endif; ?>
							</td>
							<td class="column-actions">
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=remember-members&filter_role=' . $role->role_id ) ); ?>">
									<?php echo esc_html( sprintf( _n( '%d member', '%d members', $member_count, 'remember' ), $member_count ) ); ?>
								</a>
								<?php if ( $can_edit_role ) : ?>
									|
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=remember-roles&edit_capabilities=' . $role->role_id ) ); ?>"><?php esc_html_e( 'Edit Capabilities', 'remember' ); ?></a>
								<?php endif; ?>
								<?php if ( current_user_can( 'remember_delete_roles' ) && ! $is_protected ) : ?>
									|
									<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=remember-roles&delete=' . $role->role_id ), 'remember_role_action', 'remember_role_nonce' ) ); ?>" onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to delete this role?', 'remember' ); ?>');"><?php esc_html_e( 'Delete', 'remember' ); ?></a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p><?php esc_html_e( 'No system roles found.', 'remember' ); ?></p>
		<?php endif; ?>
	<?php else : ?>
		<!-- Edit Role Form -->
		<div style="margin: 20px 0; padding: 20px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px;">
			<h2><?php echo esc_html( sprintf( __( 'Edit Role: %s', 'remember' ), $editing_role->role_name ) ); ?></h2>
			
			<form method="post" action="">
				<?php wp_nonce_field( 'remember_role_action', 'remember_role_nonce' ); ?>
				<input type="hidden" name="remember_role_action" value="update_role">
				<input type="hidden" name="role_id" value="<?php echo esc_attr( $editing_role_id ); ?>">
				
				<table class="form-table">
					<tr>
						<th><label for="role_name"><?php esc_html_e( 'Role Name', 'remember' ); ?> <span class="description">(required)</span></label></th>
						<td>
							<input type="text" id="role_name" name="role_name" class="regular-text" value="<?php echo esc_attr( $editing_role->role_name ); ?>" required<?php echo Remember_Capabilities::is_protected_system_role( $editing_role ) ? ' readonly' : ''; ?>>
						</td>
					</tr>
					<tr>
						<th><label for="role_type"><?php esc_html_e( 'Role Type', 'remember' ); ?></label></th>
						<td>
							<select id="role_type" name="role_type" class="regular-text">
								<option value="event" <?php selected( $editing_role->role_type, 'event' ); ?>><?php esc_html_e( 'Event Role', 'remember' ); ?></option>
								<option value="system" <?php selected( $editing_role->role_type, 'system' ); ?>><?php esc_html_e( 'System Role', 'remember' ); ?></option>
							</select>
							<p class="description">
								<strong><?php esc_html_e( 'Event Roles:', 'remember' ); ?></strong> <?php esc_html_e( 'Roles that members can be assigned to for specific events (e.g., "Guard", "Inmate"). Members can have multiple event roles and can play different roles at different events.', 'remember' ); ?><br>
								<strong><?php esc_html_e( 'System Roles:', 'remember' ); ?></strong> <?php esc_html_e( 'Administrative roles for managing the system (e.g., "System Administrator", "Vetting Team Member"). System roles grant capabilities to manage the plugin.', 'remember' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th><label for="description"><?php esc_html_e( 'Description', 'remember' ); ?></label></th>
						<td><textarea id="description" name="description" class="large-text" rows="3"><?php echo esc_textarea( $editing_role->description ? $editing_role->description : '' ); ?></textarea></td>
					</tr>
					<tr>
						<th><label for="show_in_frontend_edit"><?php esc_html_e( 'Show in Front End', 'remember' ); ?></label></th>
						<td>
							<label>
								<input type="checkbox" id="show_in_frontend_edit" name="show_in_frontend" value="1" <?php checked( isset( $editing_role->show_in_frontend ) ? $editing_role->show_in_frontend : 1, 1 ); ?>>
								<?php esc_html_e( 'Show this role in the front-end application form', 'remember' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'If unchecked, this role will not appear as an option when members apply for events on the front end. It can still be assigned manually in the admin.', 'remember' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label><?php esc_html_e( 'Capabilities', 'remember' ); ?></label></th>
						<td>
							<p class="description"><?php esc_html_e( 'Select the capabilities this role should have. You can only change capabilities you already hold; others stay as they are.', 'remember' ); ?></p>
							<?php $remember_render_capability_matrix( $editing_role_capabilities ); ?>
						</td>
					</tr>
				</table>
				
				<p class="submit">
					<input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Update Role', 'remember' ); ?>">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=remember-roles' ) ); ?>" class="button"><?php esc_html_e( 'Cancel', 'remember' ); ?></a>
				</p>
			</form>
		</div>
	<?php endif; ?>
</div>

<script>
jQuery(document).ready(function($) {
	$('.remember-toggle-capabilities').on('click', function(e) {
		e.preventDefault();
		var $link = $(this);
		var $target = $('#' + $link.data('target'));
		var $icon = $link.find('.dashicons');
		
		if ($target.is(':visible')) {
			$target.slideUp();
			$icon.removeClass('dashicons-arrow-up-alt2').addClass('dashicons-arrow-down-alt2');
		} else {
			$target.slideDown();
			$icon.removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-up-alt2');
		}
	});
});
</script>
