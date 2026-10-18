/**
 * All of the JavaScript for your admin-specific functionality should be
 * included in this file.
 */

(function($) {
	'use strict';
	
	// Settings page tabs
	$(document).ready(function() {
		// Handle tab clicks (Settings page only — do not intercept other nav-tab-wrappers).
		var $settingsTabs = $('.remember-settings #remember-main-settings > .nav-tab-wrapper a');

		function notificationCategoryFromHash(hash) {
			var match = /^#notifications(?:-(.+))?$/.exec(hash || '');
			if (!match) {
				return null;
			}
			return match[1] || '';
		}

		function showNotificationSubtab(category) {
			var $links = $('.remember-notification-subtab-link');
			if (!$links.length) {
				return '';
			}
			var $link = category ? $links.filter('[data-category="' + category + '"]') : $();
			if (!$link.length) {
				$link = $links.first();
			}
			var activeCategory = $link.data('category');
			$links.removeClass('nav-tab-active');
			$link.addClass('nav-tab-active');
			$('.remember-notification-panel').hide();
			$('#notification-panel-' + activeCategory).show();
			$('#remember-notification-category').val(activeCategory);
			refreshNotificationEditors();
			return activeCategory ? '#notifications-' + activeCategory : '#notifications';
		}

		function refreshNotificationEditors() {
			window.setTimeout(function() {
				if (typeof window.tinymce === 'undefined' || !tinymce.editors) {
					return;
				}
				var i, ed, len;
				len = tinymce.editors.length;
				for (i = 0; i < len; i++) {
					ed = tinymce.editors[i];
					if (!ed || !ed.id || ed.id.indexOf('notif_body_') !== 0) {
						continue;
					}
					ed.hidden = false;
					if (typeof ed.fire === 'function') {
						ed.fire('ResizeEditor');
					}
				}
			}, 50);
		}

		function activateSettingsTab(target) {
			var parentTarget = target;
			var notifCategory = notificationCategoryFromHash(target);
			if (notifCategory !== null) {
				parentTarget = '#notifications';
			}

			$settingsTabs.removeClass('nav-tab-active');
			$settingsTabs.filter('[href="' + parentTarget + '"]').addClass('nav-tab-active');

			$('.remember-settings-tab').hide();
			$(parentTarget).show();

			var hash = parentTarget;
			if (parentTarget === '#notifications') {
				hash = showNotificationSubtab(notifCategory);
			}

			if (history.pushState) {
				history.pushState(null, null, hash);
			}
		}

		$settingsTabs.on('click', function(e) {
			e.preventDefault();
			var target = $(this).attr('href');
			if (target === '#notifications') {
				var currentNotif = notificationCategoryFromHash(window.location.hash);
				if (currentNotif) {
					target = '#notifications-' + currentNotif;
				} else {
					var $first = $('.remember-notification-subtab-link').first();
					target = $first.length ? $first.attr('href') : '#notifications';
				}
			}
			activateSettingsTab(target);
		});

		$(document).on('click', '.remember-notification-subtab-link', function(e) {
			e.preventDefault();
			activateSettingsTab($(this).attr('href'));
		});

		$(document).on('submit', '#remember-notification-settings-form', function() {
			if (window.tinymce && typeof tinymce.triggerSave === 'function') {
				tinymce.triggerSave();
			}
		});

		// Handle URL hash on page load
		if (window.location.hash) {
			var hash = window.location.hash;
			// Combined Platforms tab replaced separate Social Media / IM tabs.
			if (hash === '#social-media' || hash === '#im-platforms') {
				hash = '#platforms';
			}
			if (notificationCategoryFromHash(hash) !== null) {
				activateSettingsTab(hash);
			} else {
				var $tab = $('.remember-settings #remember-main-settings > .nav-tab-wrapper a[href="' + hash + '"]');
				if ($tab.length) {
					$tab.trigger('click');
				}
			}
		} else {
			// Support ?tab=xero (and similar) from OAuth redirect URIs.
			var params = new URLSearchParams(window.location.search);
			var tabParam = params.get('tab');
			if (tabParam) {
				var $tabFromQuery = $('.remember-settings #remember-main-settings > .nav-tab-wrapper a[href="#' + tabParam + '"]');
				if ($tabFromQuery.length) {
					$tabFromQuery.trigger('click');
				}
			}
		}
		
		// Copy shortcode to clipboard
		$('.remember-copy-shortcode').on('click', function(e) {
			e.preventDefault();
			var shortcode = $(this).data('shortcode');
			var $button = $(this);
			
			// Create temporary textarea to copy from
			var $temp = $('<textarea>');
			$('body').append($temp);
			$temp.val(shortcode).select();
			document.execCommand('copy');
			$temp.remove();
			
			// Show feedback
			var originalText = $button.text();
			$button.text('✓ Copied!').prop('disabled', true);
			setTimeout(function() {
				$button.text(originalText).prop('disabled', false);
			}, 2000);
		});

		// Ticket logo override media picker
		var ticketLogoFrame;
		$('#remember-ticket-logo-select').on('click', function(e) {
			e.preventDefault();
			if (typeof wp === 'undefined' || !wp.media) {
				return;
			}
			if (ticketLogoFrame) {
				ticketLogoFrame.open();
				return;
			}
			ticketLogoFrame = wp.media({
				title: 'Select ticket logo',
				button: { text: 'Use as ticket logo' },
				multiple: false
			});
			ticketLogoFrame.on('select', function() {
				var attachment = ticketLogoFrame.state().get('selection').first().toJSON();
				$('#ticket_logo_id').val(attachment.id);
				var url = (attachment.sizes && attachment.sizes.medium) ? attachment.sizes.medium.url : attachment.url;
				$('#remember-ticket-logo-preview').html('<img src="' + url + '" alt="" style="max-height: 72px; width: auto;">');
				$('#remember-ticket-logo-clear').prop('disabled', false);
			});
			ticketLogoFrame.open();
		});
		$('#remember-ticket-logo-clear').on('click', function(e) {
			e.preventDefault();
			$('#ticket_logo_id').val('0');
			$('#remember-ticket-logo-preview').empty();
			$(this).prop('disabled', true);
		});

		// Location logo: Media Library and/or a fresh upload.
		var locationLogoFrame;
		var $locationLogoWrap;
		$('.remember-location-logo-select').on('click', function(e) {
			e.preventDefault();
			if (typeof wp === 'undefined' || !wp.media) {
				return;
			}
			$locationLogoWrap = $(this).closest('.remember-location-logo');
			var title = $(this).data('title') || 'Select location logo';
			var button = $(this).data('button') || 'Use as logo';
			if (locationLogoFrame) {
				locationLogoFrame.open();
				return;
			}
			locationLogoFrame = wp.media({
				title: title,
				button: { text: button },
				library: { type: 'image' },
				multiple: false
			});
			locationLogoFrame.on('select', function() {
				if (!$locationLogoWrap || !$locationLogoWrap.length) {
					return;
				}
				var attachment = locationLogoFrame.state().get('selection').first().toJSON();
				var url = attachment.url;
				if (attachment.sizes) {
					if (attachment.sizes.medium) {
						url = attachment.sizes.medium.url;
					} else if (attachment.sizes.full) {
						url = attachment.sizes.full.url;
					}
				}
				$locationLogoWrap.find('.remember-location-logo-id').val(attachment.id);
				$locationLogoWrap.find('.remember-location-logo-preview').empty().append(
					$('<img>', { src: url, alt: '' })
				);
				$locationLogoWrap.find('.remember-location-logo-file').val('');
				$locationLogoWrap.find('.remember-location-logo-delete').prop('checked', false);
			});
			locationLogoFrame.open();
		});
		$('.remember-location-logo-file').on('change', function() {
			if (!this.files || !this.files.length) {
				return;
			}
			$(this).closest('.remember-location-logo').find('.remember-location-logo-id').val('0');
			$(this).closest('.remember-location-logo').find('.remember-location-logo-delete').prop('checked', false);
		});
		$('.remember-location-logo-delete').on('change', function() {
			if (!this.checked) {
				return;
			}
			var $wrap = $(this).closest('.remember-location-logo');
			$wrap.find('.remember-location-logo-id').val('0');
			$wrap.find('.remember-location-logo-preview').empty();
			$wrap.find('.remember-location-logo-file').val('');
		});

		// Member profile print formats. Caps gate which modes appear; Ctrl/Cmd+P
		// is coerced to an allowed mode (or denied) so a menu is not required.
		var $printDetail = $('.remember-member-detail');
		if ($printDetail.length) {
			var $printMenu = $('.remember-print-menu');
			var $printToggle = $printMenu.find('.remember-print-menu__toggle');
			var $printList = $printMenu.find('.remember-print-menu__list');
			var defaultPrintMode = $printDetail.attr('data-print-mode') || 'denied';
			var originalDocumentTitle = document.title;

			var canPrintMode = function(mode) {
				if (mode === 'confidential') {
					return $printDetail.attr('data-print-can-confidential') === '1';
				}
				if (mode === 'event') {
					return $printDetail.attr('data-print-can-event') === '1';
				}
				return false;
			};

			var resolveAllowedPrintMode = function(preferred) {
				if (canPrintMode(preferred)) {
					return preferred;
				}
				if (canPrintMode('confidential')) {
					return 'confidential';
				}
				if (canPrintMode('event')) {
					return 'event';
				}
				return 'denied';
			};

			var closePrintMenu = function() {
				if (!$printList.length) {
					return;
				}
				$printList.prop('hidden', true);
				$printToggle.attr('aria-expanded', 'false');
			};

			var sanitizePrintFilenamePart = function(value) {
				return String(value || 'member')
					.trim()
					.replace(/\s+/g, '_')
					.replace(/[\\/:*?"<>|]+/g, '')
					.replace(/_+/g, '_')
					.replace(/^_|_$/g, '') || 'member';
			};

			var printDateStamp = function() {
				var now = new Date();
				var yy = String(now.getFullYear()).slice(-2);
				var mm = String(now.getMonth() + 1).padStart(2, '0');
				var dd = String(now.getDate()).padStart(2, '0');
				return yy + mm + dd;
			};

			var setPrintDocumentTitle = function(mode) {
				var name = sanitizePrintFilenamePart($printDetail.attr('data-print-display-name'));
				var stamp = printDateStamp();
				if (mode === 'event') {
					document.title = name + '_event_card_' + stamp;
				} else if (mode === 'confidential') {
					document.title = name + '_member_full_profile_' + stamp;
				} else {
					document.title = originalDocumentTitle;
				}
			};

			var openPrintDialog = function(mode) {
				$printDetail.attr('data-print-mode', mode);
				setPrintDocumentTitle(mode);
				// Yield once so the new mode is styled before the print snapshot.
				window.setTimeout(function() {
					window.print();
				}, 0);
			};

			var printLoading = false;

			// The confidential sheet always carries health and emergency for staff allowed to see them,
			// and the server logs the print before the dialog opens. No log, no print.
			var runPrint = function(mode) {
				mode = resolveAllowedPrintMode(mode);
				closePrintMenu();
				if (mode !== 'confidential' || $printDetail.attr('data-print-sensitive') !== '1') {
					openPrintDialog(mode);
					return;
				}
				if (printLoading) {
					return;
				}
				printLoading = true;
				$.post(rememberAjax.ajaxurl, {
					action: 'remember_print_confidential',
					nonce: $printDetail.attr('data-print-nonce'),
					member_id: $printDetail.attr('data-member-id')
				}).done(function(response) {
					var sections = response && response.success && response.data ? response.data.sections : null;
					if (!sections) {
						window.alert((response && response.data && response.data.message) || $printDetail.attr('data-print-error'));
						return;
					}
					var targets = {
						emergency: '.remember-member-detail-emergency-card',
						health: '.remember-member-detail-health-column'
					};
					$.each(targets, function(topic, selector) {
						if (sections[topic]) {
							$printDetail.find(selector).first().replaceWith($.parseHTML(String(sections[topic]).trim()));
						}
					});
					openPrintDialog(mode);
				}).fail(function(xhr) {
					var data = xhr && xhr.responseJSON && xhr.responseJSON.data;
					window.alert((data && data.message) || $printDetail.attr('data-print-error'));
				}).always(function() {
					printLoading = false;
				});
			};

			if ($printMenu.length) {
				$printToggle.on('click', function(e) {
					e.preventDefault();
					if ($printToggle.attr('aria-expanded') === 'true') {
						closePrintMenu();
						return;
					}
					$printList.prop('hidden', false);
					$printToggle.attr('aria-expanded', 'true');
				});

				$printMenu.on('click', 'button[data-print-mode]', function(e) {
					e.preventDefault();
					runPrint($(this).attr('data-print-mode'));
				});

				$(document).on('click', function(e) {
					if (!$(e.target).closest('.remember-print-menu').length) {
						closePrintMenu();
					}
				});

				$(document).on('keydown', function(e) {
					if (e.key === 'Escape') {
						closePrintMenu();
					}
					// Ctrl/Cmd+P goes through the same path as the Print button.
					if ((e.ctrlKey || e.metaKey) && !e.altKey && !e.shiftKey && String(e.key).toLowerCase() === 'p') {
						e.preventDefault();
						runPrint(defaultPrintMode);
					}
				});
			}

			// Ctrl/Cmd+P: keep an allowed mode, or blank the sheet when neither cap is held.
			$(window).on('beforeprint', function() {
				var mode = resolveAllowedPrintMode($printDetail.attr('data-print-mode') || defaultPrintMode);
				$printDetail.attr('data-print-mode', mode);
				setPrintDocumentTitle(mode);
			});

			$(window).on('afterprint', function() {
				$printDetail.attr('data-print-mode', defaultPrintMode);
				document.title = originalDocumentTitle;
			});
		}

		// Health and emergency sections stay out of the page until staff open one; the server logs each open.
		$(document).on('click', '.remember-sensitive-open', function(e) {
			e.preventDefault();
			var $button = $(this);
			var $holder = $button.closest('[data-remember-sensitive]');
			var $error = $holder.find('.remember-sensitive-error');

			var fail = function(message) {
				$button.prop('disabled', false);
				$error.text(message || $holder.attr('data-error') || '').prop('hidden', false);
			};

			$button.prop('disabled', true);
			$error.prop('hidden', true).text('');
			$.post(rememberAjax.ajaxurl, {
				action: 'remember_reveal_sensitive',
				nonce: $holder.attr('data-nonce'),
				member_id: $holder.attr('data-member-id'),
				topic: $holder.attr('data-remember-sensitive'),
				mode: $holder.attr('data-mode')
			}).done(function(response) {
				if (response && response.success && response.data && response.data.html) {
					$holder.replaceWith($.parseHTML(String(response.data.html).trim()));
					if (typeof window.rememberInitAllergyReaction === 'function') {
						window.rememberInitAllergyReaction();
					}
					return;
				}
				fail(response && response.data && response.data.message);
			}).fail(function(xhr) {
				var data = xhr && xhr.responseJSON && xhr.responseJSON.data;
				fail(data && data.message);
			});
		});

		if (typeof window.rememberInitTimezoneComboboxes === 'function') {
			window.rememberInitTimezoneComboboxes();
		}

		$('.remember-events form').each(function() {
			var $form = $(this);
			var $toggle = $form.find('.remember-event-private-toggle');
			var $row = $form.find('.remember-event-invitees-row');
			if (!$toggle.length || !$row.length) {
				return;
			}

			function syncPrivateRow() {
				if ($toggle.is(':checked')) {
					$row.removeAttr('hidden');
				} else {
					$row.attr('hidden', 'hidden');
				}
			}

			function visibleLabels() {
				return $form.find('.remember-event-invite-list label').not('.is-filtered-out');
			}

			function updateCount() {
				$form.find('.remember-event-invite-count').text(
					$form.find('.remember-event-invite-list input:checked').length
				);
			}

			$toggle.on('change', syncPrivateRow);
			syncPrivateRow();

			$form.find('.remember-event-invite-filter').on('input', function() {
				var query = $.trim($(this).val()).toLowerCase();
				$form.find('.remember-event-invite-list label').each(function() {
					var haystack = ($(this).attr('data-search') || '').toLowerCase();
					$(this).toggleClass('is-filtered-out', !!query && haystack.indexOf(query) === -1);
				});
			});

			$form.find('.remember-event-invite-list').on('change', 'input', function() {
				$(this).closest('label').toggleClass('is-checked', this.checked);
				updateCount();
			});

			$form.find('.remember-event-invite-select-shown').on('click', function() {
				visibleLabels().each(function() {
					$(this).find('input').prop('checked', true).trigger('change');
				});
			});

			$form.find('.remember-event-invite-clear-shown').on('click', function() {
				visibleLabels().each(function() {
					$(this).find('input').prop('checked', false).trigger('change');
				});
			});

			updateCount();
		});
	});
	
})(jQuery);
