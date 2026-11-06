<?php
/**
 * Agreements library admin (versioned rules / waivers).
 *
 * @package    reMember
 * @subpackage reMember/admin/views
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-agreements.php';

$editing_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
$editing    = $editing_id > 0 ? Remember_Agreements::get_agreement( $editing_id ) : null;
$revisions  = $editing ? Remember_Agreements::get_revisions_for_agreement( $editing_id ) : array();
$view_rev   = isset( $_GET['revision'] ) ? absint( $_GET['revision'] ) : 0;
$viewing    = $view_rev > 0 ? Remember_Agreements::get_revision( $view_rev ) : null;

if ( isset( $_GET['delete'] ) && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'remember_agreement_delete_' . absint( $_GET['delete'] ) ) ) {
	if ( ! current_user_can( 'remember_access_settings' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to perform this action.', 'remember' ) );
	}
	$result = Remember_Agreements::delete_agreement( absint( $_GET['delete'] ) );
	if ( true === $result ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Agreement deleted.', 'remember' ) . '</p></div>';
	} else {
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $result ) . '</p></div>';
	}
}

if ( isset( $_POST['remember_agreement_action'] ) && check_admin_referer( 'remember_agreement_action', 'remember_agreement_nonce' ) ) {
	if ( ! current_user_can( 'remember_access_settings' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to perform this action.', 'remember' ) );
	}
	$action = sanitize_text_field( wp_unslash( $_POST['remember_agreement_action'] ) );
	$title  = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
	$body   = isset( $_POST['body'] ) ? wp_kses_post( wp_unslash( $_POST['body'] ) ) : '';
	$sort   = isset( $_POST['sort_order'] ) ? absint( $_POST['sort_order'] ) : 0;
	$active = ! empty( $_POST['is_active'] ) ? 1 : 0;

	if ( 'add' === $action ) {
		$new_id = Remember_Agreements::create_agreement( $title, $body, $sort, $active );
		if ( $new_id ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Agreement created (revision 1).', 'remember' ) . '</p></div>';
		} else {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Could not create agreement. Title and body are required.', 'remember' ) . '</p></div>';
		}
	} elseif ( 'update_meta' === $action && isset( $_POST['agreement_id'] ) ) {
		$aid = absint( $_POST['agreement_id'] );
		if ( Remember_Agreements::update_agreement_meta( $aid, $title, $sort, $active ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Agreement details saved.', 'remember' ) . '</p></div>';
			$editing = Remember_Agreements::get_agreement( $aid );
		} else {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Failed to save agreement details.', 'remember' ) . '</p></div>';
		}
	} elseif ( 'add_revision' === $action && isset( $_POST['agreement_id'] ) ) {
		$aid = absint( $_POST['agreement_id'] );
		$rid = Remember_Agreements::add_revision( $aid, $body );
		if ( $rid ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'New revision published. Events still using older revisions are unchanged.', 'remember' ) . '</p></div>';
			$editing   = Remember_Agreements::get_agreement( $aid );
			$revisions = Remember_Agreements::get_revisions_for_agreement( $aid );
			$editing_id = $aid;
		} else {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Could not publish revision. Body is required.', 'remember' ) . '</p></div>';
		}
	}
}

$agreements = Remember_Agreements::get_all_agreements();
$latest_body = '';
if ( $editing && ! empty( $revisions ) ) {
	$latest_body = (string) $revisions[0]->body;
}
?>
<div class="wrap remember-agreements">
	<h1><?php esc_html_e( 'Agreements', 'remember' ); ?></h1>
	<p>
		<?php esc_html_e( 'Library of versioned agreements (event rules, waivers, and similar). Attach specific revisions to events; applicants must acknowledge each one when they apply.', 'remember' ); ?>
	</p>

	<?php if ( $viewing && $editing && (int) $viewing->agreement_id === (int) $editing->agreement_id ) : ?>
		<div class="postbox" style="max-width:800px;margin:1em 0;">
			<div class="postbox-header"><h2 class="hndle" style="padding:8px 12px;margin:0;">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: agreement title, 2: revision number */
						__( '%1$s — Revision %2$d', 'remember' ),
						$viewing->agreement_title,
						(int) $viewing->revision_number
					)
				);
				?>
			</h2></div>
			<div class="inside" style="padding:12px 16px;">
				<div class="remember-richtext"><?php echo wp_kses_post( wpautop( $viewing->body ) ); ?></div>
				<p>
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=remember-agreements&edit=' . (int) $editing->agreement_id ) ); ?>"><?php esc_html_e( 'Back to agreement', 'remember' ); ?></a>
				</p>
			</div>
		</div>
	<?php elseif ( $editing ) : ?>
		<h2><?php echo esc_html( $editing->title ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=remember-agreements&edit=' . (int) $editing->agreement_id ) ); ?>">
			<?php wp_nonce_field( 'remember_agreement_action', 'remember_agreement_nonce' ); ?>
			<input type="hidden" name="remember_agreement_action" value="update_meta">
			<input type="hidden" name="agreement_id" value="<?php echo esc_attr( (string) $editing->agreement_id ); ?>">
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="title"><?php esc_html_e( 'Title', 'remember' ); ?></label></th>
					<td><input type="text" class="regular-text" name="title" id="title" value="<?php echo esc_attr( $editing->title ); ?>" required></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Active', 'remember' ); ?></th>
					<td><label><input type="checkbox" name="is_active" value="1" <?php checked( ! empty( $editing->is_active ) ); ?>> <?php esc_html_e( 'Available to attach to events', 'remember' ); ?></label></td>
				</tr>
				<tr>
					<th><label for="sort_order"><?php esc_html_e( 'Order', 'remember' ); ?></label></th>
					<td><input type="number" class="small-text" name="sort_order" id="sort_order" value="<?php echo esc_attr( (string) $editing->sort_order ); ?>"></td>
				</tr>
			</table>
			<?php submit_button( __( 'Save details', 'remember' ), 'secondary', 'submit', false ); ?>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=remember-agreements' ) ); ?>"><?php esc_html_e( 'Back to list', 'remember' ); ?></a>
		</form>

		<hr>
		<h3><?php esc_html_e( 'Revisions', 'remember' ); ?></h3>
		<p class="description"><?php esc_html_e( 'Past revisions are immutable. Publishing a new revision does not change events still pinned to an older one.', 'remember' ); ?></p>
		<?php if ( ! empty( $revisions ) ) : ?>
			<table class="widefat striped" style="max-width:640px;margin:1em 0;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Revision', 'remember' ); ?></th>
						<th><?php esc_html_e( 'Published', 'remember' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'remember' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $revisions as $rev ) : ?>
						<tr>
							<td><strong><?php echo esc_html( (string) $rev->revision_number ); ?></strong></td>
							<td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $rev->created_at ) ) ); ?></td>
							<td>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=remember-agreements&edit=' . (int) $editing->agreement_id . '&revision=' . (int) $rev->revision_id ) ); ?>"><?php esc_html_e( 'View', 'remember' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<h3><?php esc_html_e( 'Publish new revision', 'remember' ); ?></h3>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=remember-agreements&edit=' . (int) $editing->agreement_id ) ); ?>">
			<?php wp_nonce_field( 'remember_agreement_action', 'remember_agreement_nonce' ); ?>
			<input type="hidden" name="remember_agreement_action" value="add_revision">
			<input type="hidden" name="agreement_id" value="<?php echo esc_attr( (string) $editing->agreement_id ); ?>">
			<?php
			wp_editor(
				$latest_body,
				'agreement_body_revision',
				array(
					'textarea_name' => 'body',
					'textarea_rows' => 12,
					'media_buttons' => false,
					'teeny'         => false,
					'quicktags'     => false,
				)
			);
			?>
			<?php submit_button( __( 'Publish new revision', 'remember' ) ); ?>
		</form>
	<?php else : ?>
		<div class="postbox" style="max-width:800px;margin-top:1em;">
			<div class="postbox-header"><h2 class="hndle" style="padding:8px 12px;margin:0;"><?php esc_html_e( 'Add agreement', 'remember' ); ?></h2></div>
			<div class="inside" style="margin:0;padding:12px 16px 16px;">
				<form method="post" action="">
					<?php wp_nonce_field( 'remember_agreement_action', 'remember_agreement_nonce' ); ?>
					<input type="hidden" name="remember_agreement_action" value="add">
					<table class="form-table" role="presentation">
						<tr>
							<th><label for="title"><?php esc_html_e( 'Title', 'remember' ); ?></label></th>
							<td>
								<input type="text" class="regular-text" name="title" id="title" required placeholder="<?php esc_attr_e( 'Event Rules', 'remember' ); ?>">
							</td>
						</tr>
						<tr>
							<th><label for="agreement_body_new"><?php esc_html_e( 'Body', 'remember' ); ?></label></th>
							<td>
								<?php
								wp_editor(
									'',
									'agreement_body_new',
									array(
										'textarea_name' => 'body',
										'textarea_rows' => 10,
										'media_buttons' => false,
										'quicktags'     => false,
									)
								);
								?>
								<p class="description"><?php esc_html_e( 'Saved as revision 1. Later edits publish new revisions.', 'remember' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Active', 'remember' ); ?></th>
							<td><label><input type="checkbox" name="is_active" value="1" checked> <?php esc_html_e( 'Available to attach to events', 'remember' ); ?></label></td>
						</tr>
						<tr>
							<th><label for="sort_order"><?php esc_html_e( 'Order', 'remember' ); ?></label></th>
							<td><input type="number" class="small-text" name="sort_order" id="sort_order" value="0"></td>
						</tr>
					</table>
					<?php submit_button( __( 'Add agreement', 'remember' ), 'primary', 'submit', false ); ?>
				</form>
			</div>
		</div>

		<hr>
		<h2><?php esc_html_e( 'Library', 'remember' ); ?></h2>
		<?php if ( empty( $agreements ) ) : ?>
			<p><?php esc_html_e( 'No agreements yet. Add one above.', 'remember' ); ?></p>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped" style="max-width:960px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Title', 'remember' ); ?></th>
						<th><?php esc_html_e( 'Latest revision', 'remember' ); ?></th>
						<th><?php esc_html_e( 'Active', 'remember' ); ?></th>
						<th><?php esc_html_e( 'Order', 'remember' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'remember' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $agreements as $a ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $a->title ); ?></strong></td>
							<td><?php echo $a->latest_revision ? esc_html( (string) $a->latest_revision ) : '—'; ?></td>
							<td><?php echo ! empty( $a->is_active ) ? esc_html__( 'Yes', 'remember' ) : esc_html__( 'No', 'remember' ); ?></td>
							<td><?php echo esc_html( (string) $a->sort_order ); ?></td>
							<td>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=remember-agreements&edit=' . (int) $a->agreement_id ) ); ?>"><?php esc_html_e( 'Edit', 'remember' ); ?></a>
								|
								<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=remember-agreements&delete=' . (int) $a->agreement_id ), 'remember_agreement_delete_' . (int) $a->agreement_id ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this agreement and all unused revisions?', 'remember' ) ); ?>');" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: agreement name */ __( 'Delete %s', 'remember' ), $a->title ) ); ?>"><?php esc_html_e( 'Delete', 'remember' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	<?php endif; ?>
</div>
