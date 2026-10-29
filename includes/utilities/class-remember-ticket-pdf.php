<?php
/**
 * Admission ticket PDF download.
 *
 * The on-screen ticket stays HTML. Download sends this file so the copy a
 * member keeps is not a page they can edit in a text editor.
 *
 * Check-in codes are drawn with Kazuhiko Arase's MIT-licensed encoder in
 * vendor/qrcode.php.
 *
 * @package    reMember
 * @subpackage reMember/includes/utilities
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Builds a one- or two-page admission ticket PDF from ticket data.
 */
class Remember_Ticket_Pdf {

	/**
	 * Page width in points.
	 *
	 * @var float
	 */
	private $page_width = 612;

	/**
	 * Page height in points.
	 *
	 * @var float
	 */
	private $page_height = 792;

	/**
	 * Content inset.
	 *
	 * @var float
	 */
	private $margin = 48;

	/**
	 * Current content stream.
	 *
	 * @var string
	 */
	private $ops = '';

	/**
	 * Finished page streams.
	 *
	 * @var array
	 */
	private $pages = array();

	/**
	 * Distance from the top of the page to the next free line.
	 *
	 * @var float
	 */
	private $y = 48;

	/**
	 * Ticket id for the continued-page header.
	 *
	 * @var string
	 */
	private $ticket_id = '';

	/**
	 * void, payment_required, or empty.
	 *
	 * @var string
	 */
	private $stamp = '';

	/**
	 * Stamp wording, empty when the ticket is clear.
	 *
	 * @var string
	 */
	private $stamp_label = '';

	/**
	 * JPEG bytes for the logo, empty when there is none.
	 *
	 * @var string
	 */
	private $jpeg = '';

	/**
	 * Logo pixel width.
	 *
	 * @var int
	 */
	private $image_px_w = 0;

	/**
	 * Logo pixel height.
	 *
	 * @var int
	 */
	private $image_px_h = 0;

	/**
	 * Drawn logo width.
	 *
	 * @var float
	 */
	private $logo_w = 0;

	/**
	 * Drawn logo height.
	 *
	 * @var float
	 */
	private $logo_h = 0;

	/**
	 * Whether a logo was embedded.
	 *
	 * @var bool
	 */
	private $has_logo = false;

	/**
	 * Body text color.
	 *
	 * @var array
	 */
	private $ink = array( 0.102, 0.102, 0.102 );

	/**
	 * Secondary text color.
	 *
	 * @var array
	 */
	private $muted = array( 0.333, 0.333, 0.333 );

	/**
	 * Send the PDF and return. The caller exits.
	 *
	 * @param array $data Ticket data from Remember_Ticket::get_data().
	 * @return void
	 */
	public static function output( $data ) {
		$pdf      = self::render( $data );
		$filename = 'ticket-' . sanitize_file_name( isset( $data['ticket_id'] ) ? $data['ticket_id'] : 'ticket' ) . '.pdf';

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary PDF, not HTML.
		echo $pdf;
	}

	/**
	 * PDF bytes.
	 *
	 * @param array $data Ticket data.
	 * @return string
	 */
	public static function render( $data ) {
		$builder = new self();
		return $builder->build( $data );
	}

	/**
	 * Lay out the ticket and return the file bytes.
	 *
	 * @param array $data Ticket data.
	 * @return string
	 */
	private function build( $data ) {
		$this->ticket_id = isset( $data['ticket_id'] ) ? (string) $data['ticket_id'] : '';
		$this->stamp     = isset( $data['stamp'] ) ? (string) $data['stamp'] : '';
		if ( 'void' === $this->stamp ) {
			$this->stamp_label = __( 'VOID', 'remember' );
		} elseif ( 'payment_required' === $this->stamp ) {
			$this->stamp_label = __( 'PAYMENT REQUIRED', 'remember' );
		}

		$this->has_logo = $this->prepare_logo();
		$this->start_page( false );
		$this->draw_header( $data );
		$this->draw_attendee( $data );
		$this->draw_event( $data );
		if ( ! empty( $data['checkin_code'] ) ) {
			$this->draw_checkin( (string) $data['checkin_code'] );
		}
		$this->draw_receipt( $data );
		$this->draw_footer( $data );
		return $this->document( $data );
	}

	/**
	 * Vendor block, ticket id, and the rule under them.
	 *
	 * @param array $data Ticket data.
	 * @return void
	 */
	private function draw_header( $data ) {
		$top    = $this->y;
		$text_x = $this->margin;
		if ( $this->has_logo ) {
			$this->draw_logo( $this->margin, $top );
			$text_x = $this->margin + $this->logo_w + 14;
		}

		$id_width = max(
			$this->width( $this->ticket_id, 14, 2 ),
			$this->width( __( 'Ticket', 'remember' ), 9, 1 )
		);
		$text_max = $this->page_width - $this->margin - $id_width - 16 - $text_x;
		if ( $text_max < 120 ) {
			$text_max = 120;
		}

		$this->draw_text( $text_x, $top + 9, $this->upper( __( 'Vendor information', 'remember' ) ), 8, 2, $this->muted, 0.6 );

		$name_lines = $this->wrap( isset( $data['vendor_name'] ) ? (string) $data['vendor_name'] : '', 18, 3, $text_max );
		if ( ! $name_lines ) {
			$name_lines = array( '' );
		}
		$name_y = $top + 28;
		foreach ( $name_lines as $index => $line ) {
			$this->draw_text( $text_x, $name_y + ( $index * 20 ), $line, 18, 3, $this->ink );
		}

		$url = isset( $data['vendor_url'] ) ? (string) $data['vendor_url'] : '';
		$url = preg_replace( '#^https?://#', '', $url );
		$url = is_string( $url ) ? rtrim( $url, '/' ) : '';
		$url_y = $name_y + ( ( count( $name_lines ) - 1 ) * 20 ) + 16;
		if ( '' !== $url ) {
			$this->draw_text( $text_x, $url_y, $url, 9, 1, $this->muted );
		}

		$this->draw_text_right( $this->page_width - $this->margin, $top + 12, __( 'Ticket', 'remember' ), 9, 1, $this->muted );
		$this->draw_text_right( $this->page_width - $this->margin, $top + 30, $this->ticket_id, 14, 2, $this->ink );

		$block_bottom = max( $top + ( $this->has_logo ? $this->logo_h : 0 ), '' !== $url ? $url_y + 4 : $name_y + 4 );
		$this->y      = $block_bottom + 10;
		$this->hline( $this->margin, $this->page_width - $this->margin, $this->y, $this->ink, 1.25 );
		$this->y += 8;
	}

	/**
	 * Attendee name, email, and role.
	 *
	 * @param array $data Ticket data.
	 * @return void
	 */
	private function draw_attendee( $data ) {
		$this->heading( __( 'Attendee', 'remember' ) );
		$this->text_line( isset( $data['member_name'] ) ? (string) $data['member_name'] : '', 16, 3, $this->ink );
		if ( ! empty( $data['member_email'] ) ) {
			$this->text_line( (string) $data['member_email'], 11, 1, $this->ink );
		}
		if ( ! empty( $data['role_name'] ) ) {
			$this->labeled( __( 'Role:', 'remember' ), (string) $data['role_name'] );
		}
	}

	/**
	 * Event, dates, location, and statuses.
	 *
	 * @param array $data Ticket data.
	 * @return void
	 */
	private function draw_event( $data ) {
		$this->heading( __( 'Event', 'remember' ) );
		$this->text_line( isset( $data['event_name'] ) ? (string) $data['event_name'] : '', 16, 3, $this->ink );
		if ( ! empty( $data['event_dates'] ) ) {
			$this->labeled( __( 'Dates:', 'remember' ), (string) $data['event_dates'] );
		}
		if ( ! empty( $data['location_lines'] ) && is_array( $data['location_lines'] ) ) {
			$lines = array_values( $data['location_lines'] );
			$this->labeled( __( 'Location:', 'remember' ), (string) $lines[0] );
			$indent = $this->margin + $this->width( __( 'Location:', 'remember' ), 11, 2 ) + 6;
			$count  = count( $lines );
			for ( $i = 1; $i < $count; $i++ ) {
				$this->text_line( (string) $lines[ $i ], 11, 1, $this->ink, $indent );
			}
		}
		$this->labeled(
			__( 'Application status:', 'remember' ),
			Remember_Ticket_Renderer::application_status_label( isset( $data['application_status'] ) ? (string) $data['application_status'] : '' )
		);

		$pay = Remember_Ticket_Renderer::payment_status_label( isset( $data['payment_status'] ) ? (string) $data['payment_status'] : '' );
		if ( 'payment_required' === $this->stamp && isset( $data['amount_due'] ) && (float) $data['amount_due'] > 0 ) {
			$pay .= ' — ' . sprintf(
				/* translators: %s: amount due */
				__( '%s due', 'remember' ),
				Remember_Ticket_Renderer::money( $data['amount_due'] )
			);
		}
		$this->labeled(
			__( 'Payment status:', 'remember' ),
			$pay,
			$this->pay_color( isset( $data['payment_status'] ) ? (string) $data['payment_status'] : '' )
		);
	}

	/**
	 * Check-in mark and the code printed under it.
	 *
	 * @param string $code Check-in code.
	 * @return void
	 */
	private function draw_checkin( $code ) {
		$this->heading( __( 'Check-in', 'remember' ) );
		require_once plugin_dir_path( __FILE__ ) . 'vendor/qrcode.php';
		$qr    = QRCode::getMinimumQRCode( $code, QR_ERROR_CORRECT_LEVEL_M );
		$count = (int) $qr->getModuleCount();
		if ( $count <= 0 ) {
			$this->text_line( $code, 11, 5, $this->ink );
			return;
		}

		$module = 120 / $count;
		$size   = $module * $count;
		$this->need( $size + 28 );
		$origin_x = $this->margin;
		$origin_y = $this->y + 2;
		for ( $row = 0; $row < $count; $row++ ) {
			for ( $col = 0; $col < $count; $col++ ) {
				if ( ! $qr->isDark( $row, $col ) ) {
					continue;
				}
				$this->fill_rect(
					$origin_x + ( $col * $module ),
					$origin_y + ( $row * $module ),
					$module + 0.2,
					$module + 0.2,
					array( 0, 0, 0 )
				);
			}
		}
		$this->y = $origin_y + $size + 8;
		$this->text_line( $code, 11, 5, $this->ink );
	}

	/**
	 * Line items, discount, and the amount due.
	 *
	 * @param array $data Ticket data.
	 * @return void
	 */
	private function draw_receipt( $data ) {
		$this->heading( __( 'Receipt', 'remember' ) );
		if ( ! empty( $data['invoice_number'] ) ) {
			$this->labeled( __( 'Invoice #:', 'remember' ), (string) $data['invoice_number'] );
		}

		$total_right = $this->page_width - $this->margin;
		$unit_right  = $total_right - 74;
		$qty_right   = $unit_right - 70;
		$item_w      = $qty_right - 36 - $this->margin;

		$this->y += 4;
		$this->need( 18 );
		$baseline = $this->y + 11;
		$this->draw_text( $this->margin, $baseline, __( 'Item', 'remember' ), 9, 2, $this->muted );
		$this->draw_text_right( $qty_right, $baseline, __( 'Qty', 'remember' ), 9, 2, $this->muted );
		$this->draw_text_right( $unit_right, $baseline, __( 'Unit', 'remember' ), 9, 2, $this->muted );
		$this->draw_text_right( $total_right, $baseline, __( 'Total', 'remember' ), 9, 2, $this->muted );
		$this->y += 16;
		$this->hline( $this->margin, $total_right, $this->y, array( 0.8, 0.8, 0.8 ), 0.4 );

		$items = ( isset( $data['line_items'] ) && is_array( $data['line_items'] ) ) ? $data['line_items'] : array();
		if ( ! $items ) {
			$this->need( 18 );
			$this->draw_text( $this->margin, $this->y + 14, __( 'No line items.', 'remember' ), 10, 1, $this->ink );
			$this->y += 20;
		} else {
			foreach ( $items as $item ) {
				$this->receipt_row(
					isset( $item['description'] ) ? (string) $item['description'] : '',
					isset( $item['quantity'] ) ? (string) $item['quantity'] : '',
					Remember_Ticket_Renderer::money( isset( $item['unit_cost'] ) ? $item['unit_cost'] : 0 ),
					Remember_Ticket_Renderer::money( isset( $item['total'] ) ? $item['total'] : 0 ),
					$item_w,
					$qty_right,
					$unit_right,
					$total_right
				);
			}
		}

		if ( ! empty( $data['discount_amount'] ) && (float) $data['discount_amount'] > 0 ) {
			$this->total_row(
				Remember_Ticket_Renderer::discount_label( $data ),
				Remember_Ticket_Renderer::money( 0 - (float) $data['discount_amount'] ),
				$total_right
			);
		}
		$this->total_row( __( 'Total', 'remember' ), Remember_Ticket_Renderer::money( isset( $data['total_amount'] ) ? $data['total_amount'] : 0 ), $total_right );
		$this->total_row( __( 'Paid', 'remember' ), Remember_Ticket_Renderer::money( isset( $data['amount_paid'] ) ? $data['amount_paid'] : 0 ), $total_right );
		$this->total_row( __( 'Amount due', 'remember' ), Remember_Ticket_Renderer::money( isset( $data['amount_due'] ) ? $data['amount_due'] : 0 ), $total_right );
	}

	/**
	 * One receipt line. The description wraps inside the item column.
	 *
	 * @param string $description Item text.
	 * @param string $qty         Quantity.
	 * @param string $unit        Unit price.
	 * @param string $total       Line total.
	 * @param float  $item_w      Item column width.
	 * @param float  $qty_right   Right edge of the quantity column.
	 * @param float  $unit_right  Right edge of the unit column.
	 * @param float  $total_right Right edge of the total column.
	 * @return void
	 */
	private function receipt_row( $description, $qty, $unit, $total, $item_w, $qty_right, $unit_right, $total_right ) {
		$lines = $this->wrap( $description, 10, 1, $item_w );
		if ( ! $lines ) {
			$lines = array( '' );
		}
		$line_h = 13;
		$row_h  = 6 + ( count( $lines ) * $line_h );
		$this->need( $row_h + 2 );
		$baseline = $this->y + 12;
		foreach ( $lines as $index => $line ) {
			$this->draw_text( $this->margin, $baseline + ( $index * $line_h ), $line, 10, 1, $this->ink );
		}
		$this->draw_text_right( $qty_right, $baseline, $qty, 10, 1, $this->ink );
		$this->draw_text_right( $unit_right, $baseline, $unit, 10, 1, $this->ink );
		$this->draw_text_right( $total_right, $baseline, $total, 10, 1, $this->ink );
		$this->y += $row_h;
		$this->hline( $this->margin, $total_right, $this->y, array( 0.8, 0.8, 0.8 ), 0.4 );
	}

	/**
	 * A right-aligned total, paid, or discount row.
	 *
	 * @param string $label       Row label.
	 * @param string $amount      Formatted amount.
	 * @param float  $total_right Right edge of the amount.
	 * @return void
	 */
	private function total_row( $label, $amount, $total_right ) {
		$this->need( 16 );
		$baseline = $this->y + 13;
		$amount_w = $this->width( $amount, 10, 2 );
		$this->draw_text_right( $total_right - $amount_w - 12, $baseline, $label, 10, 2, $this->ink );
		$this->draw_text_right( $total_right, $baseline, $amount, 10, 2, $this->ink );
		$this->y += 16;
	}

	/**
	 * Closing note and the time the ticket was generated.
	 *
	 * @param array $data Ticket data.
	 * @return void
	 */
	private function draw_footer( $data ) {
		$this->y += 10;
		$this->need( 36 );
		$this->hline( $this->margin, $this->page_width - $this->margin, $this->y, array( 0.8, 0.8, 0.8 ), 0.6 );
		$this->y += 8;
		$this->text_line( __( 'Present this ticket at check-in. Status and amounts reflect the latest billing sync.', 'remember' ), 8, 1, $this->muted );
		$generated = '';
		if ( ! empty( $data['generated_at'] ) ) {
			$generated = date_i18n(
				get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
				strtotime( $data['generated_at'] )
			);
		}
		$this->text_line(
			sprintf(
				/* translators: %s: datetime */
				__( 'Generated: %s', 'remember' ),
				$generated
			),
			8,
			1,
			$this->muted
		);
	}

	/**
	 * Section label.
	 *
	 * @param string $label Section name.
	 * @return void
	 */
	private function heading( $label ) {
		$this->y += 10;
		$this->need( 16 );
		$this->draw_text( $this->margin, $this->y + 9, $this->upper( $label ), 9, 2, $this->muted, 0.7 );
		$this->y += 16;
	}

	/**
	 * Wrapped paragraph. X defaults to the left margin.
	 *
	 * @param string     $text     Text.
	 * @param float      $size     Font size.
	 * @param int        $font     Font number.
	 * @param array      $color    RGB 0–1.
	 * @param float|null $x        Left edge.
	 * @return void
	 */
	private function text_line( $text, $size, $font, $color, $x = null ) {
		$x        = null === $x ? $this->margin : $x;
		$max      = $this->page_width - $this->margin - $x;
		$lines    = $this->wrap( $text, $size, $font, $max );
		$leading  = $size + 4;
		if ( ! $lines ) {
			$lines = array( '' );
		}
		foreach ( $lines as $line ) {
			$this->need( $leading );
			$this->draw_text( $x, $this->y + $size, $line, $size, $font, $color );
			$this->y += $leading;
		}
	}

	/**
	 * Bold label and a value on the same line. Extra value lines indent under the value.
	 *
	 * @param string     $label Label, including its colon.
	 * @param string     $value Value.
	 * @param array|null $color Value color.
	 * @return void
	 */
	private function labeled( $label, $value, $color = null ) {
		$color = $color ? $color : $this->ink;
		$this->need( 16 );
		$label_w = $this->width( $label, 11, 2 ) + 6;
		$max     = $this->page_width - $this->margin - $this->margin - $label_w;
		$lines   = $this->wrap( $value, 11, 1, $max );
		if ( ! $lines ) {
			$lines = array( '' );
		}
		$baseline = $this->y + 11;
		$this->draw_text( $this->margin, $baseline, $label, 11, 2, $this->ink );
		$this->draw_text( $this->margin + $label_w, $baseline, $lines[0], 11, 1, $color );
		$this->y += 16;
		$count = count( $lines );
		for ( $i = 1; $i < $count; $i++ ) {
			$this->need( 15 );
			$this->draw_text( $this->margin + $label_w, $this->y + 11, $lines[ $i ], 11, 1, $color );
			$this->y += 15;
		}
	}

	/**
	 * Start a page. A continued page names the ticket.
	 *
	 * @param bool $continued Whether earlier content already filled a page.
	 * @return void
	 */
	private function start_page( $continued ) {
		$this->ops = '';
		$this->y   = $this->margin;
		$this->stroke_rect( 28, 28, $this->page_width - 56, $this->page_height - 56, array( 0.75, 0.75, 0.75 ), 0.6 );
		if ( $continued ) {
			$this->draw_text(
				$this->margin,
				$this->y + 11,
				sprintf(
					/* translators: %s: ticket id */
					__( 'Ticket %s, continued', 'remember' ),
					$this->ticket_id
				),
				10,
				2,
				$this->muted
			);
			$this->y += 18;
			$this->hline( $this->margin, $this->page_width - $this->margin, $this->y, $this->ink, 0.8 );
			$this->y += 10;
		}
	}

	/**
	 * Open a new page when the next block will not fit.
	 *
	 * @param float $height Height still needed.
	 * @return void
	 */
	private function need( $height ) {
		$limit = $this->page_height - $this->margin;
		if ( ( $this->y + $height ) <= $limit ) {
			return;
		}
		if ( $height >= ( $limit - $this->margin ) ) {
			return;
		}
		$this->ops .= $this->stamp_ops();
		$this->pages[] = $this->ops;
		$this->start_page( true );
	}

	/**
	 * Close the last page.
	 *
	 * @return void
	 */
	private function finish_page() {
		$this->ops    .= $this->stamp_ops();
		$this->pages[] = $this->ops;
		$this->ops     = '';
	}

	/**
	 * Diagonal void or payment stamp, drawn over the page.
	 *
	 * @return string
	 */
	private function stamp_ops() {
		if ( '' === $this->stamp_label ) {
			return '';
		}
		$size  = strlen( $this->stamp_label ) > 12 ? 18 : 26;
		$width = $this->width( $this->stamp_label, $size, 2 );
		$cos   = cos( deg2rad( -18 ) );
		$sin   = sin( deg2rad( -18 ) );
		$color = 'void' === $this->stamp ? '0.690 0.000 0.125' : '0.541 0.353 0.000';
		$pad_x = 10;
		$pad_y = 7;
		return sprintf(
			"q %.4F %.4F %.4F %.4F %.2F %.2F cm /GS1 gs %s RG %s rg 2.2 w %.2F %.2F %.2F %.2F re S BT /F2 %.2F Tf %.2F 0 Td (%s) Tj ET Q\n",
			$cos,
			$sin,
			-$sin,
			$cos,
			$this->page_width / 2,
			$this->page_height / 2,
			$color,
			$color,
			- ( $width / 2 ) - $pad_x,
			- ( $size * 0.28 ) - $pad_y,
			$width + ( $pad_x * 2 ),
			$size + ( $pad_y * 2 ),
			$size,
			- ( $width / 2 ),
			$this->pdf_string( $this->stamp_label )
		);
	}

	/**
	 * Local logo file, matching the on-screen ticket's choice.
	 *
	 * @return string
	 */
	private function logo_path() {
		$options = get_option( 'remember_options', array() );
		$id      = 0;
		if ( is_array( $options ) && ! empty( $options['ticket_logo_id'] ) ) {
			$id = absint( $options['ticket_logo_id'] );
		}
		if ( $id <= 0 ) {
			$id = (int) get_theme_mod( 'custom_logo' );
		}
		if ( $id <= 0 ) {
			return '';
		}
		$path = get_attached_file( $id );
		if ( ! is_string( $path ) || '' === $path || ! is_readable( $path ) ) {
			return '';
		}
		return $path;
	}

	/**
	 * Load the logo as a JPEG XObject. PNG and WebP are flattened onto white.
	 *
	 * @return bool
	 */
	private function prepare_logo() {
		$path = $this->logo_path();
		if ( '' === $path ) {
			return false;
		}
		$info = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a bad upload should skip the logo, not fail the download.
		if ( ! is_array( $info ) || empty( $info[0] ) || empty( $info[1] ) ) {
			return false;
		}
		$mime  = isset( $info['mime'] ) ? $info['mime'] : '';
		$bytes = '';
		$px_w  = (int) $info[0];
		$px_h  = (int) $info[1];
		if ( 'image/jpeg' === $mime ) {
			$raw = file_get_contents( $path );
			if ( ! is_string( $raw ) || '' === $raw ) {
				return false;
			}
			$bytes = $raw;
		} elseif ( function_exists( 'imagecreatefromstring' ) ) {
			$raw = file_get_contents( $path );
			if ( ! is_string( $raw ) || '' === $raw ) {
				return false;
			}
			$image = @imagecreatefromstring( $raw ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- skip an unreadable logo.
			if ( ! $image ) {
				return false;
			}
			$px_w = imagesx( $image );
			$px_h = imagesy( $image );
			$flat = imagecreatetruecolor( $px_w, $px_h );
			if ( ! $flat ) {
				imagedestroy( $image );
				return false;
			}
			$white = imagecolorallocate( $flat, 255, 255, 255 );
			imagefilledrectangle( $flat, 0, 0, $px_w, $px_h, $white );
			imagecopy( $flat, $image, 0, 0, 0, 0, $px_w, $px_h );
			ob_start();
			imagejpeg( $flat, null, 85 );
			$bytes = ob_get_clean();
			imagedestroy( $image );
			imagedestroy( $flat );
			if ( ! is_string( $bytes ) || '' === $bytes ) {
				return false;
			}
		} else {
			return false;
		}

		$scale         = min( 110 / $px_w, 56 / $px_h, 1 );
		$this->jpeg    = $bytes;
		$this->image_px_w = $px_w;
		$this->image_px_h = $px_h;
		$this->logo_w  = $px_w * $scale;
		$this->logo_h  = $px_h * $scale;
		return true;
	}

	/**
	 * Paint the logo.
	 *
	 * @param float $x     Left edge.
	 * @param float $y_top Top edge.
	 * @return void
	 */
	private function draw_logo( $x, $y_top ) {
		$pdf_y      = $this->page_height - $y_top - $this->logo_h;
		$this->ops .= sprintf(
			"q %.2F 0 0 %.2F %.2F %.2F cm /Im1 Do Q\n",
			$this->logo_w,
			$this->logo_h,
			$x,
			$pdf_y
		);
	}

	/**
	 * Assemble objects, the cross-reference table, and the trailer.
	 *
	 * @param array $data Ticket data.
	 * @return string
	 */
	private function document( $data ) {
		$this->finish_page();
		$page_count   = count( $this->pages );
		$page_ids     = array();
		$content_ids  = array();
		for ( $i = 0; $i < $page_count; $i++ ) {
			$page_ids[]    = 3 + $i;
			$content_ids[] = 3 + $page_count + $i;
		}
		$next = 3 + ( 2 * $page_count );
		$font_ids = array();
		for ( $font = 1; $font <= 5; $font++ ) {
			$font_ids[ $font ] = $next;
			++$next;
		}
		$gs_id    = $next;
		++$next;
		$image_id = 0;
		if ( '' !== $this->jpeg ) {
			$image_id = $next;
			++$next;
		}
		$info_id = $next;

		$objects           = array();
		$objects[1]        = '<< /Type /Catalog /Pages 2 0 R >>';
		$kids              = array();
		foreach ( $page_ids as $page_id ) {
			$kids[] = $page_id . ' 0 R';
		}
		$objects[2] = '<< /Type /Pages /Count ' . $page_count . ' /Kids [' . implode( ' ', $kids ) . '] >>';

		$font_refs = '';
		for ( $font = 1; $font <= 5; $font++ ) {
			$font_refs .= '/F' . $font . ' ' . $font_ids[ $font ] . ' 0 R ';
		}
		$xobject   = $image_id ? ' /XObject << /Im1 ' . $image_id . ' 0 R >>' : '';
		$resources = '<< /Font << ' . $font_refs . '>> /ExtGState << /GS1 ' . $gs_id . ' 0 R >>' . $xobject . ' >>';

		foreach ( $page_ids as $index => $page_id ) {
			$objects[ $page_id ] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . $this->page_width . ' ' . $this->page_height . '] /Contents ' . $content_ids[ $index ] . ' 0 R /Resources ' . $resources . ' >>';
			$stream               = $this->pages[ $index ];
			$objects[ $content_ids[ $index ] ] = '<< /Length ' . strlen( $stream ) . " >>\nstream\n" . $stream . 'endstream';
		}

		$bases = array(
			1 => 'Helvetica',
			2 => 'Helvetica-Bold',
			3 => 'Times-Bold',
			4 => 'Times-Roman',
			5 => 'Courier',
		);
		foreach ( $bases as $font => $name ) {
			$objects[ $font_ids[ $font ] ] = '<< /Type /Font /Subtype /Type1 /BaseFont /' . $name . ' /Encoding /WinAnsiEncoding >>';
		}
		$objects[ $gs_id ] = '<< /Type /ExtGState /ca 0.55 /CA 0.55 >>';
		if ( $image_id ) {
			$objects[ $image_id ] = '<< /Type /XObject /Subtype /Image /Width ' . (int) $this->image_px_w . ' /Height ' . (int) $this->image_px_h . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen( $this->jpeg ) . " >>\nstream\n" . $this->jpeg . "\nendstream";
		}

		$title = $this->pdf_string(
			sprintf(
				/* translators: %s: ticket id */
				__( 'Admission Ticket %s', 'remember' ),
				isset( $data['ticket_id'] ) ? $data['ticket_id'] : ''
			)
		);
		$objects[ $info_id ] = '<< /Title (' . $title . ') /Producer (reMember) /Creator (reMember) >>';

		ksort( $objects );
		$pdf      = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offsets  = array( 0 );
		$max      = (int) max( array_keys( $objects ) );
		for ( $i = 1; $i <= $max; $i++ ) {
			$offsets[ $i ] = strlen( $pdf );
			$pdf          .= $i . " 0 obj\n" . $objects[ $i ] . "\nendobj\n";
		}
		$xref_at = strlen( $pdf );
		$pdf    .= 'xref' . "\n" . '0 ' . ( $max + 1 ) . "\n";
		$pdf    .= "0000000000 65535 f \n";
		for ( $i = 1; $i <= $max; $i++ ) {
			$pdf .= sprintf( "%010d 00000 n \n", $offsets[ $i ] );
		}
		$pdf .= 'trailer' . "\n" . '<< /Size ' . ( $max + 1 ) . ' /Root 1 0 R /Info ' . $info_id . " 0 R >>\n";
		$pdf .= 'startxref' . "\n" . $xref_at . "\n%%EOF";
		return $pdf;
	}

	/**
	 * Draw text. Y is the baseline measured from the top of the page.
	 *
	 * @param float $x        Left edge.
	 * @param float $baseline Baseline from the top.
	 * @param string $text    Text.
	 * @param float $size     Font size.
	 * @param int   $font     Font number.
	 * @param array $rgb      Fill color.
	 * @param float $spacing  Extra space between letters, in points.
	 * @return void
	 */
	private function draw_text( $x, $baseline, $text, $size, $font, $rgb, $spacing = 0 ) {
		$y          = $this->page_height - $baseline;
		$this->ops .= sprintf(
			"BT %s rg /F%d %.2F Tf %.2F Tc 1 0 0 1 %.2F %.2F Tm (%s) Tj ET\n",
			$this->rgb( $rgb ),
			$font,
			$size,
			$spacing,
			$x,
			$y,
			$this->pdf_string( $text )
		);
	}

	/**
	 * Draw text ending at a right edge.
	 *
	 * @param float  $right    Right edge.
	 * @param float  $baseline Baseline from the top.
	 * @param string $text     Text.
	 * @param float  $size     Font size.
	 * @param int    $font     Font number.
	 * @param array  $rgb      Fill color.
	 * @return void
	 */
	private function draw_text_right( $right, $baseline, $text, $size, $font, $rgb ) {
		$this->draw_text( $right - $this->width( $text, $size, $font ), $baseline, $text, $size, $font, $rgb );
	}

	/**
	 * Horizontal rule. Y is measured from the top.
	 *
	 * @param float $x1    Start.
	 * @param float $x2    End.
	 * @param float $y_top Distance from the top.
	 * @param array $rgb   Stroke color.
	 * @param float $width Line width.
	 * @return void
	 */
	private function hline( $x1, $x2, $y_top, $rgb, $width ) {
		$y          = $this->page_height - $y_top;
		$this->ops .= sprintf(
			"%s RG %.2F w %.2F %.2F m %.2F %.2F l S\n",
			$this->rgb( $rgb ),
			$width,
			$x1,
			$y,
			$x2,
			$y
		);
	}

	/**
	 * Stroked rectangle. Y is the top edge.
	 *
	 * @param float $x     Left.
	 * @param float $y_top Top.
	 * @param float $w     Width.
	 * @param float $h     Height.
	 * @param array $rgb   Stroke color.
	 * @param float $width Line width.
	 * @return void
	 */
	private function stroke_rect( $x, $y_top, $w, $h, $rgb, $width ) {
		$y          = $this->page_height - $y_top - $h;
		$this->ops .= sprintf(
			"%s RG %.2F w %.2F %.2F %.2F %.2F re S\n",
			$this->rgb( $rgb ),
			$width,
			$x,
			$y,
			$w,
			$h
		);
	}

	/**
	 * Filled rectangle. Y is the top edge.
	 *
	 * @param float $x     Left.
	 * @param float $y_top Top.
	 * @param float $w     Width.
	 * @param float $h     Height.
	 * @param array $rgb   Fill color.
	 * @return void
	 */
	private function fill_rect( $x, $y_top, $w, $h, $rgb ) {
		$y          = $this->page_height - $y_top - $h;
		$this->ops .= sprintf( "%s rg %.2F %.2F %.2F %.2F re f\n", $this->rgb( $rgb ), $x, $y, $w, $h );
	}

	/**
	 * Color for a payment status.
	 *
	 * @param string $status Payment status.
	 * @return array
	 */
	private function pay_color( $status ) {
		if ( 'paid' === $status ) {
			return array( 0.106, 0.369, 0.125 );
		}
		if ( in_array( $status, array( 'refunded', 'cancelled' ), true ) ) {
			return array( 0.690, 0.000, 0.125 );
		}
		return array( 0.541, 0.353, 0.000 );
	}

	/**
	 * Uppercase a heading. Falls back when mbstring is absent.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private function upper( $text ) {
		if ( function_exists( 'mb_strtoupper' ) ) {
			return mb_strtoupper( $text, 'UTF-8' );
		}
		return strtoupper( $text );
	}

	/**
	 * Wrap text to a width. Long words break by character.
	 *
	 * @param string $text      Text.
	 * @param float  $size      Font size.
	 * @param int    $font      Font number.
	 * @param float  $max_width Maximum line width.
	 * @param float  $spacing   Letter spacing.
	 * @return array
	 */
	private function wrap( $text, $size, $font, $max_width, $spacing = 0 ) {
		$text = trim( (string) preg_replace( '/\s+/', ' ', (string) $text ) );
		if ( '' === $text ) {
			return array();
		}
		$words   = explode( ' ', $text );
		$lines   = array();
		$current = '';
		foreach ( $words as $word ) {
			$trial = '' === $current ? $word : $current . ' ' . $word;
			if ( $this->width( $trial, $size, $font, $spacing ) <= $max_width ) {
				$current = $trial;
				continue;
			}
			if ( '' !== $current ) {
				$lines[] = $current;
				$current = '';
			}
			if ( $this->width( $word, $size, $font, $spacing ) <= $max_width ) {
				$current = $word;
				continue;
			}
			$chunk = '';
			$chars = preg_split( '//u', $word, -1, PREG_SPLIT_NO_EMPTY );
			if ( ! is_array( $chars ) ) {
				$chars = array( $word );
			}
			foreach ( $chars as $char ) {
				$next = $chunk . $char;
				if ( '' !== $chunk && $this->width( $next, $size, $font, $spacing ) > $max_width ) {
					$lines[] = $chunk;
					$chunk   = $char;
				} else {
					$chunk = $next;
				}
			}
			$current = $chunk;
		}
		if ( '' !== $current ) {
			$lines[] = $current;
		}
		return $lines;
	}

	/**
	 * Width of a string in points.
	 *
	 * @param string $text    Text.
	 * @param float  $size    Font size.
	 * @param int    $font    Font number.
	 * @param float  $spacing Letter spacing.
	 * @return float
	 */
	private function width( $text, $size, $font, $spacing = 0 ) {
		$encoded = $this->to_win1252( $text );
		$sum     = 0;
		$len     = strlen( $encoded );
		for ( $i = 0; $i < $len; $i++ ) {
			$sum += $this->glyph_width( ord( $encoded[ $i ] ), $font );
		}
		return ( $sum * $size / 1000 ) + ( $spacing * max( 0, $len - 1 ) );
	}

	/**
	 * Glyph width in thousandths of an em. Courier is fixed. Bold is a little wider.
	 *
	 * @param int $ord  Byte.
	 * @param int $font Font number.
	 * @return int
	 */
	private function glyph_width( $ord, $font ) {
		if ( 5 === (int) $font ) {
			return 600;
		}
		static $widths = null;
		if ( null === $widths ) {
			$widths = array(
				32  => 278, 33  => 278, 34  => 355, 35  => 556, 36  => 556, 37  => 889, 38  => 667, 39  => 191,
				40  => 333, 41  => 333, 42  => 389, 43  => 584, 44  => 278, 45  => 333, 46  => 278, 47  => 278,
				48  => 556, 49  => 556, 50  => 556, 51  => 556, 52  => 556, 53  => 556, 54  => 556, 55  => 556,
				56  => 556, 57  => 556, 58  => 278, 59  => 278, 60  => 584, 61  => 584, 62  => 584, 63  => 556,
				64  => 1015, 65 => 667, 66 => 667, 67 => 722, 68 => 722, 69 => 667, 70 => 611, 71 => 778,
				72  => 722, 73 => 278, 74 => 500, 75 => 667, 76 => 556, 77 => 833, 78 => 722, 79 => 778,
				80  => 667, 81 => 778, 82 => 722, 83 => 667, 84 => 611, 85 => 722, 86 => 667, 87 => 944,
				88  => 667, 89 => 667, 90 => 611, 91 => 278, 92 => 278, 93 => 278, 94 => 469, 95 => 556,
				96  => 333, 97 => 556, 98 => 556, 99 => 500, 100 => 556, 101 => 556, 102 => 278, 103 => 556,
				104 => 556, 105 => 222, 106 => 222, 107 => 500, 108 => 222, 109 => 833, 110 => 556, 111 => 556,
				112 => 556, 113 => 556, 114 => 333, 115 => 500, 116 => 278, 117 => 556, 118 => 500, 119 => 722,
				120 => 500, 121 => 500, 122 => 500, 123 => 334, 124 => 260, 125 => 334, 126 => 584,
			);
		}
		$width = isset( $widths[ $ord ] ) ? $widths[ $ord ] : 600;
		if ( 2 === (int) $font || 3 === (int) $font ) {
			// Helvetica-Bold is wider than Helvetica, especially on narrow letters.
			$width = (int) round( $width * 1.25 );
		}
		return $width;
	}

	/**
	 * PDF literal string, Windows-1252, with parentheses and slashes escaped.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private function pdf_string( $text ) {
		$text = $this->to_win1252( $text );
		$out  = '';
		$len  = strlen( $text );
		for ( $i = 0; $i < $len; $i++ ) {
			$ord = ord( $text[ $i ] );
			if ( 40 === $ord || 41 === $ord || 92 === $ord ) {
				$out .= '\\' . $text[ $i ];
			} elseif ( $ord < 32 || $ord > 126 ) {
				$out .= sprintf( '\\%03o', $ord );
			} else {
				$out .= $text[ $i ];
			}
		}
		return $out;
	}

	/**
	 * UTF-8 to Windows-1252. Characters that will not fit become a close ASCII stand-in, or drop.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private function to_win1252( $text ) {
		$text = (string) $text;
		if ( function_exists( 'iconv' ) ) {
			$converted = @iconv( 'UTF-8', 'Windows-1252//TRANSLIT', $text ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- fall through when the encoding is unavailable.
			if ( false === $converted ) {
				$converted = @iconv( 'UTF-8', 'Windows-1252//IGNORE', $text ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- last resort drops unmappable characters.
			}
			if ( false !== $converted ) {
				return $converted;
			}
		}
		$stripped = preg_replace( '/[^\x09\x0A\x0D\x20-\x7E]/', '?', $text );
		return is_string( $stripped ) ? $stripped : $text;
	}

	/**
	 * Format an RGB triplet for a PDF operator.
	 *
	 * @param array $rgb RGB 0–1.
	 * @return string
	 */
	private function rgb( $rgb ) {
		return sprintf( '%.3F %.3F %.3F', $rgb[0], $rgb[1], $rgb[2] );
	}
}
