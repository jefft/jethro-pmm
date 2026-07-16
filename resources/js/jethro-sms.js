
/************* SMS ****************/

// =============================================================================
// JethroSMS — SMS send-flow + registration UI module
// =============================================================================
// This module handles ONLY the interactive send flow and supporting UI:
//   - Bulk SMS form submission via AJAX (?call=sms)
//   - Modal SMS dialog submission via AJAX (?call=sms)
//   - Modal open trigger, sender-number registration / OTP, cancel links,
//     note-field toggle, scheduled-send toggle, history/message filters,
//     and delivery-status polling.
//
// ALL SMS cost/segment/unicode-policy MATHS AND THE STATUSLINE/PREVIEW NOW
// LIVE SERVER-SIDE (jethro-sms/src/sms_statusline.php), streamed via
// ?call=sms_statusline using Datastar SSE. The composer textarea posts to
// that endpoint on debounced input; the server morphs #sms-statusline* and
// #sms-preview-panel* and patches $smsSendBlocked / $smsBlockReason. The only
// client-side counting left is the instant char-count (a Datastar data-text
// signal). See docs/docs/developer/reference/sms/SMS_DATASTAR.md.
// =============================================================================

var JethroSMS = {
};

/**
 * Initialise the SMS module.
 * Called once on page load. Attaches event listeners for modal dialog
 * triggers, both submit handlers, and supporting UI. Cost/segment counting
 * and the statusline/preview are server-rendered via Datastar (see header).
 */
JethroSMS.init = function() {

	// -----------------------------------------------------------------------
	// Read unicode restriction mode from the textarea's data attribute.
	// The PHP layer sets data-sms-unicode-permitted based on the
	// SMS_UNICODE_PERMITTED config constant:
	//   absent / undefined  →  Unicode allowed freely (SMS_UNICODE_PERMITTED=1)
	//   "1"                 →  Unicode allowed freely (SMS_UNICODE_PERMITTED=1)
	//   "0"                 →  Unicode fully disabled  (SMS_UNICODE_PERMITTED=0)
	//   "when_free"         →  Block only if the message is >70 chars (i.e.
	//                          would need more than one UCS-2 segment)
	// -----------------------------------------------------------------------
	// NOTE: All SMS cost/segment/unicode-policy maths now lives SERVER-SIDE
	// (jethro-sms/src/sms_statusline.php), streamed via ?call=sms_statusline using
	// Datastar SSE. The textarea's data-on:input__debounce attribute posts to
	// that endpoint, which morphs #sms-statusline* and #sms-preview-panel* and
	// patches the $smsSendBlocked / $smsBlockReason signals that gate Send.
	// The only client-side counting left is the instant char-count (a Datastar
	// data-text signal). The sms_type radios and recipient checkboxes carry
	// data-on:change attributes that re-post to the same endpoint.
	// See docs/docs/developer/reference/sms/SMS_DATASTAR.md.

	// Preview panel visibility is driven entirely by Datastar: the
	// "Message Preview" checkbox is data-bind'd to a per-form $smspreview*
	// signal and the panel wrapper carries data-show="$smspreview*". No JS
	// handler is needed here.

	// Per-recipient override editing is now server-rendered in the preview
	// panel (renderPreviewPanel) with Datastar-bound edit textareas writing to
	// the $smsOverrides signal, which posts back to ?call=sms_statusline on
	// blur. No client-side override bookkeeping remains here.

	// -----------------------------------------------------------------------
	// Modal SMS dialog trigger.
	// When a [data-toggle="sms-modal"] element is clicked, populate the modal
	// with recipient info and show it.
	// -----------------------------------------------------------------------
	$(document).on('click', '[data-toggle="sms-modal"]', function(e) {
		var $this = $(this)
				, href = $this.attr('href')
				, $target = $($this.attr('data-target') || (href && href.replace(/.*(?=#[^\s]+$)/, ''))) //strip for ie7
				, option = $target.data('modal') ? 'toggle' : $.extend({remote: !/#/.test(href) && href}, $target.data(), $this.data());

		var recipients = $this.attr('data-name');
		var personid = $this.attr('data-personid');
		var singleRecipient = (personid || '').indexOf(',') === -1;

		// Hide token hint and message preview for single-person sends
		$("#sms-token-hint").toggle(!singleRecipient);
		$(".sms-preview-toggle").toggle(!singleRecipient);

		// Populate the modal with recipient names and clear previous state
		$("#send-sms-modal .sms_recipients").html(recipients);
		if (!singleRecipient) {
			$("#send-sms-modal .sms_recipients").append(
			' <small class="soft">(' + personid.split(',').length + ' recipients)</small>'
			);
		}
		var $modalTa = $target.find('.sms-message');
		$modalTa.val(""); // Empty the textarea in case of reuse
		$("#send-sms-modal .results").html(""); // Empty in case of reuse
		$("#send-sms-modal #sms-call-failures").empty().hide();

		if (personid) {
			$("#send-sms-modal").attr("data-sms_type", "person");
			$("#send-sms-modal").attr("data-personid", personid);
			$("#send-sms-modal .sms-modal-option").show();
			e.preventDefault();
			$target.modal(option).one('hide', function() {
				$this.focus()
			})
			$target.on('shown', function() { $target.find('.sms-message').select(); })
		} else {
			alert('No SMS recipients found');
		}
		// Mirror the recipient into the modal's hidden personid field so the
		// Datastar statusline post (?call=sms_statusline) resolves the right
		// recipient. The statusline itself is server-rendered on first input.
		$('#send-sms-modal input[name="personid"]').val(personid || '');
	});

	// The bulk Send button is disabled reactively by Datastar via
	// data-attr:disabled="$smsSendBlocked" (unicode/balance blocking, computed
	// server-side). The submit handler below additionally guards against an
	// empty recipient set / blank message. Sender validity is enforced
	// server-side at send time (a clear error is returned if misconfigured).


	// -----------------------------------------------------------------------
	// Bulk SMS submit handler (person list page inline form)
	//
	// Flow:
	//   1. Send SMS to all selected persons via AJAX (?call=sms).
	//   2. On success, JethroSMS.onAJAXSuccess() marks each person row with sms-success / sms-failure classes and displays
	//      result alerts.
	//   3. If "Create Note…" is checked, Call_SMS creates notes server-side in the same request,
	//      a note per recipient, linking the note to the SMS via related_messages.
	//   4. On note-save success, each recipient row also gets note-success so the CSS rule `#body tr.note-success
	//      .note-link` highlights the "Add Note" link — matching the behaviour of #add-note-modal.
	//   5. The finishSend callback runs after everything completes (whether or not a note was saved), updating results and
	//      re-enabling the Send button.
	// -----------------------------------------------------------------------
	$('.bulk-sms-submit').click(function(event) {
		event.preventDefault();
		if ($("input[name='personid[]']:checked").length === 0) {
			return false;
		}

		// Disable the button and show "Sending..." while the AJAX call runs
		var submitBtn = $("#smshttp .bulk-sms-submit");
		submitBtn.prop('disabled', true);
		submitBtn.prop('value', 'Sending...');
		submitBtn.css('cursor', 'wait');
		$('#sms-send-response-bulk').hide().text('');

		var smsData = $(this.form).serialize();
			// Per-recipient overrides ride along automatically: they are real
			// <textarea name="message_overrides[PID]"> fields in the form,
			// captured by serialize() and parsed server-side by Call_SMS.

		$.ajax({
			type: 'POST',
			dataType: 'JSON',
			url: '?call=sms',
			data: smsData,
			context: $(this),
			error: function(jqXHR, status, error) {
				var msg = 'Error sending SMS';
				if (status === 'parsererror' && jqXHR.responseText) {
					// Server returned non-JSON (e.g. PHP error page) — extract readable text
					var div = document.createElement('div');
					div.innerHTML = jqXHR.responseText;
					var text = (div.textContent || div.innerText || '').trim();
					if (text) msg += ':\n\n' + text;
				} else if (error) {
					msg += ': ' + error;
				}
				alert(msg);
				submitBtn.prop('disabled', false);
				submitBtn.prop('value', 'Send');
				submitBtn.css('cursor', '');
			},
			success: function(data) {
				var resultsDiv = $('#bulk-sms-results');

				// finishSend handles the final UI update — mark rows,
				// display results, re-enable the Send button.
				// Note creation happens server-side in Call_SMS when
				// saveasnote is in the POST body.
				var finishSend = function() {
					JethroSMS.onAJAXSuccess(data, resultsDiv);
					// Mark note-success on recipient rows if the server
					// indicates notes were saved.
					if (data.note_saved && data.sent && data.sent.recipients) {
						$.each(data.sent.recipients, function(personId) {
							$('tr[data-personid=' + personId + ']').addClass('note-success');
						});
					}
					var submitBtn = $("#smshttp .bulk-sms-submit");
					submitBtn.prop('disabled', false);
					submitBtn.prop('value', 'Send');
					submitBtn.css('cursor', '');
				};

				finishSend();

				// When the Messages tab is visible on this page, reload so the
				// newly sent SMS appears. (The AJAX response only updates row
				// highlighting, not the history list.)
				if ($('.messages-history-container').length) {
					location.reload();
				}
			}
		});
	});


	// -----------------------------------------------------------------------
	// Modal SMS submit handler (#send-sms-modal — single recipient)
	// Same flow as the bulk handler above, but for individual person modals:
	//   1. Send SMS to the single person via AJAX (?call=sms).
	//   2. JethroSMS.onAJAXSuccess() marks the person row with sms-success or sms-failure and returns TRUE if the modal
	//      should stay open.
	//   3. On pure success, the button briefly shows a checkmark then the modal auto-closes after 1 second.
	//   4. Note creation happens server-side in Call_SMS when the

	$('#send-sms-modal .sms-submit').on('click', function(event) {
		event.preventDefault();
		var resultsDiv = $("#send-sms-modal .results");
		resultsDiv.hide();
		$('#sms-send-response').hide().text('');

		var modalDiv = $("#send-sms-modal");
		var sms_message = $("#send-sms-modal .sms-message").val();
		if (!sms_message) {
			alert("Please enter a message first.");
			return false;
		}

		// Disable the button and show "Sending..." while the AJAX call runs
		var smsData;
		$(this).prop('disabled', true);
		$(this).html("Sending...");
		$("#send-sms-modal .results").hide();
		var sendButton = $(this);
		smsData = {
			personid: modalDiv.attr("data-personid"),
			ajax: 1,
			message: sms_message,
			sender: $('#sms_sender').val(),
			note_type: modalDiv.attr("data-note-type") || 'person'
		}
			// Collect per-recipient overrides from the preview panel's edit
			// textareas (name="message_overrides[PID]").
			var overrides = {};
			$('#send-sms-modal [name^="message_overrides["]').each(function() {
				var m = this.name.match(/message_overrides\[(\d+)\]/);
				if (m && $(this).val() !== '') overrides[m[1]] = $(this).val();
			});
			if (!$.isEmptyObject(overrides)) {
				smsData.message_overrides = overrides;
			}
		if ($('#send-sms-modal .saveasnote').is(':checked')) {
			smsData.saveasnote = 1;
			smsData.note_subject = $('#send-sms-modal input[name=note_subject]').val();
			smsData.note_action_date = $('#send-sms-modal input[name=note_action_date]').val();
		}
		if ($('#send-sms-modal .sms-schedule-toggle').is(':checked')) {
			var sendAt = $('#send-sms-modal .sms-schedule-datetime').val();
			if (sendAt) smsData.send_at = sendAt;
		}
		$.ajax({
			type: 'POST',
			dataType: 'JSON',
			url: '?call=sms',
			data: smsData,
			context: $(this),
			error: function(jqXHR, status, error) {
				var msg = 'Error sending SMS';
				if (status === 'parsererror' && jqXHR.responseText) {
					var div = document.createElement('div');
					div.innerHTML = jqXHR.responseText;
					var text = (div.textContent || div.innerText || '').trim();
					if (text) msg += ':\n\n' + text;
				} else if (error) {
					msg += ': ' + error;
				}
				alert(msg);
				sendButton.html("Send");
				sendButton.prop('disabled', false);
			},
			success: function(data) {
				var modalDiv = $("#send-sms-modal");

				// finishSend updates the UI. Note creation happens
				// server-side in Call_SMS (see saveasnote handling).
				var finishSend = function() {
					var showResults = JethroSMS.onAJAXSuccess(data, resultsDiv);
					// Mark note-success if the server saved notes
					if (data.note_saved && data.sent && data.sent.recipients) {
						$.each(data.sent.recipients, function(personId) {
							$('tr[data-personid=' + personId + ']').addClass('note-success');
						});
					}
					if (showResults) {
						resultsDiv.show();
						sendButton.html("Send");
						sendButton.removeClass('sms-success');
					} else {
						sendButton.html('<i class="icon-ok"></i> Sent').addClass('sms-success');
						modalDiv.modal('hide');
						sendButton.html("Send");
						sendButton.removeClass('sms-success');
						// When the Messages tab is visible, reload so the
						// newly sent SMS appears in the history list.
						if ($('.messages-history-container').length) {
							var newMsgId = data.sent && data.sent.msgid && data.sent.msgid[modalDiv.attr("data-personid")];
							if (newMsgId) location.hash = 'message_' + newMsgId;
							location.reload();
						}
					}
					sendButton.prop('disabled', false);
				};

				finishSend();
			}
		});
		return false;
	});
}


// -----------------------------------------------------------------------
// SMS scheduled-send — datetime default on check (visibility via Datastar)
// -----------------------------------------------------------------------
$(document).on('change', '.sms-schedule-toggle', function() {
	var $input = $(this).closest('.control-group').find('.sms-schedule-datetime');
	if (this.checked && !$input.val()) {
		var d = new Date();
		$input.val(d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0') + 'T' + String(d.getHours()).padStart(2,'0') + ':' + String(d.getMinutes()).padStart(2,'0'));
	}
	if (!this.checked) {
		$input.val('');
	}
});



/**
 * Basic HTML-escaping for the preview panel.
 */
JethroSMS.escapeHtml = function(str) {
	return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
};


/*
 * Process the JSON response from ?call=sms and display results in the given container.
 *
 * Handles five categories from the response: sent, failed, failed_archived, failed_blank, failed_opted_out.
 * For each, appends an alert to resultsDiv and marks the corresponding person rows
 * with success/failure classes and tooltips.
 *
 * @param {Object} data       JSON response from ?call=sms
 * @param {jQuery} resultsDiv  Container to append result alerts into
 * @returns {boolean}  TRUE if the modal should stay open (errors, failures, or nothing sent),
 *                     FALSE if everything succeeded and the modal can close.
 */
JethroSMS.onAJAXSuccess = function (data, resultsDiv) {
	var sentCount = 0,
	failedCount = 0,
	archivedCount = 0,
	blankCount = 0,
	optedOutCount = 0,
	rawresponse = '',
	statusBtn;

	if (data.sent !== undefined) { sentCount = data.sent.count; }
	if (data.failed !== undefined) { failedCount = data.failed.count; }
	if (data.failed_archived !== undefined) { archivedCount = data.failed_archived.count; }
	if (data.failed_blank !== undefined) { blankCount = data.failed_blank.count; }
	if (data.failed_opted_out !== undefined) { optedOutCount = data.failed_opted_out.count; }
	if (data.rawresponse !== undefined) { rawresponse = data.rawresponse; }

	resultsDiv.html(""); // Reset results in case there's something there
	var message = '';
	if (data.error!==undefined) {
		var $respDiv = resultsDiv.closest('#send-sms-modal').length ? $('#sms-send-response') : $('#sms-send-response-bulk');
		$respDiv.html(JethroSMS.escapeHtml(data.error).replace(/\n/g, '<br>')).show();
		return true;
	}
	if (sentCount > 0) {
		var verb = data.sent.scheduled ? 'scheduled for sending to' : 'successfully sent to';
		if (sentCount == 1) {
			var recip = data.sent.recipients[Object.keys(data.sent.recipients)[0]];
			message = 'Message ' + verb + ' ' + recip.first_name + ' ' + recip.last_name;
		} else {
			message = 'Message ' + verb + ' ' + sentCount + ' recipients';
		}
		message += ' <a href="?view=persons__messages">(SMS History Log)</a>';
		JethroSMS.appendAlert(resultsDiv, 'alert-success', message, sentCount == 1 ? null : data.sent.recipients);
		JethroSMS.markRecipientStatuses(data.sent.recipients, 'sms-success', 'SMS sent', false);

		if (!data.sent.confirmed) {
			JethroSMS.appendAlert(resultsDiv, '', 'Unable to confirm whether SMS sending was successful. Please check your system SMS configuration.');
		}
	}

	if (blankCount > 0) {
		if (blankCount == 1) {
			var recip = data.failed_blank.recipients[Object.keys(data.failed_blank.recipients)[0]];
			message = recip.first_name+' '+recip.last_name+' was not sent the message because they have no mobile number';
		} else {
			message = blankCount+' recipients were not sent the message because they have no mobile number';
		}
		JethroSMS.appendAlert(resultsDiv, '', message, blankCount == 1 ? null : data.failed_blank.recipients);
		JethroSMS.markRecipientStatuses(data.failed_blank.recipients, 'sms-failure', null, false);
	}

	if (archivedCount > 0) {
		if (archivedCount == 1) {
			var recip = data.failed_archived.recipients[Object.keys(data.failed_archived.recipients)[0]];
			message = recip.first_name+' '+recip.last_name+' was not sent the message because they are archived';
		} else {
			message = archivedCount+' archived persons were not sent the message';
		}
		JethroSMS.appendAlert(resultsDiv, '', message, archivedCount == 1 ? null : data.failed_archived.recipients);
		JethroSMS.markRecipientStatuses(data.failed_archived.recipients, 'sms-failure', 'SMS not sent - person is archived', false);
	}

	if (optedOutCount > 0) {
		if (optedOutCount == 1) {
			var recip = data.failed_opted_out.recipients[Object.keys(data.failed_opted_out.recipients)[0]];
			message = recip.first_name+' '+recip.last_name+' has opted out of receiving SMS';
		} else {
			message = optedOutCount+' recipients have opted out of receiving SMS';
		}
		JethroSMS.appendAlert(resultsDiv, '', message, optedOutCount == 1 ? null : data.failed_opted_out.recipients);
		JethroSMS.markRecipientStatuses(data.failed_opted_out.recipients, 'sms-failure', 'SMS not sent - opted out', false);
	}

	if (failedCount > 0) {
		if (failedCount == 1) {
			var recip = data.failed.recipients[Object.keys(data.failed.recipients)[0]];
			message = 'SMS sending failed for '+recip.first_name+' '+recip.last_name;
		} else {
			message = 'SMS sending failed for '+failedCount+' recipients';
		}
		JethroSMS.appendAlert(resultsDiv, 'alert-error', message, failedCount == 1 ? null : data.failed.recipients);
		JethroSMS.markRecipientStatuses(data.failed.recipients, 'sms-failure', 'SMS failed', false);
	}

	// Keep modal open for failures, errors, empty sends, or multi-recipient successes
	return ((failedCount > 0) || (archivedCount > 0) || (blankCount > 0) || (optedOutCount > 0) || ( sentCount == 0) || (data.error !== undefined) || (sentCount > 1));
}

/**
 * Append a Bootstrap alert div to `parent`, optionally followed by a
 * comma-separated list of recipient full names.
 *
 * Recipient names are HTML-escaped via jQuery's .text()/.html() round-trip.
 * The `content` parameter is inserted as raw HTML — callers are responsible
 * for sanitising any user-supplied values before passing them here.
 *
 * @param {jQuery}      parent      Container element to append into.
 * @param {string}      className   Bootstrap modifier: 'alert-success', 'alert-error', or ''.
 * @param {string}      content     Alert body as an HTML string.
 * @param {Object|null} recipients  personId → {first_name, last_name} map;
 *                                  null suppresses the recipient name list.
 */
JethroSMS.appendAlert = function(parent, className, content, recipients)
{
	if (recipients) {
		content += '<p>';
		var count = 0;
		var personID;
		for (personID in recipients) {
			if (recipients.hasOwnProperty(personID)) {
				if (count > 0) {
					content += ", ";
				}
				count = count + 1;
				content += $('<span>').text(recipients[personID]['first_name'] + " " + recipients[personID]['last_name']).html();
			}
		}
		content += '</p>';
	}
	parent.append('<div class="alert ' + className + '">' + content + '</div>');

}

/**
 * Apply post-send visual state to person rows in the list view.
 *
 * Iterates `recipients` by key and for each matching <tr data-personid="N">:
 *   - Adds `rowClass` (e.g. 'sms-success', 'sms-failure').
 *   - Sets the .btn-sms title attribute to `buttonMessage` when non-null.
 *   - Unchecks the row checkbox when `untick` is true.
 *
 * In practice this path is never reached: all callers pass untick=false.
 *
 * @param {Object}      recipients     personId → recipient data (only keys are iterated).
 * @param {string|null} rowClass       CSS class to add to the <tr>; null = skip.
 * @param {string|null} buttonMessage  Tooltip text for .btn-sms; null = skip.
 * @param {boolean}     untick         Uncheck the row checkbox when true.
 */
JethroSMS.markRecipientStatuses = function(recipients, rowClass, buttonMessage, untick)
{
	var personID;
	for (personID in recipients) {
		if (recipients.hasOwnProperty(personID)) {
			if (rowClass) $('tr[data-personid=' + personID + ']').addClass(rowClass);
			if (untick)
			$('tr[data-personid=' + personID + '] input[type=checkbox]').prop('checked', false);
			if (buttonMessage) $('tr[data-personid=' + personID + '] .btn-sms').attr('title', buttonMessage);
		}
	}
}

/**
 * SMS delivery status polling.
 *
 * renderSmsDeliveryStatusIndicator() emits data-on:load="@get(...)"
 * for non-final statuses (scheduled, queued, etc.). Datastar fetches
 * the updated HTML; if the status is still non-final, the replacement
 * span carries data-on:load again, creating a polling chain that stops
 * naturally when the status becomes final (delivered / failed / cancelled).
 */
$(document).ready(function() {
	JethroSMS.init();
	updateSmsCostSum();
});
$(window).on('resize', updateSmsCostSum);
/**
 * Sum the costs of all currently-visible rows and sync recipient-scroll
 * heights.  Single pass over the rows to avoid double DOM walk.
 * Called by filter input data-on:input handlers (Datastar-driven) and on page load.
 */
function updateSmsCostSum() {
	requestAnimationFrame(function() {
		var sum = 0;
		var rows = document.querySelectorAll('.sms-history-table tbody tr');
		for (var i = 0; i < rows.length; i++) {
			var row = rows[i];
			if (row.style.display === 'none') continue;
			// cost sum
			sum += parseFloat(row.dataset.cost || '0');
			// recipient height sync (reads offsetHeight — only correct
			// for laid-out rows; off-screen rows skipped by h > 0 guard)
			var bodyCell = row.querySelector('td:nth-child(4)');
			var scroll = row.querySelector('.sms-history-recipients-scroll');
			if (bodyCell && scroll) {
				var h = bodyCell.offsetHeight;
				if (h > 0) {
					scroll.style.maxHeight = h + 'px';
				}
			}
		}
		var el = document.getElementById('sms-cost-total');
		if (el) {
			el.textContent = 'Σ $' + sum.toFixed(2);
		}
	});
}
