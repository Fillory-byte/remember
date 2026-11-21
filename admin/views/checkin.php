<?php
/**
 * Door check-in screen.
 *
 * @package    reMember
 * @subpackage reMember/admin/views
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

require_once plugin_dir_path( __FILE__ ) . '../../includes/utilities/class-remember-checkin.php';

$events   = Remember_Checkin::enabled_events();
$event_id = isset( $_REQUEST['event_id'] ) ? absint( $_REQUEST['event_id'] ) : 0;
$allowed  = array();
foreach ( $events as $event ) {
	$allowed[ (int) $event->event_id ] = $event;
}
if ( ! isset( $allowed[ $event_id ] ) ) {
	$event_id = $events ? (int) $events[0]->event_id : 0;
}

$result  = null;
$matches = array();
$notice  = '';

if ( isset( $_POST['remember_checkin_action'] ) && check_admin_referer( 'remember_checkin', 'remember_checkin_nonce' ) ) {
	$action = sanitize_key( wp_unslash( $_POST['remember_checkin_action'] ) );
	if ( ! $event_id ) {
		$notice = __( 'Turn on door check-in for an event before using this screen.', 'remember' );
	} elseif ( 'code' === $action ) {
		$code   = isset( $_POST['checkin_code'] ) ? sanitize_text_field( wp_unslash( $_POST['checkin_code'] ) ) : '';
		$result = Remember_Checkin::check_in( $code, $event_id );
	} elseif ( 'application' === $action ) {
		$result = Remember_Checkin::check_in_application( isset( $_POST['application_id'] ) ? absint( $_POST['application_id'] ) : 0, $event_id );
	} elseif ( 'undo' === $action ) {
		$result = Remember_Checkin::undo( isset( $_POST['application_id'] ) ? absint( $_POST['application_id'] ) : 0, $event_id );
	} elseif ( 'search' === $action ) {
		$term    = isset( $_POST['checkin_search'] ) ? sanitize_text_field( wp_unslash( $_POST['checkin_search'] ) ) : '';
		$matches = Remember_Checkin::search( $event_id, $term );
		if ( ! $matches ) {
			$notice = __( 'No accepted attendee matched that name or email.', 'remember' );
		}
	}
}

$counts = $event_id ? Remember_Checkin::counts( $event_id ) : array( 'checked' => 0, 'accepted' => 0 );

if ( ! function_exists( 'remember_checkin_result_title' ) ) {
/**
 * Heading for a check-in result.
 *
 * @param string $code Result code.
 * @return string
 */
function remember_checkin_result_title( $code ) {
	$titles = array(
		'checked_in'  => __( 'Checked in', 'remember' ),
		'undone'      => __( 'Check-in removed', 'remember' ),
		'already'     => __( 'Already checked in', 'remember' ),
		'void'        => __( 'Void ticket', 'remember' ),
		'event_cancelled' => __( 'Event cancelled', 'remember' ),
		'not_accepted'=> __( 'Not accepted', 'remember' ),
		'wrong_event' => __( 'Wrong event', 'remember' ),
		'disabled'    => __( 'Check-in is off', 'remember' ),
		'invalid'     => __( 'Code not recognized', 'remember' ),
	);
	return isset( $titles[ $code ] ) ? $titles[ $code ] : __( 'Check-in', 'remember' );
}
}
?>
<div class="wrap remember-checkin">
	<h1><?php esc_html_e( 'Check-in', 'remember' ); ?></h1>
	<style>
		.remember-checkin-count { font-size: 28px; margin: 12px 0; }
		.remember-checkin input[type="text"], .remember-checkin input[type="search"] { font-size: 20px; width: 100%; max-width: 420px; }
		.remember-checkin-result { max-width: 520px; padding: 16px 18px; margin: 16px 0; border-left: 6px solid #646970; background: #fff; }
		.remember-checkin-result--ok { border-color: #1b5e20; }
		.remember-checkin-result--warn { border-color: #9a6700; }
		.remember-checkin-result--bad { border-color: #8a2424; }
		.remember-checkin-result h2 { margin: 0 0 8px; }
		.remember-checkin video { width: 100%; max-width: 420px; background: #111; }
		.remember-checkin-matches { max-width: 520px; }
		.remember-checkin-matches form { margin: 8px 0; }
	</style>

	<?php if ( ! $events ) : ?>
		<p><?php esc_html_e( 'No event has door check-in turned on. The admission ticket stays as it is until that box is checked on the event.', 'remember' ); ?></p>
	<?php else : ?>
		<form method="get">
			<input type="hidden" name="page" value="remember-checkin">
			<label for="remember-checkin-event"><strong><?php esc_html_e( 'Event', 'remember' ); ?></strong></label>
			<select id="remember-checkin-event" name="event_id" onchange="this.form.submit()">
				<?php foreach ( $events as $event ) : ?>
					<option value="<?php echo esc_attr( (string) $event->event_id ); ?>" <?php selected( $event_id, (int) $event->event_id ); ?>><?php echo esc_html( $event->event_name ); ?></option>
				<?php endforeach; ?>
			</select>
		</form>
		<p class="remember-checkin-count">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: checked-in count, 2: accepted count */
					__( 'Checked in %1$d / %2$d', 'remember' ),
					(int) $counts['checked'],
					(int) $counts['accepted']
				)
			);
			?>
		</p>

		<?php if ( $notice ) : ?>
			<div class="notice notice-warning"><p><?php echo esc_html( $notice ); ?></p></div>
		<?php endif; ?>

		<?php if ( $result ) : ?>
			<?php
			$tone = 'bad';
			if ( in_array( $result['result'], array( 'checked_in', 'undone' ), true ) ) {
				$tone = 'ok';
			} elseif ( 'already' === $result['result'] ) {
				$tone = 'warn';
			}
			$label = ! empty( $result['row'] ) ? Remember_Checkin::label( $result['row'] ) : null;
			?>
			<div class="remember-checkin-result remember-checkin-result--<?php echo esc_attr( $tone ); ?>">
				<h2><?php echo esc_html( remember_checkin_result_title( $result['result'] ) ); ?></h2>
				<?php if ( $label ) : ?>
					<p><strong><?php echo esc_html( $label['name'] ); ?></strong><?php echo $label['role'] ? ' · ' . esc_html( $label['role'] ) : ''; ?></p>
				<?php endif; ?>
				<?php if ( 'already' === $result['result'] && $label ) : ?>
					<p>
						<?php
						echo esc_html(
							$label['by']
								? sprintf(
									/* translators: 1: check-in time, 2: staff name */
									__( 'Checked in %1$s by %2$s.', 'remember' ),
									$label['when'],
									$label['by']
								)
								: sprintf(
									/* translators: %s: check-in time */
									__( 'Checked in %s.', 'remember' ),
									$label['when']
								)
						);
						?>
					</p>
				<?php elseif ( 'void' === $result['result'] ) : ?>
					<p><?php esc_html_e( 'This ticket was voided. Do not admit on this ticket.', 'remember' ); ?></p>
				<?php elseif ( 'event_cancelled' === $result['result'] ) : ?>
					<p><?php esc_html_e( 'This event is cancelled. Do not admit on this ticket.', 'remember' ); ?></p>
				<?php elseif ( 'not_accepted' === $result['result'] ) : ?>
					<p><?php esc_html_e( 'This application is not an accepted ticket.', 'remember' ); ?></p>
				<?php elseif ( 'wrong_event' === $result['result'] ) : ?>
					<p><?php esc_html_e( 'This ticket belongs to a different event.', 'remember' ); ?></p>
				<?php elseif ( 'invalid' === $result['result'] ) : ?>
					<p><?php esc_html_e( 'That code does not match a ticket.', 'remember' ); ?></p>
				<?php endif; ?>
				<?php if ( ! empty( $result['row'] ) && in_array( $result['result'], array( 'checked_in', 'already' ), true ) ) : ?>
					<form method="post">
						<?php wp_nonce_field( 'remember_checkin', 'remember_checkin_nonce' ); ?>
						<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $event_id ); ?>">
						<input type="hidden" name="application_id" value="<?php echo esc_attr( (string) $result['row']->application_id ); ?>">
						<button type="submit" class="button" name="remember_checkin_action" value="undo"><?php esc_html_e( 'Undo check-in', 'remember' ); ?></button>
					</form>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Ticket code', 'remember' ); ?></h2>
		<form method="post">
			<?php wp_nonce_field( 'remember_checkin', 'remember_checkin_nonce' ); ?>
			<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $event_id ); ?>">
			<p>
				<label for="remember-checkin-code" class="screen-reader-text"><?php esc_html_e( 'Ticket code', 'remember' ); ?></label>
				<input type="text" id="remember-checkin-code" name="checkin_code" autocomplete="off" inputmode="text">
			</p>
			<p><button type="submit" class="button button-primary" name="remember_checkin_action" value="code"><?php esc_html_e( 'Check in', 'remember' ); ?></button></p>
		</form>

		<h2><?php esc_html_e( 'Camera', 'remember' ); ?></h2>
		<p id="remember-checkin-camera-note"><?php esc_html_e( 'Use the camera where this browser can read a code. Otherwise type the code printed under the mark on the ticket.', 'remember' ); ?></p>
		<p><button type="button" class="button" id="remember-checkin-camera"><?php esc_html_e( 'Start camera', 'remember' ); ?></button></p>
		<video id="remember-checkin-video" hidden playsinline></video>

		<h2><?php esc_html_e( 'Find a name', 'remember' ); ?></h2>
		<form method="post">
			<?php wp_nonce_field( 'remember_checkin', 'remember_checkin_nonce' ); ?>
			<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $event_id ); ?>">
			<p>
				<label for="remember-checkin-search" class="screen-reader-text"><?php esc_html_e( 'Name or email', 'remember' ); ?></label>
				<input type="search" id="remember-checkin-search" name="checkin_search" value="<?php echo isset( $_POST['checkin_search'] ) ? esc_attr( sanitize_text_field( wp_unslash( $_POST['checkin_search'] ) ) ) : ''; ?>">
			</p>
			<p><button type="submit" class="button" name="remember_checkin_action" value="search"><?php esc_html_e( 'Search', 'remember' ); ?></button></p>
		</form>
		<div class="remember-checkin-matches">
			<?php foreach ( $matches as $match ) : ?>
				<form method="post">
					<?php wp_nonce_field( 'remember_checkin', 'remember_checkin_nonce' ); ?>
					<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $event_id ); ?>">
					<input type="hidden" name="application_id" value="<?php echo esc_attr( (string) $match->application_id ); ?>">
					<strong><?php echo esc_html( $match->display_name ); ?></strong>
					<?php echo $match->role_name ? ' · ' . esc_html( $match->role_name ) : ''; ?>
					<?php if ( ! empty( $match->checked_in_at ) ) : ?>
						<em><?php esc_html_e( 'Already checked in', 'remember' ); ?></em>
					<?php endif; ?>
					<button type="submit" class="button" name="remember_checkin_action" value="application"><?php esc_html_e( 'Check in', 'remember' ); ?></button>
				</form>
			<?php endforeach; ?>
		</div>
		<script>
			(function () {
				var button = document.getElementById('remember-checkin-camera');
				var video = document.getElementById('remember-checkin-video');
				var input = document.getElementById('remember-checkin-code');
				var note = document.getElementById('remember-checkin-camera-note');
				if (!button || !video || !input) return;
				if (!('BarcodeDetector' in window) || !navigator.mediaDevices) {
					button.hidden = true;
					if (note) note.textContent = 'This browser cannot scan a code. Type the code printed under the mark on the ticket.';
					return;
				}
				button.addEventListener('click', function () {
					var detector = new BarcodeDetector({ formats: ['qr_code'] });
					navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } }).then(function (stream) {
						video.hidden = false;
						video.srcObject = stream;
						return video.play();
					}).then(function () {
						var timer = window.setInterval(function () {
							detector.detect(video).then(function (codes) {
								if (!codes.length || !codes[0].rawValue) return;
								window.clearInterval(timer);
								input.value = codes[0].rawValue;
								if (video.srcObject) {
									video.srcObject.getTracks().forEach(function (track) { track.stop(); });
								}
								input.form.submit();
							}).catch(function () {});
						}, 400);
					}).catch(function () {
						if (note) note.textContent = 'The camera did not start. Type the code printed under the mark on the ticket.';
					});
				});
			})();
		</script>
	<?php endif; ?>
</div>
