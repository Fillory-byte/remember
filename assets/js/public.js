/**
 * All of the JavaScript for your public-facing functionality should be
 * included in this file.
 */

(function($) {
	'use strict';

	/**
	 * Keep the "Display name publicly as" nickname option in sync while typing
	 * (same idea as WordPress core user profile).
	 */
	function initDisplayNameNicknameSync() {
		var $nickname = $('#nickname');
		var $display = $('#display_name');
		if (!$nickname.length || !$display.length) {
			return;
		}

		var previousNickname = $.trim($nickname.val());

		function syncNicknameOption() {
			var nextNickname = $.trim($nickname.val());
			if (!nextNickname) {
				return;
			}

			var $options = $display.find('option');
			var $match = $options.filter(function() {
				return $(this).val() === previousNickname;
			}).first();

			if ($match.length) {
				$match.val(nextNickname).text(nextNickname);
			} else {
				var exists = $options.filter(function() {
					return $(this).val() === nextNickname;
				}).length > 0;
				if (!exists) {
					$display.prepend($('<option></option>').val(nextNickname).text(nextNickname));
				}
			}

			if ($display.val() === previousNickname || !$display.val()) {
				$display.val(nextNickname);
			}

			previousNickname = nextNickname;
		}

		$nickname.on('input change', syncNicknameOption);
	}

	/**
	 * Circular photo preview with drag-to-recenter and zoom.
	 * On form submit, crops the framed square into the file input.
	 * Works for profile edit and member registration (same markup class).
	 */
	function initOnePhotoCropper($wrap) {
		var $form = $wrap.closest('form');
		var $file = $wrap.find('input[type="file"][name="photo_file"]');
		var $current = $wrap.find('.remember-profile-photo-current');
		var $cropper = $wrap.find('.remember-profile-photo-cropper');
		var $viewport = $wrap.find('.remember-profile-photo-cropper-viewport');
		var $img = $wrap.find('.remember-profile-photo-cropper-image');
		var $zoomRange = $wrap.find('.remember-photo-zoom-range');
		var $zoomIn = $wrap.find('.remember-photo-zoom-in');
		var $zoomOut = $wrap.find('.remember-photo-zoom-out');
		var $clear = $wrap.find('.remember-photo-clear');
		var outputSize = parseInt($wrap.data('output-size'), 10) || 800;

		if (!$file.length || !$cropper.length || !$viewport.length || !$img.length) {
			return;
		}

		var objectUrl = null;
		var naturalW = 0;
		var naturalH = 0;
		var zoom = 1;
		var tx = 0;
		var ty = 0;
		var dragging = false;
		var dragStartX = 0;
		var dragStartY = 0;
		var dragOriginTx = 0;
		var dragOriginTy = 0;
		var ready = false;

		function viewportSize() {
			return $viewport.innerWidth() || 200;
		}

		function baseScale() {
			var v = viewportSize();
			if (!naturalW || !naturalH) {
				return 1;
			}
			return v / Math.min(naturalW, naturalH);
		}

		function maxOffset() {
			var v = viewportSize();
			var scale = baseScale() * zoom;
			return {
				x: Math.max(0, (naturalW * scale - v) / 2),
				y: Math.max(0, (naturalH * scale - v) / 2)
			};
		}

		function clampOffsets() {
			var max = maxOffset();
			tx = Math.max(-max.x, Math.min(max.x, tx));
			ty = Math.max(-max.y, Math.min(max.y, ty));
		}

		function applyTransform() {
			clampOffsets();
			var v = viewportSize();
			var scale = baseScale() * zoom;
			var width = naturalW * scale;
			var height = naturalH * scale;
			var left = (v / 2) - (width / 2) + tx;
			var top = (v / 2) - (height / 2) + ty;
			var el = $img[0];
			// left/top — not transform. iOS Safari clips transform children
			// incorrectly when the parent has overflow:hidden and border-radius.
			el.style.setProperty('width', width + 'px', 'important');
			el.style.setProperty('height', height + 'px', 'important');
			el.style.setProperty('max-width', 'none', 'important');
			el.style.setProperty('max-height', 'none', 'important');
			el.style.setProperty('left', left + 'px', 'important');
			el.style.setProperty('top', top + 'px', 'important');
			el.style.setProperty('transform', 'none', 'important');
		}

		function setZoom(next) {
			zoom = Math.max(1, Math.min(3, next));
			$zoomRange.val(zoom.toFixed(2));
			applyTransform();
		}

		function revokeObjectUrl() {
			if (objectUrl) {
				URL.revokeObjectURL(objectUrl);
				objectUrl = null;
			}
		}

		function hideCropper() {
			ready = false;
			revokeObjectUrl();
			$img.attr('src', '');
			$img.removeAttr('style');
			$cropper.prop('hidden', true);
			if ($current.length && $current.children().length) {
				$current.prop('hidden', false);
			}
		}

		function showCropper(file) {
			if (!file || !file.type || file.type.indexOf('image/') !== 0) {
				hideCropper();
				return;
			}

			revokeObjectUrl();
			objectUrl = URL.createObjectURL(file);
			ready = false;
			zoom = 1;
			tx = 0;
			ty = 0;
			$zoomRange.val('1');

			function layoutPreview() {
				if (!naturalW || !naturalH) {
					return;
				}
				ready = true;
				if ($current.length) {
					$current.prop('hidden', true);
				}
				$cropper.prop('hidden', false);
				window.requestAnimationFrame(function() {
					window.requestAnimationFrame(applyTransform);
				});
			}

			function onDecoded(img) {
				naturalW = img.naturalWidth || img.width;
				naturalH = img.naturalHeight || img.height;
				if (!naturalW || !naturalH) {
					hideCropper();
					return;
				}
				$img.off('load.rememberPhoto').one('load.rememberPhoto', layoutPreview);
				$img.attr('src', objectUrl);
				if ($img[0].complete) {
					layoutPreview();
				}
			}

			var probe = new Image();
			probe.onload = function() {
				onDecoded(probe);
			};
			probe.onerror = function() {
				hideCropper();
			};
			probe.src = objectUrl;
		}

		function clearSelectedFile() {
			$file.val('');
			hideCropper();
		}

		function cropToFile(callback) {
			if (!ready || !$img[0].complete || !naturalW || !naturalH) {
				callback(null);
				return;
			}

			var v = viewportSize();
			var scale = baseScale() * zoom;
			var imgLeft = (v / 2) - (naturalW * scale / 2) + tx;
			var imgTop = (v / 2) - (naturalH * scale / 2) + ty;
			var sx = (0 - imgLeft) / scale;
			var sy = (0 - imgTop) / scale;
			var sSize = v / scale;

			sx = Math.max(0, Math.min(naturalW - sSize, sx));
			sy = Math.max(0, Math.min(naturalH - sSize, sy));
			sSize = Math.min(sSize, naturalW, naturalH);

			var canvas = document.createElement('canvas');
			canvas.width = outputSize;
			canvas.height = outputSize;
			var ctx = canvas.getContext('2d');
			if (!ctx) {
				callback(null);
				return;
			}

			ctx.imageSmoothingEnabled = true;
			ctx.imageSmoothingQuality = 'high';
			ctx.drawImage($img[0], sx, sy, sSize, sSize, 0, 0, outputSize, outputSize);

			canvas.toBlob(function(blob) {
				if (!blob) {
					callback(null);
					return;
				}
				var name = 'profile-photo.jpg';
				var original = $file[0].files && $file[0].files[0] ? $file[0].files[0].name : '';
				if (original) {
					name = original.replace(/\.[^.]+$/, '') + '-cropped.jpg';
				}
				callback(new File([blob], name, { type: 'image/jpeg', lastModified: Date.now() }));
			}, 'image/jpeg', 0.92);
		}

		$file.on('change', function() {
			var file = this.files && this.files[0] ? this.files[0] : null;
			if (!file) {
				hideCropper();
				return;
			}
			showCropper(file);
		});

		$clear.on('click', function(e) {
			e.preventDefault();
			clearSelectedFile();
		});

		$zoomRange.on('input change', function() {
			setZoom(parseFloat(this.value) || 1);
		});

		$zoomIn.on('click', function(e) {
			e.preventDefault();
			setZoom(zoom + 0.1);
		});

		$zoomOut.on('click', function(e) {
			e.preventDefault();
			setZoom(zoom - 0.1);
		});

		$viewport.on('wheel', function(e) {
			if ($cropper.prop('hidden')) {
				return;
			}
			e.preventDefault();
			var delta = e.originalEvent.deltaY > 0 ? -0.08 : 0.08;
			setZoom(zoom + delta);
		});

		$viewport.on('pointerdown', function(e) {
			if ($cropper.prop('hidden') || !ready) {
				return;
			}
			dragging = true;
			dragStartX = e.clientX;
			dragStartY = e.clientY;
			dragOriginTx = tx;
			dragOriginTy = ty;
			$viewport.addClass('is-dragging');
			if (this.setPointerCapture) {
				this.setPointerCapture(e.pointerId);
			}
		});

		$viewport.on('pointermove', function(e) {
			if (!dragging) {
				return;
			}
			tx = dragOriginTx + (e.clientX - dragStartX);
			ty = dragOriginTy + (e.clientY - dragStartY);
			applyTransform();
		});

		$viewport.on('pointerup pointercancel', function() {
			dragging = false;
			$viewport.removeClass('is-dragging');
		});

		$(window).on('resize.rememberPhotoCrop orientationchange.rememberPhotoCrop', function() {
			if (ready && !$cropper.prop('hidden')) {
				applyTransform();
			}
		});

		$form.on('submit', function(e) {
			if ($cropper.prop('hidden') || !ready || !$file[0].files || !$file[0].files.length) {
				return;
			}

			// Avoid double-handling after we swap in the cropped file.
			if ($form.data('rememberPhotoCropped')) {
				$form.removeData('rememberPhotoCropped');
				return;
			}

			e.preventDefault();
			$form.addClass('remember-photo-cropping');

			cropToFile(function(file) {
				$form.removeClass('remember-photo-cropping');
				if (file && typeof DataTransfer !== 'undefined') {
					var dt = new DataTransfer();
					dt.items.add(file);
					$file[0].files = dt.files;
				}
				$form.data('rememberPhotoCropped', true);
				// Native submit after replacing the file.
				if (typeof $form[0].requestSubmit === 'function') {
					$form[0].requestSubmit();
				} else {
					$form[0].submit();
				}
			});
		});
	}

	function initProfilePhotoCropper() {
		$('.remember-profile-photo-edit').each(function() {
			initOnePhotoCropper($(this));
		});
	}

	/**
	 * Show the allergy reaction field only when an allergy other than None is checked.
	 */
	function rememberInitSurvey(root) {
		var $root = root ? $(root) : $(document);
		$root.find('[data-remember-survey]').each(function() {
			var $survey = $(this);
			if ($survey.data('rememberSurveyReady')) {
				return;
			}
			$survey.data('rememberSurveyReady', 1);

			function currentValues($question) {
				var type = $question.attr('data-field-type');
				if (type === 'multiselect') {
					return $question.find('input:checked').map(function() {
						return $(this).val();
					}).get();
				}
				if (type === 'boolean') {
					var picked = $question.find('input:checked').val() || '';
					return picked ? [picked] : [];
				}
				var $field = $question.find('select, textarea, input[type="text"]').first();
				var value = $field.length ? ($field.val() || '') : '';
				return value ? [value] : [];
			}

			function apply() {
				var $questions = $survey.find('[data-remember-survey-question]');
				for (var pass = 0; pass < 2; pass++) {
					$questions.each(function() {
						var $question = $(this);
						var rawRule = $question.attr('data-remember-pq-when');
						if (!rawRule) {
							return;
						}
						var rule = null;
						try {
							rule = JSON.parse(rawRule);
						} catch (error) {
							rule = null;
						}
						var match = false;
						if (rule && rule.field_key && rule.values && rule.values.length) {
							var $parent = $survey.find('[data-remember-pq-key="' + rule.field_key + '"]');
							if ($parent.length && !$parent.prop('hidden')) {
								var picked = currentValues($parent);
								match = picked.some(function(value) {
									return rule.values.indexOf(value) !== -1;
								});
							}
						}
						$question.prop('hidden', !match);
						$question.find(':input').prop('disabled', !match);
						$question.find('.remember-required').prop('hidden', !match);
					});
				}
			}

			$survey.on('change input', ':input', apply);
			apply();
		});
	}
	window.rememberInitSurvey = rememberInitSurvey;

	function initAllergyReactionFields() {
		$('[data-remember-allergy-group]').each(function() {
			var $group = $(this);
			var $reaction = $group.find('[data-remember-allergy-reaction]');
			var $field = $reaction.find('textarea');
			var $boxes = $group.find('input[type="checkbox"][name="allergies[]"]');
			if (!$reaction.length || !$boxes.length) {
				return;
			}

			function sync() {
				var needs = $boxes.filter(':checked').filter(function() {
					return !this.hasAttribute('data-remember-none');
				}).length > 0;
				if (needs) {
					$reaction.removeAttr('hidden');
					$field.prop('required', true);
				} else {
					$reaction.attr('hidden', 'hidden');
					$field.prop('required', false);
				}
			}

			$boxes.off('change.rememberAllergyReaction').on('change.rememberAllergyReaction', sync);
			sync();
		});
	}
	window.rememberInitAllergyReaction = initAllergyReactionFields;

	function initRegistrationSuccessSplash() {
		var splash = document.querySelector('[data-remember-register-splash]');
		if (!splash) {
			return;
		}
		var url = splash.getAttribute('data-dashboard-url');
		if (!url) {
			return;
		}
		window.setTimeout(function() {
			window.location.assign(url);
		}, 3000);
	}

	/**
	 * Require at least one checkbox in dietary / medical / allergy groups.
	 * Selecting "None" clears other options in the same group (and vice versa).
	 */
	function initRequireOneCheckboxGroups() {
		$('[data-remember-require-one]').each(function() {
			var $group = $(this);
			var $boxes = $group.find('input[type="checkbox"]');
			if (!$boxes.length) {
				return;
			}

			function syncRequired() {
				var anyChecked = $boxes.filter(':checked').length > 0;
				$boxes.prop('required', false);
				if (!anyChecked) {
					$boxes.first().prop('required', true);
				}
			}

			$boxes.on('change', function() {
				var $changed = $(this);
				if ($changed.is('[data-remember-none]') && $changed.is(':checked')) {
					$boxes.not($changed).prop('checked', false);
				} else if (!$changed.is('[data-remember-none]') && $changed.is(':checked')) {
					$boxes.filter('[data-remember-none]').prop('checked', false);
				}
				syncRequired();
			});

			syncRequired();
		});
	}

	/**
	 * Conditional custom profile fields: show/hide + required when a gate field matches.
	 */
	/**
	 * Guard always-required multiselect custom questions.
	 *
	 * Conditional multiselects are handled by initConditionalProfileQuestions();
	 * always-required ones (no required_when rule) were previously guarded by
	 * neither path, so a member could submit them empty and the server would
	 * reject the save and reload the form from the database, discarding every
	 * other response. This mirrors initRequireOneCheckboxGroups(): when nothing
	 * is checked, the first box is marked required so the browser blocks submit
	 * and the member keeps their entries.
	 *
	 * @return {void}
	 */
	function initAlwaysRequiredPqMultiselects() {
		$('.remember-pq-field[data-remember-pq-always-required="1"]').each(function() {
			var $field = $(this);
			if ($field.data('remember-pq-type') !== 'multiselect') {
				return;
			}
			if ($field.is('[data-remember-pq-when]')) {
				return; // Conditional fields are managed elsewhere.
			}
			var $boxes = $field.find('input[type="checkbox"]');
			if (!$boxes.length) {
				return;
			}
			function syncRequired() {
				var anyChecked = $boxes.filter(':checked').length > 0;
				$boxes.prop('required', false);
				if (!anyChecked) {
					$boxes.first().prop('required', true);
				}
			}
			$boxes.on('change', syncRequired);
			syncRequired();
		});
	}

	function initConditionalProfileQuestions() {
		var $fields = $('.remember-pq-field[data-remember-pq-when]');
		if (!$fields.length) {
			return;
		}

		function getFieldValues($field) {
			var type = $field.data('remember-pq-type');
			if (type === 'multiselect') {
				return $field.find('input[type="checkbox"]:checked').map(function() {
					return $(this).val();
				}).get();
			}
			var $input = $field.find('select, input[type="text"]').first();
			var val = $input.length ? String($input.val() || '') : '';
			return val ? [val] : [];
		}

		function gateMatches(rule) {
			if (!rule || !rule.field_key || !rule.values || !rule.values.length) {
				return false;
			}
			var $gate = $('.remember-pq-field[data-remember-pq-key="' + rule.field_key + '"]');
			if (!$gate.length || $gate.prop('hidden')) {
				return false;
			}
			var picked = getFieldValues($gate);
			for (var i = 0; i < picked.length; i++) {
				if (rule.values.indexOf(picked[i]) !== -1) {
					return true;
				}
			}
			return false;
		}

		function syncMultiRequire($field, required) {
			var $group = $field.find('[role="group"]').first();
			var $boxes = $field.find('input[type="checkbox"]');
			if (!$boxes.length) {
				return;
			}
			if (required) {
				$group.attr('data-remember-pq-require-one', '1');
				var any = $boxes.filter(':checked').length > 0;
				$boxes.prop('required', false);
				if (!any) {
					$boxes.first().prop('required', true);
				}
			} else {
				$group.removeAttr('data-remember-pq-require-one');
				$boxes.prop('required', false);
			}
		}

		function syncField($field) {
			var raw = $field.attr('data-remember-pq-when');
			var rule = null;
			try {
				rule = raw ? JSON.parse(raw) : null;
			} catch (e) {
				rule = null;
			}
			var active = gateMatches(rule);
			$field.prop('hidden', !active);
			$field.find('.remember-pq-req-mark').prop('hidden', !active);

			var type = $field.data('remember-pq-type');
			if (type === 'multiselect') {
				syncMultiRequire($field, active);
			} else {
				$field.find('select, input[type="text"]').prop('required', !!active);
			}
		}

		function syncAll() {
			// Two passes so nested conditionals settle when a mid-chain gate hides.
			$fields.each(function() {
				syncField($(this));
			});
			$fields.each(function() {
				syncField($(this));
			});
		}

		$(document).on('change', '.remember-pq-field select, .remember-pq-field input[type="checkbox"], .remember-pq-field input[type="text"]', function() {
			syncAll();
		});

		syncAll();
	}

	function normalizeProfileConfirmPhrase(value) {
		return String(value || '')
			.toLowerCase()
			.replace(/\s+/g, ' ')
			.trim()
			.replace(/[.,!?;:'"\s]+$/g, '')
			.trim();
	}

	function initProfileCurrencyConfirm() {
		var ajaxUrl = (typeof rememberPublic !== 'undefined' && rememberPublic.ajaxurl) ? rememberPublic.ajaxurl : '';
		var nonce = (typeof rememberPublic !== 'undefined' && rememberPublic.profileCurrencyNonce) ? rememberPublic.profileCurrencyNonce : '';
		var staleMsg = (typeof rememberPublic !== 'undefined' && rememberPublic.i18n && rememberPublic.i18n.profileStale)
			? rememberPublic.i18n.profileStale
			: 'Save your profile first (within the last 24 hours), then type the confirmation phrase.';

		$('.remember-profile-currency-confirm').each(function() {
			var $wrap = $(this);
			var $input = $wrap.find('.remember-profile-currency-confirm__input');
			var $status = $wrap.find('.remember-profile-currency-confirm__status');
			var $form = $wrap.closest('form');
			if (!$input.length || !$form.length) {
				return;
			}
			var $submit = $form.find('button[type="submit"].remember-button-primary, button[type="submit"]').first();
			var expected = normalizeProfileConfirmPhrase($input.data('remember-confirm-phrase') || 'my profile is current');
			var fresh = $wrap.attr('data-remember-profile-fresh') === '1';
			var checking = false;

			function setStatus(message) {
				if (!message) {
					$status.prop('hidden', true).text('');
					return;
				}
				$status.prop('hidden', false).text(message);
			}

			function sync() {
				var phraseOk = normalizeProfileConfirmPhrase($input.val()) === expected;
				var unlocked = phraseOk && fresh;
				$submit.prop('disabled', !unlocked);
				$submit.toggleClass('remember-button-disabled', !unlocked);
				if (phraseOk && !fresh) {
					setStatus(staleMsg);
				} else {
					setStatus('');
				}
			}

			function refreshFreshness() {
				if (!ajaxUrl || !nonce || checking) {
					sync();
					return;
				}
				checking = true;
				$.post(ajaxUrl, {
					action: 'remember_profile_currency_status',
					nonce: nonce
				}).done(function(resp) {
					if (resp && resp.success && resp.data) {
						fresh = !!resp.data.fresh;
						$wrap.attr('data-remember-profile-fresh', fresh ? '1' : '0');
						if (resp.data.updated_at) {
							$wrap.attr('data-remember-profile-updated-at', resp.data.updated_at);
						}
					}
				}).always(function() {
					checking = false;
					sync();
				});
			}

			$input.on('input change keyup', sync);
			$(window).on('focus', refreshFreshness);
			$(document).on('visibilitychange', function() {
				if (!document.hidden) {
					refreshFreshness();
				}
			});
			$wrap.find('a[target="_blank"]').on('click', function() {
				// After returning from profile edit, re-check freshness.
				window.setTimeout(refreshFreshness, 500);
			});

			refreshFreshness();
		});
	}

	function applyGateI18n(key, fallback) {
		if (typeof rememberPublic !== 'undefined' && rememberPublic.i18n && rememberPublic.i18n[key]) {
			return rememberPublic.i18n[key];
		}
		return fallback;
	}

	function closeApplyGate($dialog) {
		if (!$dialog || !$dialog.length) {
			return;
		}
		$dialog.removeAttr('open');
		$dialog.find('[data-remember-apply-continue]').off('click.rememberApplyGate');
		$dialog.find('[data-remember-apply-cancel]').off('click.rememberApplyGate');
	}

	function initApplyGate() {
		$(document).on('click', 'a.remember-apply-gate', function(e) {
			var $link = $(this);
			var profileUrl = $link.attr('data-profile-url') || '';
			if (!profileUrl) {
				return;
			}
			e.preventDefault();

			var $dialog = $('#remember-apply-gate-dialog');
			if (!$dialog.length) {
				$dialog = $('<dialog>', {
					id: 'remember-apply-gate-dialog',
					class: 'remember-apply-gate-dialog',
					'aria-labelledby': 'remember-apply-gate-title'
				});
				$dialog.append(
					$('<h2>', { id: 'remember-apply-gate-title', class: 'remember-apply-gate-title' }).text(applyGateI18n('applyGateTitle', 'Confirm your profile first')),
					$('<p>', { class: 'remember-apply-gate-body' }).text(applyGateI18n('applyGateBody', '')),
					$('<div>', { class: 'remember-apply-gate-actions' }).append(
						$('<button>', {
							type: 'button',
							class: 'remember-button remember-button-primary',
							'data-remember-apply-continue': '1'
						}).text(applyGateI18n('applyGateContinue', 'Review profile')),
						$('<button>', {
							type: 'button',
							class: 'remember-button remember-button-secondary',
							'data-remember-apply-cancel': '1'
						}).text(applyGateI18n('applyGateCancel', 'Cancel'))
					)
				);
				$('body').append($dialog);
			}

			$dialog.find('[data-remember-apply-continue]').off('click.rememberApplyGate').on('click.rememberApplyGate', function() {
				window.location.href = profileUrl;
			});
			$dialog.find('[data-remember-apply-cancel]').off('click.rememberApplyGate').on('click.rememberApplyGate', function() {
				if (typeof $dialog[0].close === 'function') {
					$dialog[0].close();
				} else {
					closeApplyGate($dialog);
				}
			});

			if (typeof $dialog[0].showModal === 'function') {
				$dialog[0].showModal();
			} else {
				$dialog.attr('open', 'open');
			}
			$dialog.find('[data-remember-apply-continue]').trigger('focus');
		});

		$(document).on('cancel', '#remember-apply-gate-dialog', function() {
			closeApplyGate($(this));
		});
	}

	$(function() {
		initDisplayNameNicknameSync();
		initProfilePhotoCropper();
		initRequireOneCheckboxGroups();
		initAlwaysRequiredPqMultiselects();
		initAllergyReactionFields();
		rememberInitSurvey();
		initRegistrationSuccessSplash();
		initConditionalProfileQuestions();
		initProfileCurrencyConfirm();
		initApplyGate();
		initInterestsLimitFallback();
		hookTinymceAddEditor();
		bindKnownInterestsEditors();
		var tries = 0;
		var timer = window.setInterval(function() {
			tries += 1;
			hookTinymceAddEditor();
			bindKnownInterestsEditors();
			if (tries >= 40) {
				window.clearInterval(timer);
			}
		}, 250);
		if (typeof window.rememberInitTimezoneComboboxes === 'function') {
			window.rememberInitTimezoneComboboxes();
		}
	});

	/**
	 * Count Unicode code points (matches PHP mb_strlen for typical Interests text).
	 *
	 * @param {string} text
	 * @return {number}
	 */
	function rememberInterestsCodepoints(text) {
		if (!text) {
			return 0;
		}
		if (typeof Array.from === 'function') {
			return Array.from(text).length;
		}
		return text.length;
	}

	/**
	 * Format "current / max characters" without depending on wp.i18n.
	 *
	 * @param {number} count
	 * @param {number} max
	 * @return {string}
	 */
	function rememberInterestsCountLabel(count, max, template) {
		var label = template || '%1$s / %2$s characters';
		return label.replace('%1$s', String(count)).replace('%2$s', String(max));
	}

	/**
	 * Show a short, auto-hiding notice next to an Interests counter so an
	 * over-limit paste is not silently discarded.
	 *
	 * @param {object} $counter jQuery counter element.
	 * @param {string} message  Message to show.
	 * @return {void}
	 */
	function rememberShowInterestsNotice($counter, message) {
		if (!$counter || !$counter.length) {
			return;
		}
		var $notice = $counter.data('remember-interests-notice');
		if (!$notice || !$notice.length) {
			$notice = $('<span class="remember-interests-notice" role="status" aria-live="polite"></span>');
			$counter.after($notice);
			$counter.data('remember-interests-notice', $notice);
		}
		$notice.text(message).addClass('is-visible');
		var prior = $counter.data('remember-interests-notice-timer');
		if (prior) {
			window.clearTimeout(prior);
		}
		$counter.data('remember-interests-notice-timer', window.setTimeout(function() {
			$notice.removeClass('is-visible');
		}, 6000));
	}

	/**
	 * Message shown when an Interests paste is over the limit.
	 *
	 * @param {number} max Character limit.
	 * @return {string}
	 */
	function rememberInterestsOverLimitMessage(max) {
		return 'Your pasted text was over the ' + String(max) + '-character limit, so it was not added. Please shorten it and try again.';
	}

	/**
	 * Text inside a marker payload must not contain raw <, >, or %%.
	 *
	 * @param {string} text
	 * @return {string}
	 */
	function rememberEscapeMarkerText(text) {
		return String(text || '')
			.replace(/%%/g, '%%rmb:esc%%')
			.replace(/&/g, '%%rmb:amp%%')
			.replace(/</g, '%%rmb:lt%%')
			.replace(/>/g, '%%rmb:gt%%');
	}

	/**
	 * Turn editor HTML into %%rmb:%% markers so the POST has no angle brackets.
	 * PHP turns the markers back into p, br, b, em, u, ul, ol, and li.
	 *
	 * @param {string} html
	 * @return {string}
	 */
	function rememberInterestsToMarkers(html) {
		var skip = { SCRIPT: 1, STYLE: 1, IFRAME: 1, OBJECT: 1, NOSCRIPT: 1, TEXTAREA: 1, INPUT: 1, BUTTON: 1, SELECT: 1, SVG: 1 };
		var map = { P: 'p', B: 'b', STRONG: 'b', I: 'em', EM: 'em', U: 'u', UL: 'ul', OL: 'ol', LI: 'li' };
		var wrap = document.createElement('div');
		wrap.innerHTML = String(html || '');

		function walk(node) {
			var out = '';
			var child = node.firstChild;
			while (child) {
				out += serialize(child);
				child = child.nextSibling;
			}
			return out;
		}

		function serialize(node) {
			if (!node) {
				return '';
			}
			if (node.nodeType === 3) {
				return rememberEscapeMarkerText(node.nodeValue || '');
			}
			if (node.nodeType !== 1) {
				return '';
			}
			var tag = node.tagName;
			if (skip[tag] || tag.indexOf(':') !== -1) {
				return '';
			}
			if (tag === 'BR') {
				var brClass = node.getAttribute('class') || '';
				var bogus = node.getAttribute('data-mce-bogus');
				if (brClass.indexOf('Apple-interchange-newline') !== -1 || bogus) {
					return '';
				}
				return '%%rmb:br%%';
			}
			var inner = walk(node);
			var name = map[tag];
			if (!name) {
				return inner;
			}
			if (!String(inner).replace(/%%rmb:br%%/g, '').replace(/\s+/g, '')) {
				return '';
			}
			return '%%rmb:' + name + '%%' + inner + '%%rmb:/' + name + '%%';
		}

		return walk(wrap);
	}

	/**
	 * Live character limit for Interests TinyMCE editors.
	 *
	 * @param {object} editor TinyMCE editor instance.
	 * @return {void}
	 */
	function initInterestsLimitEditor(editor) {
		if (!editor || !editor.id) {
			return;
		}
		if (editor.id !== 'interests' && editor.id !== 'remember_reg_interests') {
			return;
		}
		if (editor.rememberInterestsLimited) {
			return;
		}
		editor.rememberInterestsLimited = true;

		function useUnderlineTag() {
			if (editor.formatter) {
				editor.formatter.register('underline', { inline: 'u', exact: true });
			}
		}
		editor.on('init', useUnderlineTag);
		if (editor.initialized) {
			useUnderlineTag();
		}

		var $counter = $('.remember-interests-count[data-remember-interests-editor="' + editor.id + '"]');
		var max = parseInt($counter.attr('data-remember-interests-max'), 10) || 2000;
		var template = $counter.attr('data-remember-interests-template') || '';

		function plainLen() {
			return rememberInterestsCodepoints(editor.getContent({ format: 'text' }) || '');
		}

		function updateCounter() {
			var len = plainLen();
			$counter.text(rememberInterestsCountLabel(len, max, template));
			$counter.toggleClass('is-over', len > max);
		}

		function isEditNav(e) {
			if (!e) {
				return false;
			}
			if (e.ctrlKey || e.metaKey || e.altKey) {
				return true;
			}
			var key = e.key || '';
			return (
				key === 'Backspace' ||
				key === 'Delete' ||
				key === 'ArrowLeft' ||
				key === 'ArrowRight' ||
				key === 'ArrowUp' ||
				key === 'ArrowDown' ||
				key === 'Home' ||
				key === 'End' ||
				key === 'Tab' ||
				key === 'Escape'
			);
		}

		editor.on('keydown', function(e) {
			if (isEditNav(e)) {
				return;
			}
			if (plainLen() >= max) {
				e.preventDefault();
			}
		});

		editor.on('keyup', updateCounter);
		editor.on('change', updateCounter);
		editor.on('undo', updateCounter);
		editor.on('redo', updateCounter);
		editor.on('input', updateCounter);
		editor.on('NodeChange', updateCounter);
		editor.on('SetContent', updateCounter);

		// WordPress calls save() on submit, and save() writes this event into the textarea.
		editor.on('SaveContent', function(e) {
			if (!e) {
				return;
			}
			e.content = rememberInterestsToMarkers(e.content || '');
			var field = editor.getElement();
			if (field) {
				field.value = e.content;
			}
		});

		var formEl = editor.getElement() && editor.getElement().form;
		if (formEl && !formEl.rememberInterestsMarkerSubmit) {
			formEl.rememberInterestsMarkerSubmit = true;
			formEl.addEventListener('submit', function() {
				editor.save();
			}, true);
		}

		editor.on('paste', function() {
			window.setTimeout(function() {
				if (plainLen() > max && editor.undoManager) {
					editor.undoManager.undo();
					rememberShowInterestsNotice($counter, rememberInterestsOverLimitMessage(max));
				}
				updateCounter();
			}, 0);
		});

		editor.on('init', updateCounter);
		updateCounter();
	}

	window.rememberInitInterestsLimitEditor = initInterestsLimitEditor;

	function hookTinymceAddEditor() {
		if (!window.tinymce || typeof tinymce.on !== 'function') {
			return false;
		}
		if (tinymce.rememberInterestsHooked) {
			return true;
		}
		tinymce.rememberInterestsHooked = true;
		tinymce.on('AddEditor', function(e) {
			if (e && e.editor) {
				initInterestsLimitEditor(e.editor);
			}
		});
		return true;
	}

	function bindKnownInterestsEditors() {
		if (!window.tinymce || typeof tinymce.get !== 'function') {
			return;
		}
		['interests', 'remember_reg_interests'].forEach(function(id) {
			var existing = tinymce.get(id);
			if (existing) {
				initInterestsLimitEditor(existing);
			}
		});
	}

	hookTinymceAddEditor();
	$(document).on('tinymce-editor-init', function(event, editor) {
		initInterestsLimitEditor(editor);
	});

	/**
	 * Textarea fallback when TinyMCE is not active.
	 *
	 * @return {void}
	 */
	function initInterestsLimitFallback() {
		$('textarea#interests, textarea#remember_reg_interests').each(function() {
			var $area = $(this);
			if ($area.closest('.wp-editor-wrap').find('.mce-tinymce').length) {
				return;
			}
			var id = $area.attr('id');
			var $counter = $('.remember-interests-count[data-remember-interests-editor="' + id + '"]');
			if (!$counter.length) {
				return;
			}
			var max = parseInt($counter.attr('data-remember-interests-max'), 10) || 2000;
			var template = $counter.attr('data-remember-interests-template') || '';

			function sync(fromUser) {
				var value = $area.val() || '';
				var len = rememberInterestsCodepoints(value);
				if (fromUser && len > max) {
					if (typeof Array.from === 'function') {
						value = Array.from(value).slice(0, max).join('');
					} else {
						value = value.substring(0, max);
					}
					$area.val(value);
					len = max;
					rememberShowInterestsNotice($counter, 'Your text was shortened to the ' + String(max) + '-character limit.');
				}
				$counter.text(rememberInterestsCountLabel(len, max, template));
				$counter.toggleClass('is-over', len > max);
			}

			$area.on('input change', function() {
				sync(true);
			});
			$area.closest('form').on('submit', function() {
				if (window.tinymce && typeof tinymce.get === 'function' && tinymce.get(id)) {
					return;
				}
				$area.val(rememberInterestsToMarkers($area.val() || ''));
			});
			sync(false);
		});
	}

})(jQuery);
