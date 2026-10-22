/**
 * Staff report builder.
 */
(function ($) {
	'use strict';

	var cfg = window.rememberReports || {};
	var i18n = cfg.i18n || {};
	var catalog = null;
	var loadingSaved = false;
	var page = 1;
	var lastTotal = 0;
	var lastPages = 1;
	var eventId = 0;
	var state = emptyState();

	function emptyState() {
		return {
			reportId: 0,
			name: '',
			subject: '',
			mode: 'detail',
			columns: [],
			filters: [],
			group_by: [],
			aggregations: [],
			sort: { field: '', dir: 'asc' }
		};
	}

	function t(key, fallback) {
		return i18n[key] || fallback || key;
	}

	function notice(message, type) {
		var cls = type === 'error' ? 'notice-error' : 'notice-success';
		var $box = $('#remember-reports-notice');
		if (!message) {
			$box.empty();
			return;
		}
		$box.html('<div class="notice ' + cls + ' is-dismissible"><p></p></div>');
		$box.find('p').text(message);
	}

	function post(action, data) {
		data = data || {};
		data.action = action;
		data.nonce = cfg.nonce;
		return $.post(cfg.ajaxurl, data);
	}

	function currentSubject() {
		if (!catalog || !catalog.subjects) {
			return null;
		}
		var i;
		for (i = 0; i < catalog.subjects.length; i++) {
			if (catalog.subjects[i].id === state.subject) {
				return catalog.subjects[i];
			}
		}
		return catalog.subjects[0] || null;
	}

	function fields() {
		var sub = currentSubject();
		return sub && sub.fields ? sub.fields : [];
	}

	function fieldById(id) {
		var list = fields();
		var i;
		for (i = 0; i < list.length; i++) {
			if (list[i].id === id) {
				return list[i];
			}
		}
		return null;
	}

	function definition() {
		return {
			subject: state.subject,
			mode: state.mode,
			columns: state.columns.slice(),
			filters: state.filters.map(function (f) {
				return {
					field: f.field,
					op: f.op,
					value: f.value,
					value_from: f.value_from,
					value_to: f.value_to
				};
			}),
			group_by: state.group_by.slice(),
			aggregations: state.aggregations.map(function (a) {
				return { fn: a.fn, field: a.field };
			}),
			sort: {
				field: state.sort.field || '',
				dir: state.sort.dir === 'desc' ? 'desc' : 'asc'
			}
		};
	}

	function applyDefaults() {
		var sub = currentSubject();
		if (!sub) {
			return;
		}
		state.subject = sub.id;
		state.columns = (sub.default || []).slice();
		state.filters = [];
		state.group_by = [];
		state.aggregations = [{ fn: 'count', field: '*' }];
		state.sort = { field: state.columns[0] || '', dir: 'asc' };
	}

	function operatorsFor(field) {
		var ops = catalog.operators || [];
		var type = field ? field.type : 'string';
		var allowed;
		if (type === 'number' || type === 'date' || type === 'datetime') {
			allowed = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'between', 'empty', 'not_empty'];
		} else if (type === 'multiselect' || (field && field.list)) {
			allowed = ['eq', 'neq', 'in', 'not_in', 'empty', 'not_empty'];
		} else if (type === 'enum') {
			allowed = ['eq', 'neq', 'in', 'empty', 'not_empty'];
		} else {
			allowed = ['eq', 'neq', 'contains', 'in', 'empty', 'not_empty'];
		}
		return ops.filter(function (op) {
			return allowed.indexOf(op.id) !== -1;
		});
	}

	function hasChoices(field) {
		return !!(field && field.options && field.options.length && (field.type === 'enum' || field.type === 'multiselect' || field.list));
	}

	function choiceItems(field) {
		return (field.options || []).map(function (opt) {
			if (opt && typeof opt === 'object') {
				return { id: String(opt.id || opt.key || ''), label: String(opt.label || opt.id || opt.key || '') };
			}
			return { id: String(opt), label: String(opt) };
		}).filter(function (opt) {
			return opt.id !== '';
		});
	}

	function defaultOp(field) {
		var ops = operatorsFor(field);
		if (field && (field.type === 'multiselect' || field.list)) {
			var i;
			for (i = 0; i < ops.length; i++) {
				if (ops[i].id === 'in') {
					return 'in';
				}
			}
		}
		return ops[0] ? ops[0].id : 'eq';
	}

	function optionList(items, selected, includeBlank, blankLabel) {
		var html = '';
		if (includeBlank) {
			html += '<option value="">' + $('<div/>').text(blankLabel || t('none', 'None')).html() + '</option>';
		}
		(items || []).forEach(function (item) {
			var id = typeof item === 'string' ? item : item.id;
			var label = typeof item === 'string' ? item : item.label;
			html += '<option value="' + $('<div/>').text(id).html() + '"' + (id === selected ? ' selected' : '') + '>' + $('<div/>').text(label).html() + '</option>';
		});
		return html;
	}

	function fieldOptions(selected, includeBlank) {
		return optionList(fields().map(function (f) {
			return { id: f.id, label: f.label };
		}), selected, includeBlank);
	}

	function labeled(label, $control) {
		var $wrap = $('<label class="remember-reports-field"/>');
		$wrap.append($('<span class="remember-reports-label"/>').text(label));
		$wrap.append($control);
		return $wrap;
	}

	function renderSaved(list) {
		var $ul = $('#remember-saved-reports');
		$ul.empty();
		if (!list || !list.length) {
			$ul.append($('<li class="remember-reports-nav-empty"/>').text(t('noneSaved', 'No saved reports yet.')));
			return;
		}
		list = list.slice().sort(function (a, b) {
			return String(a.name || '').localeCompare(String(b.name || ''), undefined, { sensitivity: 'base' });
		});
		list.forEach(function (row) {
			var $li = $('<li/>');
			var $btn = $('<button type="button" class="button-link remember-saved-report"/>');
			$btn.attr('data-id', row.report_id);
			$btn.text(row.name || t('unnamed', 'Untitled report'));
			if (parseInt(row.report_id, 10) === state.reportId) {
				$btn.addClass('is-active');
			}
			$li.append($btn);
			$ul.append($li);
		});
	}

	function loadSavedList() {
		return post('remember_report_list').done(function (res) {
			if (res && res.success) {
				renderSaved(res.data.reports || []);
			}
		});
	}

	function renderColumns() {
		var $groups = $('#remember-report-column-groups');
		var grouped = {};
		var order = [];
		fields().forEach(function (f) {
			if (!grouped[f.group]) {
				grouped[f.group] = [];
				order.push(f.group);
			}
			grouped[f.group].push(f);
		});
		$groups.empty();
		order.forEach(function (group) {
			var $cluster = $('<div class="remember-reports-cluster"/>');
			$cluster.append($('<h4/>').text(group));
			var $grid = $('<div class="remember-reports-checks"/>');
			grouped[group].forEach(function (f) {
				var $lab = $('<label/>');
				var $cb = $('<input type="checkbox" class="remember-report-col"/>');
				$cb.val(f.id);
				$cb.prop('checked', state.columns.indexOf(f.id) !== -1);
				$lab.append($cb).append($('<span/>').text(f.label));
				$grid.append($lab);
			});
			$cluster.append($grid);
			$groups.append($cluster);
		});
		renderColumnOrder();
	}

	function renderColumnOrder() {
		var $ol = $('#remember-report-column-order');
		$ol.empty();
		state.columns.forEach(function (id, index) {
			var field = fieldById(id);
			if (!field) {
				return;
			}
			var $li = $('<li/>');
			$li.append($('<span/>').text(field.label));
			var $up = $('<button type="button" class="button-link remember-col-up"/>').text(t('up', 'Move up')).attr('data-index', index);
			var $down = $('<button type="button" class="button-link remember-col-down"/>').text(t('down', 'Move down')).attr('data-index', index);
			var $rm = $('<button type="button" class="button-link remember-col-remove"/>').text(t('remove', 'Remove')).attr('data-index', index);
			$li.append($up, $down, $rm);
			$ol.append($li);
		});
	}

	function renderMode() {
		var summary = state.mode === 'summary';
		$('#remember-report-columns-wrap').toggle(!summary);
		$('#remember-report-summary-wrap').prop('hidden', !summary);
		$('input[name="remember_report_mode"][value="' + state.mode + '"]').prop('checked', true);
		if (summary && !state.aggregations.length) {
			state.aggregations = [{ fn: 'count', field: '*' }];
		}
		renderGroups();
		renderAggs();
		renderSort();
	}

	function renderGroups() {
		var $box = $('#remember-report-groups');
		$box.empty();
		state.group_by.forEach(function (id, index) {
			var $row = $('<div class="remember-reports-criteria"/>');
			var $sel = $('<select class="remember-group-field"/>').attr('data-index', index);
			$sel.html(fieldOptions(id, false));
			var $rm = $('<button type="button" class="button-link remember-group-remove"/>').text(t('remove', 'Remove')).attr('data-index', index);
			$row.append(labeled(t('fieldLabel', 'Field'), $sel), $rm);
			$box.append($row);
		});
	}

	function renderAggs() {
		var $box = $('#remember-report-aggs');
		$box.empty();
		var fns = catalog.aggregations || [];
		state.aggregations.forEach(function (agg, index) {
			var $row = $('<div class="remember-reports-criteria"/>');
			var $fn = $('<select class="remember-agg-fn"/>').attr('data-index', index);
			$fn.html(optionList(fns, agg.fn, false));
			var $field = $('<select class="remember-agg-field"/>').attr('data-index', index);
			var fieldItems = [{ id: '*', label: t('countStar', 'Rows') }].concat(fields().map(function (f) {
				return { id: f.id, label: f.label };
			}));
			if (agg.fn === 'sum') {
				fieldItems = fields().filter(function (f) {
					return f.measure;
				}).map(function (f) {
					return { id: f.id, label: f.label };
				});
			} else if (agg.fn === 'count') {
				fieldItems = [{ id: '*', label: t('countStar', 'Rows') }];
			}
			$field.html(optionList(fieldItems, agg.field || '*', false));
			var $rm = $('<button type="button" class="button-link remember-agg-remove"/>').text(t('remove', 'Remove')).attr('data-index', index);
			$row.append(
				labeled(t('calcLabel', 'Calculation'), $fn),
				labeled(t('fieldLabel', 'Field'), $field),
				$rm
			);
			$box.append($row);
		});
	}

	function pinnedQuestionLabel(ignoreIndex) {
		var found = '';
		var i;
		for (i = 0; i < state.filters.length; i++) {
			if (i === ignoreIndex) {
				continue;
			}
			var filter = state.filters[i];
			if (!filter || filter.field !== 'question.label') {
				continue;
			}
			if (filter.op !== 'eq' && filter.op !== 'in') {
				continue;
			}
			var values = Array.isArray(filter.value) ? filter.value.slice() : String(filter.value || '').split(',');
			values = values.map(function (value) {
				return String(value);
			}).filter(function (value) {
				return value !== '';
			});
			if (values.length !== 1) {
				continue;
			}
			if (found && found !== values[0]) {
				return '';
			}
			found = values[0];
		}
		return found;
	}

	function shapeForFilter(field, index) {
		if (!field || field.id !== 'answer.value' || !field.questions || !field.questions.length) {
			return field;
		}
		var label = pinnedQuestionLabel(index);
		if (!label) {
			return field;
		}
		var match = null;
		field.questions.forEach(function (question) {
			if (question.label === label) {
				match = question;
			}
		});
		if (!match || (match.type !== 'enum' && match.type !== 'multiselect')) {
			return field;
		}
		return {
			id: field.id,
			label: field.label,
			group: field.group,
			type: match.type,
			options: match.options || [],
			list: match.type === 'multiselect',
			measure: false
		};
	}

	function renderFilters() {
		var $box = $('#remember-report-filters');
		$box.empty();
		state.filters.forEach(function (filter, index) {
			var field = shapeForFilter(fieldById(filter.field) || fields()[0], index);
			if (!field) {
				return;
			}
			if (!filter.field) {
				filter.field = field.id;
			}
			var ops = operatorsFor(field);
			if (!filter.op || !ops.some(function (op) { return op.id === filter.op; })) {
				filter.op = defaultOp(field);
			}
			var $row = $('<div class="remember-reports-criteria remember-filter-row"/>');
			var $field = $('<select class="remember-filter-field"/>').attr('data-index', index);
			$field.html(fieldOptions(filter.field, false));
			var $op = $('<select class="remember-filter-op"/>').attr('data-index', index);
			$op.html(optionList(ops, filter.op, false));
			$row.append(
				labeled(t('fieldLabel', 'Field'), $field),
				labeled(t('operatorLabel', 'Operator'), $op),
				labeled(t('valueLabel', 'Value'), valueControl(filter, field, index)),
				$('<button type="button" class="button-link remember-filter-remove"/>').text(t('remove', 'Remove')).attr('data-index', index)
			);
			$box.append($row);
		});
	}

	function valueControl(filter, field, index) {
		var op = filter.op;
		var $wrap = $('<span class="remember-filter-value"/>');
		if (op === 'empty' || op === 'not_empty') {
			return $wrap;
		}
		if (op === 'between') {
			var $from = $('<input type="text" class="remember-filter-from"/>').attr('data-index', index).val(filter.value_from || '');
			var $to = $('<input type="text" class="remember-filter-to"/>').attr('data-index', index).val(filter.value_to || '');
			if (field.type === 'date') {
				$from.attr('type', 'date');
				$to.attr('type', 'date');
			} else if (field.type === 'datetime') {
				$from.attr('type', 'datetime-local');
				$to.attr('type', 'datetime-local');
			} else if (field.type === 'number') {
				$from.attr('type', 'number');
				$to.attr('type', 'number');
			}
			$wrap.append($from, $('<span/>').text(' – '), $to);
			return $wrap;
		}
		if (hasChoices(field)) {
			var $sel = $('<select class="remember-filter-val"/>').attr('data-index', index);
			if (op === 'in' || op === 'not_in') {
				$sel.attr('multiple', 'multiple');
				$sel.attr('size', Math.min(8, Math.max(3, field.options.length)));
			}
			$sel.html(optionList(choiceItems(field), null, op !== 'in' && op !== 'not_in', t('selectValue', 'Select value')));
			if (op === 'in' || op === 'not_in') {
				var selected = Array.isArray(filter.value) ? filter.value : String(filter.value || '').split(',');
				$sel.val(selected);
			} else {
				$sel.val(filter.value || '');
			}
			$wrap.append($sel);
			return $wrap;
		}
		var $input = $('<input type="text" class="remember-filter-val"/>').attr('data-index', index).val(filter.value || '');
		if (field.type === 'date') {
			$input.attr('type', 'date');
		} else if (field.type === 'datetime') {
			$input.attr('type', 'datetime-local');
		} else if (field.type === 'number') {
			$input.attr('type', 'number');
		}
		$wrap.append($input);
		return $wrap;
	}

	function renderSort() {
		var choices = state.mode === 'summary' ? state.group_by.slice() : state.columns.slice();
		var items = choices.map(function (id) {
			var f = fieldById(id);
			return f ? { id: f.id, label: f.label } : null;
		}).filter(Boolean);
		if (!items.length) {
			items = fields().map(function (f) {
				return { id: f.id, label: f.label };
			});
		}
		$('#remember-report-sort-field').html(optionList(items, state.sort.field, true));
		$('#remember-report-sort-dir').val(state.sort.dir === 'desc' ? 'desc' : 'asc');
	}

	function renderSubjectSelect() {
		var $sel = $('#remember-report-subject');
		$sel.html(optionList((catalog.subjects || []).map(function (s) {
			return { id: s.id, label: s.label };
		}), state.subject, false));
	}

	function selectedEventId() {
		return parseInt($('#remember-report-event').val(), 10) || 0;
	}

	function renderEventSelect() {
		var items = (catalog.events || []).map(function (event) {
			return { id: String(event.id), label: event.label };
		});
		$('#remember-report-event').html(optionList(items, eventId ? String(eventId) : '', true, t('allEvents', 'All events')));
		updateEventHint();
	}

	function updateEventHint() {
		var id = selectedEventId();
		var sub = state.subject;
		var msg = t('eventHintAll', 'Saved reports stay global. Choose an event to limit this run; it is not saved with the report.');
		if (id) {
			if (sub === 'applications') {
				msg = t('eventHintApps', 'This run is limited to applications for the selected event. The saved report stays global.');
			} else if (sub === 'payments') {
				msg = t('eventHintPay', 'This run is limited to payments for the selected event. The saved report stays global.');
			} else if (sub === 'events') {
				msg = t('eventHintEvents', 'This run is limited to the selected event. The saved report stays global.');
			} else if (sub === 'surveys') {
				msg = t('eventHintSurveys', 'This run is limited to survey answers for the selected event. The saved report stays global.');
			} else {
				msg = t('eventHintMembers', 'This run is limited to accepted participants of the selected event. The saved report stays global.');
			}
		}
		$('#remember-report-event-hint').text(msg);
	}

	function hideCopyPanel() {
		$('#remember-report-copy-panel').prop('hidden', true);
		$('#remember-report-copy-user').empty();
		$('#remember-report-copy-confirm').prop('disabled', false);
	}

	function hideSchedulePanel() {
		$('#remember-report-schedule-panel').prop('hidden', true);
	}

	function scheduleFrequencyFields() {
		var frequency = $('#remember-report-schedule-frequency').val();
		$('#remember-report-schedule-weekday-wrap').prop('hidden', frequency !== 'weekly');
		$('#remember-report-schedule-monthday-wrap').prop('hidden', frequency !== 'monthly');
	}

	function fillSchedule(data) {
		var schedule = data.schedule || {};
		var note = t('scheduleNote', 'Times use the site timezone (%s). The CSV uses your access. Each person is checked again when it sends.');
		$('#remember-report-schedule-note').text(note.replace('%s', data.timezone || ''));
		$('#remember-report-schedule-enabled').prop('checked', !!parseInt(schedule.enabled, 10));
		$('#remember-report-schedule-frequency').val(schedule.frequency || 'weekly');
		$('#remember-report-schedule-weekday').val(String(schedule.weekday || 1));
		$('#remember-report-schedule-monthday').val(String(schedule.monthday || 1));
		$('#remember-report-schedule-time').val(schedule.send_time || '08:00');
		$('#remember-report-schedule-skip').prop('checked', schedule.skip_empty === undefined || !!parseInt(schedule.skip_empty, 10));
		$('#remember-report-schedule-sensitive').prop('checked', !!parseInt(schedule.sensitive_opt_in, 10));
		$('#remember-report-schedule-sensitive-wrap').prop('hidden', !data.sensitive);
		var events = (catalog && catalog.events) ? catalog.events : [];
		var $event = $('#remember-report-schedule-event');
		$event.html(optionList(events.map(function (event) {
			return { id: String(event.id), label: event.label };
		}), schedule.event_id ? String(schedule.event_id) : '', true, t('allEvents', 'All events')));
		var chosen = {};
		(schedule.recipient_ids || []).forEach(function (id) {
			chosen[String(id)] = true;
		});
		var $people = $('#remember-report-schedule-recipients');
		$people.empty();
		(data.recipients || []).forEach(function (person) {
			var $opt = $('<option></option>').val(String(person.id)).text(person.label);
			if (chosen[String(person.id)]) {
				$opt.prop('selected', true);
			}
			$people.append($opt);
		});
		if (schedule.last_sent_at) {
			$('#remember-report-schedule-last').text(t('lastSent', 'Last sent %s.').replace('%s', schedule.last_sent_at));
		} else {
			$('#remember-report-schedule-last').text(t('notSentYet', 'Not sent yet.'));
		}
		scheduleFrequencyFields();
		$('#remember-report-schedule-panel').prop('hidden', false);
	}

	function setSavedActions() {
		var saved = !!state.reportId;
		$('#remember-report-delete, #remember-report-copy, #remember-report-schedule').prop('disabled', !saved);
		if (!saved) {
			hideCopyPanel();
			hideSchedulePanel();
		}
	}

	function renderBuilder() {
		$('#remember-report-name').val(state.name);
		setSavedActions();
		renderSubjectSelect();
		renderEventSelect();
		renderColumns();
		renderFilters();
		renderMode();
		renderSort();
	}

	function resetNew() {
		state = emptyState();
		if (catalog && catalog.subjects && catalog.subjects.length) {
			state.subject = catalog.subjects[0].id;
			applyDefaults();
		}
		page = 1;
		clearResults();
		renderBuilder();
		loadSavedList();
		notice('');
	}

	function clearResults() {
		$('#remember-report-table thead, #remember-report-table tbody').empty();
		$('#remember-report-empty').prop('hidden', false);
		$('#remember-report-pager').prop('hidden', true);
		$('#remember-report-meta').text('');
		lastTotal = 0;
		lastPages = 1;
	}

	function readFiltersFromDom() {
		state.filters.forEach(function (filter, index) {
			var $row = $('#remember-report-filters .remember-filter-row').eq(index);
			filter.field = $row.find('.remember-filter-field').val() || filter.field;
			filter.op = $row.find('.remember-filter-op').val() || filter.op;
			filter.value_from = $row.find('.remember-filter-from').val() || '';
			filter.value_to = $row.find('.remember-filter-to').val() || '';
			var $val = $row.find('.remember-filter-val');
			if ($val.attr('multiple')) {
				filter.value = $val.val() || [];
			} else {
				filter.value = $val.val() || '';
			}
		});
		state.name = $('#remember-report-name').val() || '';
		state.sort.field = $('#remember-report-sort-field').val() || '';
		state.sort.dir = $('#remember-report-sort-dir').val() || 'asc';
	}

	function runReport(nextPage) {
		readFiltersFromDom();
		page = nextPage || 1;
		var $btn = $('.remember-report-run');
		$btn.prop('disabled', true).text(t('running', 'Running…'));
		notice('');
		post('remember_report_run', {
			definition: JSON.stringify(definition()),
			page: page,
			event_id: selectedEventId()
		}).done(function (res) {
			if (!res || !res.success) {
				notice((res && res.data && res.data.message) || t('error', 'Could not run that report.'), 'error');
				clearResults();
				return;
			}
			drawResults(res.data);
		}).fail(function (xhr) {
			var msg = t('error', 'Could not run that report.');
			if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
				msg = xhr.responseJSON.data.message;
			}
			notice(msg, 'error');
			clearResults();
		}).always(function () {
			$btn.prop('disabled', false).text(t('run', 'Run'));
		});
	}

	function drawResults(data) {
		var $thead = $('#remember-report-table thead').empty();
		var $tbody = $('#remember-report-table tbody').empty();
		var $tr = $('<tr/>');
		(data.columns || []).forEach(function (col) {
			$tr.append($('<th/>').text(col.label));
		});
		$thead.append($tr);
		(data.rows || []).forEach(function (row) {
			var $r = $('<tr/>');
			(data.columns || []).forEach(function (col) {
				$r.append($('<td/>').text(row[col.id] || ''));
			});
			$tbody.append($r);
		});
		lastTotal = parseInt(data.total, 10) || 0;
		lastPages = parseInt(data.pages, 10) || 1;
		page = parseInt(data.page, 10) || 1;
		$('#remember-report-empty').prop('hidden', lastTotal > 0);
		if (!lastTotal) {
			$('#remember-report-empty').text(t('noRows', 'No rows.')).prop('hidden', false);
		}
		var meta = lastTotal + ' ' + t('rowsLabel', 'rows');
		if (data.event_label) {
			meta += ' · ' + data.event_label;
		}
		$('#remember-report-meta').text(meta);
		$('#remember-report-pager').prop('hidden', lastPages <= 1);
		$('#remember-report-page-label').text(t('page', 'Page') + ' ' + page + ' ' + t('of', 'of') + ' ' + lastPages);
		$('#remember-report-prev').prop('disabled', page <= 1);
		$('#remember-report-next').prop('disabled', page >= lastPages);
	}

	function saveReport(asNew) {
		readFiltersFromDom();
		var name = state.name || '';
		if (asNew) {
			name = window.prompt(t('needName', 'Name this report.'), name || t('unnamed', 'Untitled report')) || '';
		} else if (!state.reportId && !name) {
			name = window.prompt(t('needName', 'Name this report.'), t('unnamed', 'Untitled report')) || '';
		}
		name = $.trim(name);
		if (!name) {
			notice(t('needName', 'Name this report.'), 'error');
			return;
		}
		state.name = name;
		$('#remember-report-name').val(name);
		post('remember_report_save', {
			name: name,
			report_id: asNew ? 0 : state.reportId,
			definition: JSON.stringify(definition())
		}).done(function (res) {
			if (!res || !res.success) {
				notice((res && res.data && res.data.message) || t('error', 'Could not run that report.'), 'error');
				return;
			}
			state.reportId = parseInt(res.data.report_id, 10) || 0;
			setSavedActions();
			notice(t('saved', 'Saved.'));
			loadSavedList();
		}).fail(function (xhr) {
			var msg = t('error', 'Could not run that report.');
			if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
				msg = xhr.responseJSON.data.message;
			}
			notice(msg, 'error');
		});
	}

	function loadReport(id) {
		hideCopyPanel();
		hideSchedulePanel();
		post('remember_report_get', { report_id: id }).done(function (res) {
			if (!res || !res.success) {
				notice((res && res.data && res.data.message) || t('error', 'Could not run that report.'), 'error');
				return;
			}
			loadingSaved = true;
			state = emptyState();
			state.reportId = parseInt(res.data.report_id, 10) || 0;
			state.name = res.data.name || '';
			var def = res.data.definition || {};
			state.subject = def.subject || res.data.subject || '';
			state.mode = def.mode === 'summary' ? 'summary' : 'detail';
			state.columns = Array.isArray(def.columns) ? def.columns.slice() : [];
			state.filters = Array.isArray(def.filters) ? def.filters.slice() : [];
			state.group_by = Array.isArray(def.group_by) ? def.group_by.slice() : [];
			state.aggregations = Array.isArray(def.aggregations) ? def.aggregations.slice() : [];
			state.sort = def.sort && typeof def.sort === 'object' ? def.sort : { field: '', dir: 'asc' };
			if (!state.subject && catalog.subjects[0]) {
				state.subject = catalog.subjects[0].id;
			}
			if (state.mode === 'detail' && !state.columns.length) {
				var sub = currentSubject();
				state.columns = sub && sub.default ? sub.default.slice() : [];
			}
			renderBuilder();
			loadingSaved = false;
			clearResults();
			loadSavedList();
			runReport(1);
		});
	}

	function bind() {
		$('#remember-report-new').on('click', function () {
			resetNew();
		});
		$('#remember-report-subject').on('change', function () {
			if (loadingSaved) {
				return;
			}
			state.subject = $(this).val();
			applyDefaults();
			renderBuilder();
			clearResults();
		});
		$('#remember-report-event').on('change', function () {
			eventId = selectedEventId();
			updateEventHint();
		});
		$('input[name="remember_report_mode"]').on('change', function () {
			state.mode = $(this).val() === 'summary' ? 'summary' : 'detail';
			renderMode();
		});
		$('#remember-report-column-groups').on('change', '.remember-report-col', function () {
			var id = $(this).val();
			var idx = state.columns.indexOf(id);
			if (this.checked && idx === -1) {
				state.columns.push(id);
			} else if (!this.checked && idx !== -1) {
				state.columns.splice(idx, 1);
			}
			renderColumnOrder();
			renderSort();
		});
		$('#remember-report-column-order').on('click', '.remember-col-up', function () {
			var i = parseInt($(this).attr('data-index'), 10);
			if (i > 0) {
				var tmp = state.columns[i - 1];
				state.columns[i - 1] = state.columns[i];
				state.columns[i] = tmp;
				renderColumns();
				renderSort();
			}
		});
		$('#remember-report-column-order').on('click', '.remember-col-down', function () {
			var i = parseInt($(this).attr('data-index'), 10);
			if (i < state.columns.length - 1) {
				var tmp = state.columns[i + 1];
				state.columns[i + 1] = state.columns[i];
				state.columns[i] = tmp;
				renderColumns();
				renderSort();
			}
		});
		$('#remember-report-column-order').on('click', '.remember-col-remove', function () {
			var i = parseInt($(this).attr('data-index'), 10);
			state.columns.splice(i, 1);
			renderColumns();
			renderSort();
		});
		$('#remember-report-add-filter').on('click', function () {
			var first = fields()[0];
			if (!first) {
				return;
			}
			readFiltersFromDom();
			state.filters.push({ field: first.id, op: defaultOp(first), value: '', value_from: '', value_to: '' });
			renderFilters();
		});
		$('#remember-report-filters').on('click', '.remember-filter-remove', function () {
			readFiltersFromDom();
			state.filters.splice(parseInt($(this).attr('data-index'), 10), 1);
			renderFilters();
		});
		$('#remember-report-filters').on('change', '.remember-filter-field, .remember-filter-op', function () {
			readFiltersFromDom();
			renderFilters();
		});
		$('#remember-report-filters').on('change', '.remember-filter-val', function () {
			var index = parseInt($(this).attr('data-index'), 10);
			var filter = state.filters[index];
			if (!filter || filter.field !== 'question.label') {
				return;
			}
			readFiltersFromDom();
			renderFilters();
		});
		$('#remember-report-add-group').on('click', function () {
			var first = fields()[0];
			if (!first) {
				return;
			}
			state.group_by.push(first.id);
			renderGroups();
			renderSort();
		});
		$('#remember-report-groups').on('change', '.remember-group-field', function () {
			var i = parseInt($(this).attr('data-index'), 10);
			state.group_by[i] = $(this).val();
			renderSort();
		});
		$('#remember-report-groups').on('click', '.remember-group-remove', function () {
			state.group_by.splice(parseInt($(this).attr('data-index'), 10), 1);
			renderGroups();
			renderSort();
		});
		$('#remember-report-add-agg').on('click', function () {
			state.aggregations.push({ fn: 'count', field: '*' });
			renderAggs();
		});
		$('#remember-report-aggs').on('change', '.remember-agg-fn', function () {
			var i = parseInt($(this).attr('data-index'), 10);
			state.aggregations[i].fn = $(this).val();
			if (state.aggregations[i].fn === 'count') {
				state.aggregations[i].field = '*';
			}
			renderAggs();
		});
		$('#remember-report-aggs').on('change', '.remember-agg-field', function () {
			var i = parseInt($(this).attr('data-index'), 10);
			state.aggregations[i].field = $(this).val();
		});
		$('#remember-report-aggs').on('click', '.remember-agg-remove', function () {
			state.aggregations.splice(parseInt($(this).attr('data-index'), 10), 1);
			renderAggs();
		});
		$('.remember-report-run').on('click', function () {
			runReport(1);
		});
		$('#remember-report-prev').on('click', function () {
			if (page > 1) {
				runReport(page - 1);
			}
		});
		$('#remember-report-next').on('click', function () {
			if (page < lastPages) {
				runReport(page + 1);
			}
		});
		$('#remember-report-save').on('click', function () {
			saveReport(false);
		});
		$('#remember-report-save-as').on('click', function () {
			saveReport(true);
		});
		$('#remember-report-copy').on('click', function () {
			if (!state.reportId) {
				notice(t('copyNeedSave', 'Save this report before copying it.'), 'error');
				return;
			}
			var $panel = $('#remember-report-copy-panel');
			var $select = $('#remember-report-copy-user');
			$panel.prop('hidden', false);
			$select.empty().append($('<option></option>').val('').text(t('loadingRecipients', 'Loading…')));
			$('#remember-report-copy-confirm').prop('disabled', true);
			post('remember_report_recipients', { report_id: state.reportId }).done(function (res) {
				$select.empty();
				if (!res || !res.success) {
					notice((res && res.data && res.data.message) || t('error', 'Could not run that report.'), 'error');
					hideCopyPanel();
					return;
				}
				var list = (res.data && res.data.recipients) ? res.data.recipients : [];
				if (!list.length) {
					notice(t('noRecipients', 'No one else can receive this report. They need View Reports plus the read access this subject uses.'), 'error');
					hideCopyPanel();
					return;
				}
				$select.append($('<option></option>').val('').text(t('copyNeedUser', 'Choose someone to copy this report to.')));
				list.forEach(function (person) {
					$select.append($('<option></option>').val(String(person.id)).text(person.label));
				});
				$('#remember-report-copy-confirm').prop('disabled', false);
			}).fail(function (xhr) {
				var msg = t('error', 'Could not run that report.');
				if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
					msg = xhr.responseJSON.data.message;
				}
				notice(msg, 'error');
				hideCopyPanel();
			});
		});
		$('#remember-report-copy-cancel').on('click', function () {
			hideCopyPanel();
		});
		$('#remember-report-schedule').on('click', function () {
			if (!state.reportId) {
				notice(t('scheduleNeedSave', 'Save this report before scheduling it.'), 'error');
				return;
			}
			post('remember_report_schedule_get', { report_id: state.reportId }).done(function (res) {
				if (!res || !res.success) {
					notice((res && res.data && res.data.message) || t('error', 'Could not run that report.'), 'error');
					return;
				}
				fillSchedule(res.data || {});
			}).fail(function (xhr) {
				var msg = t('error', 'Could not run that report.');
				if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
					msg = xhr.responseJSON.data.message;
				}
				notice(msg, 'error');
			});
		});
		$('#remember-report-schedule-cancel').on('click', function () {
			hideSchedulePanel();
		});
		$('#remember-report-schedule-frequency').on('change', scheduleFrequencyFields);
		$('#remember-report-schedule-save').on('click', function () {
			if (!state.reportId) {
				notice(t('scheduleNeedSave', 'Save this report before scheduling it.'), 'error');
				return;
			}
			var recipients = $('#remember-report-schedule-recipients').val() || [];
			$('#remember-report-schedule-save').prop('disabled', true);
			post('remember_report_schedule_save', {
				report_id: state.reportId,
				enabled: $('#remember-report-schedule-enabled').is(':checked') ? 1 : 0,
				frequency: $('#remember-report-schedule-frequency').val(),
				weekday: $('#remember-report-schedule-weekday').val(),
				monthday: $('#remember-report-schedule-monthday').val(),
				send_time: $('#remember-report-schedule-time').val(),
				event_id: $('#remember-report-schedule-event').val() || 0,
				skip_empty: $('#remember-report-schedule-skip').is(':checked') ? 1 : 0,
				sensitive_opt_in: $('#remember-report-schedule-sensitive').is(':checked') ? 1 : 0,
				recipient_ids: recipients
			}).done(function (res) {
				$('#remember-report-schedule-save').prop('disabled', false);
				if (!res || !res.success) {
					notice((res && res.data && res.data.message) || t('error', 'Could not run that report.'), 'error');
					return;
				}
				notice(t('scheduleSaved', 'Schedule saved.'));
				if (res.data && res.data.schedule && res.data.schedule.last_sent_at) {
					$('#remember-report-schedule-last').text(t('lastSent', 'Last sent %s.').replace('%s', res.data.schedule.last_sent_at));
				}
			}).fail(function (xhr) {
				$('#remember-report-schedule-save').prop('disabled', false);
				var msg = t('error', 'Could not run that report.');
				if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
					msg = xhr.responseJSON.data.message;
				}
				notice(msg, 'error');
			});
		});
		$('#remember-report-copy-confirm').on('click', function () {
			if (!state.reportId) {
				notice(t('copyNeedSave', 'Save this report before copying it.'), 'error');
				return;
			}
			var userId = parseInt($('#remember-report-copy-user').val(), 10) || 0;
			if (!userId) {
				notice(t('copyNeedUser', 'Choose someone to copy this report to.'), 'error');
				return;
			}
			$('#remember-report-copy-confirm').prop('disabled', true);
			post('remember_report_copy', { report_id: state.reportId, user_id: userId }).done(function (res) {
				$('#remember-report-copy-confirm').prop('disabled', false);
				if (!res || !res.success) {
					notice((res && res.data && res.data.message) || t('error', 'Could not run that report.'), 'error');
					return;
				}
				hideCopyPanel();
				notice((res.data && res.data.message) || t('saved', 'Saved.'));
			}).fail(function (xhr) {
				$('#remember-report-copy-confirm').prop('disabled', false);
				var msg = t('error', 'Could not run that report.');
				if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
					msg = xhr.responseJSON.data.message;
				}
				notice(msg, 'error');
			});
		});
		$('#remember-report-delete').on('click', function () {
			if (!state.reportId) {
				return;
			}
			if (!window.confirm(t('confirmDel', 'Delete this saved report?'))) {
				return;
			}
			post('remember_report_delete', { report_id: state.reportId }).done(function (res) {
				if (!res || !res.success) {
					notice((res && res.data && res.data.message) || t('error', 'Could not run that report.'), 'error');
					return;
				}
				notice(t('deleted', 'Deleted.'));
				resetNew();
			});
		});
		$('#remember-report-export').on('click', function () {
			readFiltersFromDom();
			var $form = $('#remember-report-export-form');
			$form.find('[name="nonce"]').val(cfg.nonce);
			$form.find('[name="definition"]').val(JSON.stringify(definition()));
			$form.find('[name="event_id"]').val(String(selectedEventId() || ''));
			$form.trigger('submit');
		});
		$('#remember-saved-reports').on('click', '.remember-saved-report', function () {
			loadReport($(this).attr('data-id'));
		});
		$('#remember-report-name').on('change', function () {
			state.name = $(this).val();
		});
	}

	$(function () {
		if (!cfg.ajaxurl) {
			return;
		}
		bind();
		clearResults();
		post('remember_report_catalog').done(function (res) {
			if (!res || !res.success) {
				notice(t('error', 'Could not run that report.'), 'error');
				return;
			}
			catalog = res.data;
			if (!catalog.subjects || !catalog.subjects.length) {
				notice(t('noSubjects', 'No report subjects are available for your role.'), 'error');
				return;
			}
			resetNew();
		}).fail(function () {
			notice(t('error', 'Could not run that report.'), 'error');
		});
	});
})(jQuery);
