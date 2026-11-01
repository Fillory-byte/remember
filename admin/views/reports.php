<?php
/**
 * Staff report builder.
 *
 * @package    reMember
 * @subpackage reMember/admin/views
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}
?>
<div class="wrap remember-reports">
	<div class="remember-reports-pagehead">
		<div>
			<h1><?php esc_html_e( 'Reports', 'remember' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Build a table from members, applications, payments, vetting, or events. Saved reports are yours only; you can copy one into another staff member’s library if they can run it. A saved report can also be emailed as a CSV on a schedule. Columns you cannot read are dropped when the report runs.', 'remember' ); ?></p>
		</div>
	</div>
	<div id="remember-reports-notice"></div>
	<div class="remember-reports-layout">
		<aside class="remember-reports-sidebar">
			<div class="remember-reports-panel">
				<div class="remember-reports-panel__head">
					<h2><?php esc_html_e( 'My Reports', 'remember' ); ?></h2>
					<button type="button" class="button-link" id="remember-report-new"><?php esc_html_e( 'New report', 'remember' ); ?></button>
				</div>
				<ul id="remember-saved-reports" class="remember-reports-nav"></ul>
			</div>
		</aside>
		<div class="remember-reports-main">
			<div class="remember-reports-panel">
				<div class="remember-reports-toolbar">
					<label class="remember-reports-field remember-reports-name">
						<span class="remember-reports-label"><?php esc_html_e( 'Name', 'remember' ); ?></span>
						<input type="text" id="remember-report-name" maxlength="255" placeholder="<?php esc_attr_e( 'Untitled report', 'remember' ); ?>">
					</label>
					<label class="remember-reports-field">
						<span class="remember-reports-label"><?php esc_html_e( 'Subject', 'remember' ); ?></span>
						<select id="remember-report-subject"></select>
					</label>
					<label class="remember-reports-field remember-reports-event">
						<span class="remember-reports-label"><?php esc_html_e( 'Event', 'remember' ); ?></span>
						<select id="remember-report-event"></select>
					</label>
					<fieldset class="remember-reports-seg">
						<legend class="remember-reports-label"><?php esc_html_e( 'Result', 'remember' ); ?></legend>
						<label>
							<input type="radio" name="remember_report_mode" value="detail" checked>
							<span><?php esc_html_e( 'Rows', 'remember' ); ?></span>
						</label>
						<label>
							<input type="radio" name="remember_report_mode" value="summary">
							<span><?php esc_html_e( 'Summary', 'remember' ); ?></span>
						</label>
					</fieldset>
					<div class="remember-reports-toolbar-run">
						<button type="button" class="button button-primary remember-report-run" id="remember-report-run"><?php esc_html_e( 'Run report', 'remember' ); ?></button>
					</div>
				</div>
				<p class="description remember-reports-event-hint" id="remember-report-event-hint"></p>

				<div id="remember-report-columns-wrap" class="remember-reports-section">
					<div class="remember-reports-section__head">
						<h3><?php esc_html_e( 'Columns', 'remember' ); ?></h3>
						<p class="description"><?php esc_html_e( 'Choose fields, then order the selected list.', 'remember' ); ?></p>
						<p class="description" id="remember-report-survey-layout" hidden><?php esc_html_e( 'A survey result is one row per response. Each question is a column, and the cell is the answer. Question and Answer stay available as filters.', 'remember' ); ?></p>
					</div>
					<div class="remember-reports-columns-grid">
						<div id="remember-report-column-groups"></div>
						<div class="remember-reports-selected">
							<h4><?php esc_html_e( 'Selected', 'remember' ); ?></h4>
							<ol id="remember-report-column-order" class="remember-reports-order"></ol>
						</div>
					</div>
				</div>

				<div id="remember-report-summary-wrap" class="remember-reports-section" hidden>
					<div class="remember-reports-section__head">
						<h3><?php esc_html_e( 'Grouping', 'remember' ); ?></h3>
						<p class="description"><?php esc_html_e( 'Group rows by one or more fields, then add counts or totals. Leave grouping empty for a single total row.', 'remember' ); ?></p>
					</div>
					<div id="remember-report-groups"></div>
					<p class="remember-reports-add">
						<button type="button" class="button" id="remember-report-add-group"><?php esc_html_e( 'Add grouping', 'remember' ); ?></button>
					</p>
					<div class="remember-reports-section__head">
						<h3><?php esc_html_e( 'Calculations', 'remember' ); ?></h3>
					</div>
					<div id="remember-report-aggs"></div>
					<p class="remember-reports-add">
						<button type="button" class="button" id="remember-report-add-agg"><?php esc_html_e( 'Add calculation', 'remember' ); ?></button>
					</p>
				</div>

				<div class="remember-reports-section">
					<div class="remember-reports-section__head">
						<h3><?php esc_html_e( 'Filters', 'remember' ); ?></h3>
					</div>
					<div id="remember-report-filters"></div>
					<p class="remember-reports-add">
						<button type="button" class="button" id="remember-report-add-filter"><?php esc_html_e( 'Add filter', 'remember' ); ?></button>
					</p>
					<div class="remember-reports-filter-logic" id="remember-report-filter-logic" hidden>
						<p class="remember-reports-filter-preview" id="remember-report-filter-preview"></p>
						<p class="description"><?php esc_html_e( 'Choose AND or OR between filters. Use ( and ) to group filters, and add them wherever AND and OR appear together.', 'remember' ); ?></p>
					</div>
				</div>

				<div class="remember-reports-section remember-reports-sort">
					<div class="remember-reports-section__head">
						<h3><?php esc_html_e( 'Sort', 'remember' ); ?></h3>
					</div>
					<div class="remember-reports-criteria">
						<label class="remember-reports-field">
							<span class="remember-reports-label"><?php esc_html_e( 'Field', 'remember' ); ?></span>
							<select id="remember-report-sort-field"></select>
						</label>
						<label class="remember-reports-field">
							<span class="remember-reports-label"><?php esc_html_e( 'Direction', 'remember' ); ?></span>
							<select id="remember-report-sort-dir">
								<option value="asc"><?php esc_html_e( 'Ascending', 'remember' ); ?></option>
								<option value="desc"><?php esc_html_e( 'Descending', 'remember' ); ?></option>
							</select>
						</label>
					</div>
				</div>

				<div class="remember-reports-footer">
					<button type="button" class="button button-primary remember-report-run"><?php esc_html_e( 'Run report', 'remember' ); ?></button>
					<button type="button" class="button" id="remember-report-save"><?php esc_html_e( 'Save', 'remember' ); ?></button>
					<button type="button" class="button" id="remember-report-save-as"><?php esc_html_e( 'Save as', 'remember' ); ?></button>
					<button type="button" class="button" id="remember-report-copy" disabled><?php esc_html_e( 'Copy to…', 'remember' ); ?></button>
					<button type="button" class="button" id="remember-report-schedule" disabled><?php esc_html_e( 'Schedule', 'remember' ); ?></button>
					<button type="button" class="button" id="remember-report-delete" disabled><?php esc_html_e( 'Delete', 'remember' ); ?></button>
					<span class="remember-reports-footer-spacer"></span>
					<button type="button" class="button" id="remember-report-export"><?php esc_html_e( 'Export CSV', 'remember' ); ?></button>
				</div>
				<div id="remember-report-copy-panel" class="remember-reports-copy" hidden>
					<label class="remember-reports-field">
						<span class="remember-reports-label"><?php esc_html_e( 'Copy to', 'remember' ); ?></span>
						<select id="remember-report-copy-user"></select>
					</label>
					<button type="button" class="button" id="remember-report-copy-confirm"><?php esc_html_e( 'Copy', 'remember' ); ?></button>
					<button type="button" class="button-link" id="remember-report-copy-cancel"><?php esc_html_e( 'Cancel', 'remember' ); ?></button>
				</div>
				<div id="remember-report-schedule-panel" class="remember-reports-schedule" hidden>
					<h3><?php esc_html_e( 'Email schedule', 'remember' ); ?></h3>
					<p class="description" id="remember-report-schedule-note"></p>
					<label>
						<input type="checkbox" id="remember-report-schedule-enabled" value="1">
						<?php esc_html_e( 'Email this report', 'remember' ); ?>
					</label>
					<div class="remember-reports-criteria">
						<label class="remember-reports-field">
							<span class="remember-reports-label"><?php esc_html_e( 'How often', 'remember' ); ?></span>
							<select id="remember-report-schedule-frequency">
								<option value="daily"><?php esc_html_e( 'Every day', 'remember' ); ?></option>
								<option value="weekly"><?php esc_html_e( 'Every week', 'remember' ); ?></option>
								<option value="monthly"><?php esc_html_e( 'Every month', 'remember' ); ?></option>
							</select>
						</label>
						<label class="remember-reports-field" id="remember-report-schedule-weekday-wrap">
							<span class="remember-reports-label"><?php esc_html_e( 'Day', 'remember' ); ?></span>
							<select id="remember-report-schedule-weekday">
								<option value="1"><?php esc_html_e( 'Monday', 'remember' ); ?></option>
								<option value="2"><?php esc_html_e( 'Tuesday', 'remember' ); ?></option>
								<option value="3"><?php esc_html_e( 'Wednesday', 'remember' ); ?></option>
								<option value="4"><?php esc_html_e( 'Thursday', 'remember' ); ?></option>
								<option value="5"><?php esc_html_e( 'Friday', 'remember' ); ?></option>
								<option value="6"><?php esc_html_e( 'Saturday', 'remember' ); ?></option>
								<option value="7"><?php esc_html_e( 'Sunday', 'remember' ); ?></option>
							</select>
						</label>
						<label class="remember-reports-field" id="remember-report-schedule-monthday-wrap" hidden>
							<span class="remember-reports-label"><?php esc_html_e( 'Date', 'remember' ); ?></span>
							<input type="number" id="remember-report-schedule-monthday" min="1" max="31" value="1">
						</label>
						<label class="remember-reports-field">
							<span class="remember-reports-label"><?php esc_html_e( 'Time', 'remember' ); ?></span>
							<input type="time" id="remember-report-schedule-time" value="08:00">
						</label>
						<label class="remember-reports-field">
							<span class="remember-reports-label"><?php esc_html_e( 'Event', 'remember' ); ?></span>
							<select id="remember-report-schedule-event"></select>
						</label>
					</div>
					<label>
						<input type="checkbox" id="remember-report-schedule-skip" value="1" checked>
						<?php esc_html_e( 'Skip the email when the report has no rows', 'remember' ); ?>
					</label>
					<label id="remember-report-schedule-sensitive-wrap" hidden>
						<input type="checkbox" id="remember-report-schedule-sensitive" value="1">
						<?php esc_html_e( 'This report includes health or emergency contact fields. Include them in the email.', 'remember' ); ?>
					</label>
					<label class="remember-reports-field">
						<span class="remember-reports-label"><?php esc_html_e( 'Send to', 'remember' ); ?></span>
						<select id="remember-report-schedule-recipients" multiple size="6" aria-describedby="remember-report-schedule-warning"></select>
					</label>
					<div id="remember-report-schedule-warning" class="notice notice-warning inline remember-reports-schedule-warning" aria-live="polite" hidden></div>
					<p class="description" id="remember-report-schedule-last"></p>
					<p>
						<button type="button" class="button button-primary" id="remember-report-schedule-save"><?php esc_html_e( 'Save schedule', 'remember' ); ?></button>
						<button type="button" class="button-link" id="remember-report-schedule-cancel"><?php esc_html_e( 'Cancel', 'remember' ); ?></button>
					</p>
				</div>
			</div>

			<div class="remember-reports-panel remember-reports-results">
				<div class="remember-reports-results__head">
					<h2><?php esc_html_e( 'Results', 'remember' ); ?></h2>
					<p id="remember-report-meta" class="remember-reports-meta"></p>
				</div>
				<div class="remember-reports-table-wrap">
					<table class="widefat striped" id="remember-report-table">
						<thead></thead>
						<tbody></tbody>
					</table>
				</div>
				<p id="remember-report-empty" class="remember-reports-empty" hidden><?php esc_html_e( 'Run a report to see rows here.', 'remember' ); ?></p>
				<div id="remember-report-pager" class="remember-reports-pager" hidden>
					<button type="button" class="button" id="remember-report-prev"><?php esc_html_e( 'Previous', 'remember' ); ?></button>
					<span id="remember-report-page-label"></span>
					<button type="button" class="button" id="remember-report-next"><?php esc_html_e( 'Next', 'remember' ); ?></button>
				</div>
			</div>
		</div>
	</div>
	<form id="remember-report-export-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" hidden>
		<input type="hidden" name="action" value="remember_report_export">
		<input type="hidden" name="nonce" value="">
		<input type="hidden" name="definition" value="">
		<input type="hidden" name="event_id" value="">
	</form>
</div>
