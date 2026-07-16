<?php


/**
 * Jethro ↔ jethro-sms bridge — the Jethro-specific part of the SMS subsystem.
 *
 * The pure SMS engine (providers, value objects, templater, statusline maths)
 * lives in the `jethro-sms/` package at `Sms\` and `Sms\Providers\`.  This
 * file bridges it into Jethro: database persistence, session caching, person
 * resolution, permission checks, and HTML rendering.
 *
 * ── Structure ───────────────────────────────────────────────────────────
 *
 *  Domain types
 *    {@see JethroSmsRecipient}       — person + phone number, implements SmsRecipient
 *    {@see SmsRequestRecipients}     — categorised recipients from a request
 *    {@see JethroSmsDelivery}        — SmsDelivery with Jethro personId + databaseId
 *    {@see JethroSmsDeliveryBatch}   — SmsDeliveryBatch keyed by Jethro batch ID
 *    {@see DbLoggingSmsProvider}     — decorator that persists sends to the DB
 *    {@see SessionSmsCache}          — SmsCache backed by $_SESSION
 *
 *  Provider factory
 *    {@see getSmsProvider()}         — build + memoize the configured provider chain
 *    {@see resetSmsProviderCache()}  — invalidate the memoized provider
 *
 *  Send pipeline
 *    {@see sendSms()}               — send to recipients, with token expansion + DB logging
 *
 *  Request parsing
 *    {@see getRecipientsFromRequest()}      — resolve recipients from $_REQUEST params
 *    {@see getRecipientsFromPersonRecords()} — person DB rows → JethroSmsRecipient[]
 *    {@see getSenderFromRequest()}          — resolve sender, enforcing closed vocabulary
 *    {@see getLegitimateSenderStrings()}    — build the closed vocabulary
 *    {@see getCurrentUserMobileNumber()}    — resolve _USER_MOBILE_ sender
 *
 *  Delivery lifecycle
 *    {@see updateDelivery()}               — poll upstream for status changes
 *    {@see cancelSms()}                    — cancel a scheduled send
 *    {@see canCancelScheduledDelivery()}   — permission + capability gate
 *    {@see loadSmsBatch()}                 — reconstruct a SmsDeliveryBatch from the DB
 *
 *  Opt-out handling
 *    {@see getOptedOutPhoneNumbers()}       — upstream opt-out list, session-cached
 *    {@see isPersonOptedOut()}             — check a single Person
 *
 *  Display / HTML
 *    {@see formatSendSummary()}            — human-readable send result
 *    {@see renderSmsDeliveryStatusIndicator()} — status column for the SMS log
 *    {@see renderSmsDeliveryStatusIcon()}      — status icon for delivery tracking
 *    {@see renderRegistrationStepHtml()}       — sender registration form
 *    {@see printSmsModal()}                — the main send-SMS modal dialog
 *    {@see printBulkSmsForm()}             — bulk-action SMS form on list pages
 *    {@see printSenderDropdown()}          — sender selection control
 *    {@see printSaveAsNoteCheckbox()}      — "Create Note" checkbox
 *    {@see printNoteSubjectField()}        — note subject input
 *    {@see printNoteActionDateField()}     — note action date picker
 *    {@see printTextbox()}                 — message textarea with statusline + preview
 *
 *  Utilities
 *    {@see getSmsBalance()}                — account balance, cached per request
 *    {@see getCurrentSmsProviderKey()}     — short key of the current provider
 *    {@see getAvailableTokens()}           — person tokens for message personalisation
 *    {@see makeStatuslineConfig()}         — config snapshot for statusline maths
 *    {@see SmsStatusIcon}                  — canonical icons for delivery statuses
 *    {@see insertSms()}                    — write an SMS + deliveries to the DB
 *    {@see classifySmsStatus()}            — aggregate status for the SMS log
 *    {@see getPersonSmsHistory()}          — SMS log entries for a person
 *    {@see isUsable()} / {@see isConfigured()} / {@see isFeatureEnabled()}
 *    {@see usesUserMobile()}               — does the provider use phone-number senders
 *
 * ── Provider chain (outermost first) ─────────────────────────────────────
 *
 *   1. EJSmsProvider          — EasyJethro multi-tenant balance (when applicable)
 *   2. LocalBalanceSmsProvider — sms_purchases table (when SMS_BALANCE='database')
 *   3. OverridingSmsProvider   — SMS_SENDER enforcement + send-cooloff delay
 *   4. DbLoggingSmsProvider    — persists sends + deliveries to the DB
 *   5. TokenExpandingSmsProvider — per-recipient %token% expansion
 *   6. Raw provider            — FiveCentSmsV5Provider, CellcastSmsProvider, etc.
 *
 * @see jethro-sms/docs/reference/SMS_ARCHITECTURE.md
 */
namespace Jethro\Sms;

// Pure SMS engine (providers, value objects, templater, statusline maths)
// lives in the jethro-sms package; this loader brings in all of it.
require_once dirname(__DIR__) . '/jethro-sms/src/load.php';
require_once __DIR__ . '/Jethro/Sms/Providers/DbLoggingSmsProvider.php';
require_once __DIR__ . '/Jethro/Sms/SmsStatusIcon.php';
require_once __DIR__ . '/Jethro/Sms/JethroSmsRecipient.php';
require_once __DIR__ . '/Jethro/Sms/SmsRequestRecipients.php';
require_once __DIR__ . '/Jethro/Sms/JethroSmsDelivery.php';
require_once __DIR__ . '/Jethro/Sms/JethroSmsDeliveryBatch.php';
require_once __DIR__ . '/Jethro/Sms/SessionSmsCache.php';
require_once __DIR__ . '/Jethro/Sms/Providers/LocalBalanceSmsProvider.php';
require_once __DIR__ . '/Jethro/Sms/Providers/EJSmsProvider.php';

use Sms\AllSent;
use Sms\Failed;
use Sms\PartialSuccess;
use Sms\PhoneNumber;
use Sms\SenderID;
use Sms\SmsProvider;
use Sms\SmsRecipient;
use Sms\SmsSender;
use Sms\SmsStatus;use Sms\Providers\TemplateSmsProvider;

/**
 * Build the configured SMS provider chain.
 *
 * Auto-detects the raw provider class from constants if SMS_PROVIDER is
 * not set:
 *   1. SMS_SMSBROADCAST_USERNAME + SMS_SMSBROADCAST_PASSWORD → SmsBroadcastSmsProvider
 *   2. SMS_5CENTSMS_APIKEY_ID + SMS_5CENTSMS_APIKEY → FiveCentSmsV5Provider
 *   3. SMS_CELLCAST_APIKEY → CellcastSmsProvider
 *   4. SMS_HTTP_URL → FiveCentSmsV4Provider
 *
 * The returned provider chain is (outermost first):
 *   EJSmsProvider → LocalBalanceSmsProvider → OverridingSmsProvider → DbLoggingSmsProvider → TokenExpandingSmsProvider → RawProvider
 *
 * Memoized per unique ($tfa, $logToDb) pair. PHP constants are immutable
 * within a request, so the chain is identical on every call with the same
 * arguments; caching both success and failure Results is safe. All callers
 * share a single chain instance per request.
 * To reset the cache in tests, call {@link resetSmsProviderCache()}.
 *
 * @param bool $tfa      When true, 2FA_* constants are tried first for each field.
 * @param bool $logToDb  When true, wraps the chain in DbLoggingSmsProvider.
 * @return \Result<SmsProvider, string>  Success with the provider chain, or failure with an error message.
 */
function getSmsProvider(bool $tfa = false, bool $logToDb = true): \Result
{
	// Memoize by (tfa, logToDb) — both success and failure Results are stable
	// for the lifetime of the request since constants cannot change.
	$memoKey = ($tfa ? 't' : 'f') . ($logToDb ? 't' : 'f');
	if (isset($GLOBALS['__sms_provider_memo'][$memoKey])) {
		return $GLOBALS['__sms_provider_memo'][$memoKey];
	}

	// Raw-provider selection (SMS_PROVIDER / auto-detection) lives in the
	// jethro-sms package — single source for this bridge and the standalone
	// CLI. The bridge's job here is rendering not-configured help as HTML.
	$classResult = \Sms\resolveRawProviderClass();
	if ($classResult->isFailure()) {
		$error = $classResult->getError();
		if (empty($error['notConfigured'])) {
			return $GLOBALS['__sms_provider_memo'][$memoKey] = \Result::failure($error['message']);
		}
		$html = 'Set <code>SMS_PROVIDER</code> or define the required constants for one of these providers:<br><br>';
		foreach (\Sms\providerCandidates() as [$candidate, $label]) {
			if ($candidate::usagePreference() < 0) {
				continue;
			}
			// We used to display ents($candidate) here ('Sms\FiveCentSmsV5Privider') but it seemed too techie
			$html .= '<b>' . ents($label) . '</b><br>';
			foreach ($candidate::getConstants() as [$key, $required, $purpose]) {
				$defined = (string) ifdef($key, '') !== '';
				$icon = $defined
					? '<span style="color:#468847">&#10003;</span> '
					: '<span style="color:#b94a48">&#10007;</span> ';
				$html .= '&nbsp;&nbsp;' . $icon . '<a href="#' . ents($key) . '"><code>' . ents($key) . '</code></a> '
					. '<span style="color:' . ($required === 'required' ? '#b94a48' : '#999') . '">'
					. ents($required) . '</span>'
					. ' &mdash; ' . ents($purpose) . '<br>';
			}
			$html .= '<br>';
		}
		return $GLOBALS['__sms_provider_memo'][$memoKey] = \Result::failure('SMS not configured. ' . $html);
	}
	$class = $classResult->getValue();

	try {
		// Build the chain: raw provider → token expansion → (optional) DB logging.
		// Compute a config fingerprint so that cached sender IDs and balances
		// are invalidated when provider settings change (provider class,
		// API keys, URLs, etc.) — the SessionSmsCache treats fingerprint
		// mismatches as cache misses.
		$fingerprint = hash('xxh3', $class . ':' . implode(',', array_map(
			fn($c) => $c[0] . '=' . (string) ifdef($c[0], ''),
			$class::getConstants(),
		)));
		$provider = $class::fromConstants($tfa)->withCache(new SessionSmsCache('sms_cache_', $fingerprint));
	} catch (\Throwable $e) {
		return $GLOBALS['__sms_provider_memo'][$memoKey] = \Result::failure($e->getMessage());
	}

	$personCache = [];
	$templater = new \Sms\Templater();
	$templater->registerFunction('concat', fn(string ...$parts): string => implode('', $parts));
	$provider = new \Sms\TokenExpandingSmsProvider($provider, function (\Sms\SmsRecipient $r) use (&$personCache): array {
		if (!$r instanceof JethroSmsRecipient) {
			throw new \RuntimeException(
				'Token expansion requires JethroSmsRecipient recipients. '
				. 'Got ' . get_debug_type($r) . '. '
				. 'Use person IDs instead of raw phone numbers, or avoid %tokens% in the message.'
			);
		}
		$pid = $r->personId;
		if (!isset($personCache[$pid]) && isset($GLOBALS['system'])) {
			$res = $GLOBALS['system']->getDBObjectData('person', ['id' => $pid]);
			$personCache[$pid] = $res[$pid] ?? [];
		}
		$p = $personCache[$pid] ?? [];
		return [
			'firstname' => $p['first_name'] ?? '',
			'lastname'  => $p['last_name']  ?? '',
			'fullname'  => trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? '')),
		];
	}, $templater, getAvailableTokens(),
		shortenFn: fn(string $url): string => getUrlShortener()->shorten($url),
		previewShortenFn: fn(string $url): string => getUrlShortener()->previewShorten($url));

	if ($logToDb) {
		$provider = new \Jethro\Sms\Providers\DbLoggingSmsProvider($provider);
	}

	// OverridingSmsProvider sits outside DbLoggingSmsProvider so the DB layer
	// sees the cooloff-modified $sendAt.  userInitiated mirrors logToDb —
	// system-initiated sends (2FA, reminders) skip the cooloff undo window.
	$provider = new \Sms\OverridingSmsProvider($provider, userInitiated: $logToDb);

	if (defined('SMS_BALANCE') && SMS_BALANCE !== '') {
		$provider = new \Jethro\Sms\Providers\LocalBalanceSmsProvider($provider, SMS_BALANCE);
	}

+    $provider = new \Jethro\Sms\Providers\EJSmsProvider($provider);
+
	$result = \Result::success($provider);
	$GLOBALS['__sms_provider_memo'][$memoKey] = $result;
	return $result;
}

/**
 * Reset the getSmsProvider() memo cache.
 *
 * For test use only. Production code never needs this — constants are
 * immutable per request so the memoized chain is always correct.
 */
function resetSmsProviderCache(): void
{
	unset($GLOBALS['__sms_provider_memo']);
}



// ---------------------------------------------------------------------------
// Main send pipeline
// ---------------------------------------------------------------------------


/**
 * Send an SMS message.
 *
 * Accepts either the single-message form or the entries form:
 *   sendSms('Hello', $recips, $sender)
 *   sendSms([['message' => 'Hi', 'recipients' => [$r]]], [], $sender)
 *
 * @param string|array<int, array{message: string, recipients: SmsRecipient[]}> $messageOrEntries
 * @param SmsRecipient[] $recipients  Only used in the single-message form. Pass [] for entries form.
 * @param bool $logToDb  When false, skips DB logging (use for system-initiated sends with no logged-in user context, e.g. 2FA OTP).
 * @return \Result<\Sms\SmsDeliveryBatch, string>
 */
function sendSms(
	string|array $messageOrEntries,
	array        $recipients,
	SmsSender    $sender,
	?int         $sendAt = null,
	bool         $preview = false,
	bool         $logToDb = true,
): \Result
{
	$entries = is_string($messageOrEntries)
		? [['message' => $messageOrEntries, 'recipients' => $recipients]]
		: $messageOrEntries;

	if ($entries === []) {
		return \Result::failure('No entries to send');
	}

	// Send
	$providerResult = getSmsProvider(logToDb: $logToDb);
	if ($providerResult->isFailure()) {
		return \Result::failure($providerResult->getError());
	}
	$provider = $providerResult->getValue();

	// Auto-shorten bare https?:// URLs: wrap them in %(shorten "...")%
	// Gated by SMS_SHORTEN_URLS — explicit %(shorten url)% tokens always work.
	if (ifdef('SMS_SHORTEN_URLS', false) && ifdef('URLSHORTENER', '') !== '') {
		foreach ($entries as $i => $entry) {
			$msg = $entry['message'];
			// Don't double-wrap: explicit %(shorten ...)% tokens skip auto-shorten.
			if (!str_contains($msg, '%(shorten')) {
				$entries[$i]['message'] = preg_replace_callback(
					'{(https?://[^\s"\')\][<>]+)}',
					function (array $m): string {
						if (strlen($m[0]) > 26) {
							return '%(shorten "' . $m[1] . '")%';
						}
						return $m[0];
					},
					$msg,
				);
			}
		}
	}

	$outcome = $provider->send(
		entries: $entries,
		sender: $sender,
		sendAt: $sendAt,
		preview: $preview,
	);
	if ($outcome->isFailure()) {
		return \Result::failure($outcome->getError());
	}

	$batch = $outcome->getValue();


    return \Result::success($batch);
}


// ---------------------------------------------------------------------------
// Request parsing
// ---------------------------------------------------------------------------

/**
 * Resolve SMS recipients from the current HTTP request.
 *
 * Reads `$_REQUEST` params set by various SMS-initiating UI flows:
 *
 *   - `queryid`   — a saved person query
 *   - `groupid`   — a person group
 *   - `roster_view` + `start_date`/`end_date` — roster view assignees
 *   - `personid`  — comma-separated or array of person IDs
 *
 * When `sms_type` is `'family'`, selected persons are expanded to all
 * adults in their families (using Age_Bracket and Family lookups).
 *
 * Recipients whose phone number appears in the upstream provider's opt-out
 * list are moved from `$rawRecips` into `$optedOut` before constructing
 * the result.  Numbers are normalised to international format for comparison.
 *
 * @return SmsRequestRecipients  Categorised recipients ready for sending and UI display
 * @see getRecipientsFromPersonRecords()
 * @see call_sms.class.php
 */
function getRecipientsFromRequest(): SmsRequestRecipients
{
	$blanks = $archived = $rawRecips = $optedOut = [];

	if (!empty($_REQUEST['queryid'])) {
		$query = $GLOBALS['system']->getDBObject('person_query', (int)$_REQUEST['queryid']);
		$personids = $query->getResultPersonIDs();
		$rawRecips = $GLOBALS['system']->getDBObjectData('person', ['(id' => $personids, '!mobile_tel' => '', '!(status' => \Person_Status::getArchivedIDs()], 'AND');
		$blanks = $GLOBALS['system']->getDBObjectData('person', ['(id' => $personids, 'mobile_tel' => '', '!(status' => \Person_Status::getArchivedIDs()], 'AND');
		$archived = $GLOBALS['system']->getDBObjectData('person', ['(id' => $personids, '(status' => Person_Status::getArchivedIDs()], 'AND');
	} else if (!empty($_REQUEST['groupid'])) {
		$group = $GLOBALS['system']->getDBObject('person_group', (int)$_REQUEST['groupid']);
		$personids = array_keys($group->getMembers());
		$rawRecips = $GLOBALS['system']->getDBObjectData('person', ['(id' => $personids, '!mobile_tel' => '', '!(status' => \Person_Status::getArchivedIDs()], 'AND');
		$blanks = $GLOBALS['system']->getDBObjectData('person', ['(id' => $personids, 'mobile_tel' => '', '!(status' => \Person_Status::getArchivedIDs()], 'AND');
		$archived = $GLOBALS['system']->getDBObjectData('person', ['(id' => $personids, '(status' => Person_Status::getArchivedIDs()], 'AND');
	} else if (!empty($_REQUEST['roster_view'])) {
		$rawRecips = [];
		foreach ((array)$_REQUEST['roster_view'] as $viewid) {
			$view = $GLOBALS['system']->getDBObject('roster_view', (int)$viewid);
			$rawRecips += $view->getAssignees($_REQUEST['start_date'], $_REQUEST['end_date']);
		}
	} else {
		if (empty($_REQUEST['personid'])) {
			$rawRecips = $blanks = $archived = [];
		} else {
			// Normalise: a comma-separated string (from roster "SMS all") or an array
			$personids = \is_array($_REQUEST['personid']) ? $_REQUEST['personid'] : explode(',', $_REQUEST['personid']);
			$rawRecips = $GLOBALS['system']->getDBObjectData('person', ['(id' => $personids, '!mobile_tel' => '', '!(status' => \Person_Status::getArchivedIDs()], 'AND');
			$blanks = $GLOBALS['system']->getDBObjectData('person', ['(id' => $personids, 'mobile_tel' => '', '!(status' => \Person_Status::getArchivedIDs()], 'AND');
			$archived = $GLOBALS['system']->getDBObjectData('person', ['(id' => $personids, '(status' => \Person_Status::getArchivedIDs()], 'AND');
		}
	}

	// Family mode: expand selected persons to all adults in their families.
	// Use $rawRecips + $blanks as seed so persons without mobiles still contribute their family.
	if (($rawRecips !== [] || $blanks !== []) && (array_get($_REQUEST, 'sms_type', 'person') === 'family')) {
		$personids = array_keys($rawRecips + $blanks);
		$GLOBALS['system']->includeDBClass('family');
		$GLOBALS['system']->includeDBClass('age_bracket');
		$families = \Family::getFamilyDataByMemberIDs($personids);
		$familyIds = array_keys($families);
		if ($familyIds !== []) {
			$adultBrackets = \Age_Bracket::getAdults();
			$rawRecips = $GLOBALS['system']->getDBObjectData('person', [
				'(age_bracketid' => $adultBrackets,
				'(familyid' => $familyIds,
				'!mobile_tel' => '',
				'!(status' => \Person_Status::getArchivedIDs(),
			], 'AND');
			$blanks = $GLOBALS['system']->getDBObjectData('person', [
				'(age_bracketid' => $adultBrackets,
				'(familyid' => $familyIds,
				'mobile_tel' => '',
				'!(status' => \Person_Status::getArchivedIDs(),
			], 'AND');
			$archived = $GLOBALS['system']->getDBObjectData('person', [
				'(age_bracketid' => $adultBrackets,
				'(familyid' => $familyIds,
				'(status' => \Person_Status::getArchivedIDs(),
			], 'AND');
		} else {
			$rawRecips = $blanks = $archived = $optedOut = [];
		}
	}

	// Filter out recipients whose mobile numbers are in the upstream opt-out list.
	// Normalise to international format for comparison (providers store numbers
	// without "+" prefix, e.g. "61491570157").
	$optedOut = [];
	if ($rawRecips !== []) {
		$optedOutNumbers = getOptedOutPhoneNumbers();
		if ($optedOutNumbers !== []) {
			$optedOut = [];
			$survivors = [];
			foreach ($rawRecips as $pid => $data) {
                $mobile = (string) ($data['mobile_tel'] ?? '');
                if ($mobile === '') continue;
                $intl = (new \Sms\PhoneNumber($mobile))->internationalise('0', '61');
				if (\in_array($intl->value, $optedOutNumbers, true)) {
					$optedOut[$pid] = $data;
				} else {
					$survivors[$pid] = $data;
				}
			}
			$rawRecips = $survivors;
		}
	}

	return new SmsRequestRecipients(
		recipients: getRecipientsFromPersonRecords($rawRecips),
		blanks: $blanks,
		archived: $archived,
		optedOut: $optedOut,
		rawPersonRecords: $rawRecips,
	);
}

/**
 * Convert person records from the database into JethroSmsRecipient objects.
 *
 * @param array{}[] $personRecords  Array of person rows (must have 'id' and 'mobile_tel' keys)
 * @return JethroSmsRecipient[]
 */
function getRecipientsFromPersonRecords(array $personRecords): array
{
	$recipients = [];
	foreach ($personRecords as $personid => $persondata) {
		if (!empty($persondata['mobile_tel'])) {
			$recipients[] = new JethroSmsRecipient(
				personId: (int)$personid,
				number: new \Sms\PhoneNumber($persondata['mobile_tel']),
			);
		}
	}
	return $recipients;
}

/**
 * Resolve the SMS sender from the HTTP request.
 *
 * Only accepts legitimate sender values from the closed vocabulary defined
 * by {@see getLegitimateSenderStrings()}: the symbolic `_USER_MOBILE_` token
 * (resolved from the session user), the trusted `SMS_SENDER` constant, and
 * sender IDs in `SMS_SENDER_OPTIONS` or discovered from the provider.
 *
 * Arbitrary raw phone numbers and unrecognised strings are rejected —
 * the dropdown never produces them and they represent potential sender
 * spoofing (see docs/security/09_sms_sender_spoofing_via_request.md).
 *
 * Returns null if no valid sender can be determined.
 */
function getSenderFromRequest(): ?SmsSender
{
    $rawSender = $_REQUEST['sender'] ?? null;

    // Resolve through the trust hierarchy:
    //   request param → SMS_SENDER constant → session user's mobile
    $sender = null;
    if ($rawSender !== null && $rawSender !== '') {
        $sender = $rawSender;
    } elseif (defined('SMS_SENDER') && SMS_SENDER !== '') {
        $sender = SMS_SENDER;
    } else {
        $sender = getCurrentUserMobileNumber();
    }

    if ($sender === null) {
        return null;
    }

    // _USER_MOBILE_ always resolves to the session user's actual number.
    if ($sender === '_USER_MOBILE_') {
        return getCurrentUserMobileNumber();
    }

    // Fallback path — no request sender specified, no SMS_SENDER.
    // getCurrentUserMobileNumber() returns a PhoneNumber directly.
    if ($sender instanceof SmsSender) {
        return $sender;
    }

    // SMS_SENDER constant: trusted configuration, accept as-is.
    if (defined('SMS_SENDER') && $sender === SMS_SENDER) {
        if (is_numeric($sender)) {
            return new PhoneNumber((string) $sender);
        }
        return new SenderID((string) $sender);
    }

    // Explicit request value (not _USER_MOBILE_, not SMS_SENDER, not a
    // fallback object).  Only accept if it appears in the legitimate
    // sender vocabulary — SmsSenderIDs in the admin allowlist or
    // discovered from the provider.  Raw numbers and random strings fail
    // here and return null (no valid sender).
    $legitimate = getLegitimateSenderStrings();
    if (\in_array($sender, $legitimate, true)) {
        return new SenderID((string) $sender);
    }

    return null;
}

/**
 * Collect the full set of legitimate sender string values from the
 * SMS_SENDER_OPTIONS allowlist and provider-discovered sender IDs.
 *
 * Statically cached — one provider lookup on first call, free afterwards.
 *
 * @return string[]
 */
function getLegitimateSenderStrings(): array
{
    static $legitimate = null;
    if ($legitimate !== null) {
        return $legitimate;
    }

    $legitimate = [];

    // 1. SMS_SENDER_OPTIONS allowlist (minus special tokens: _USER_MOBILE_ is
    //    handled by getSenderFromRequest(); _SENDER_IDS_ expands to real IDs
    //    via the provider call in step 2 below).
    if (defined('SMS_SENDER_OPTIONS') && SMS_SENDER_OPTIONS !== '') {
        foreach (array_map('trim', explode(',', SMS_SENDER_OPTIONS)) as $id) {
            if ($id !== '' && $id !== '_USER_MOBILE_' && $id !== '_SENDER_IDS_') {
                $legitimate[] = $id;
            }
        }
    }

    // 2. Provider-discovered sender IDs (session-cached by the provider,
    //    and getSmsProvider() is memoized — cheap on repeated calls).
    $providerResult = getSmsProvider();
    if ($providerResult->isSuccess()) {
        $idsResult = $providerResult->getValue()->getSenderIds(getAll: true);
        if ($idsResult->isSuccess()) {
            foreach ($idsResult->getValue() as $senderId) {
                $legitimate[] = $senderId->value;
            }
        }
    }

    return $legitimate;
}

/**
 * Resolve the sender's phone number.
 *
 * Checks: Current user's mobile number
 */
function getCurrentUserMobileNumber(): ?\Sms\PhoneNumber
{
    $currentUser = $GLOBALS['user_system']->getCurrentUser();
    if (!empty($currentUser['mobile_tel'])) {
		return new \Sms\PhoneNumber($currentUser['mobile_tel']);
	}
	return null;
}


// ---------------------------------------------------------------------------
// Delivery lifecycle
// ---------------------------------------------------------------------------

/**
 * Fetch delivery status for a previously sent SMS.
 *
 * Caching (smsdelivery) is handled transparently by DbLoggingSmsProvider
 * wired into getSmsProvider().
 *
 * @return \Result<\Sms\SmsDelivery, string>
 */
function updateDelivery(\Sms\SmsDelivery $delivery): \Result
{
	$providerResult = getSmsProvider();
	if ($providerResult->isFailure()) {
		return \Result::failure($providerResult->getError());
	}

	return $providerResult->getValue()->updateDelivery($delivery);
}

/**
 * Cancel the deliveries in a batch.
 *
 * Delegates to the provider's cancel(). DB persistence is handled transparently
 * by DbLoggingSmsProvider wired into getSmsProvider().
 *
 * @return \Result<\Sms\SmsDeliveryBatch, string>
 */
function cancelSms(\Sms\SmsDeliveryBatch $batch): \Result
{
	$providerResult = getSmsProvider();
	if ($providerResult->isFailure()) {
		return \Result::failure($providerResult->getError());
	}

	return $providerResult->getValue()->cancel($batch);
}
/**
 * Whether the current user can cancel a scheduled SMS delivery.
 * Does not check delivery status — call only for SCHEDULED deliveries.
 */
function canCancelScheduledDelivery(): bool
{
    if (!$GLOBALS['user_system']->havePerm(PERM_SENDSMS)) {
        return false;
    }
    $providerResult = getSmsProvider();
    if ($providerResult->isFailure()) {
        return false;
    }
    return $providerResult->getValue()->hasCapability(\Sms\SmsCapability::DEFERRED_SEND_CANCEL);
}

/**
 * Load a persisted SMS send as a batch, for cancellation/inspection.
 *
 * Deliveries are JethroSmsDelivery objects hydrated with real recipients,
 * statuses, remote IDs and database IDs.  senderPersonId carries sms.sender
 * for ownership checks (null = script-initiated send).
 *
 * @return \Result<JethroSmsDeliveryBatch, string>  Failure if no such sms row.
 */
function loadSmsBatch(int $smsId): \Result
{
	$db = $GLOBALS['db'];

	$smsRow = $db->queryRow(
		'SELECT sender FROM sms WHERE id = ' . (int)$smsId
	);
	if (!$smsRow) {
		return \Result::failure('SMS send not found');
	}

	$senderPersonId = isset($smsRow['sender']) && $smsRow['sender'] !== null
		? (int)$smsRow['sender']
		: null;

	$rows = $db->queryAll(
		'SELECT sd.id, sd.personid, sd.remote_id, sd.status, sd.body, p.mobile_tel'
		. ' FROM smsdelivery sd'
		. ' JOIN _person p ON p.id = sd.personid'
		. ' WHERE sd.sms_id = ' . (int)$smsId
	);

	$deliveries = [];
	foreach ((array)$rows as $row) {
		$deliveries[] = new JethroSmsDelivery(
			inner: new \Sms\SmsDelivery(
				recipient: new \Sms\PhoneNumber($row['mobile_tel']),
				status: \Sms\SmsStatus::fromMySql((string)$row['status']),
				remoteId: $row['remote_id'],
				message: $row['body'],
			),
			recipientPersonId: (int)$row['personid'],
			databaseId: (int)$row['id'],
		);
	}

	return \Result::success(new JethroSmsDeliveryBatch(
		batchId: (string)$smsId,
		deliveries: $deliveries,
		senderPersonId: $senderPersonId,
	));
}


// formatDeliveryLine() and formatDeliveryLines() are defined in
// jethro-sms/src/functions.php (namespace Sms) — shared with the CLI.

/**
 * Compare upstream provider deliveries with local database records.
 *
 * Calls listRecentDeliveries($since) on the provider, formats the result,
 * queries smsdelivery+sms for the same period, formats that, and diffs them.
 *
 * @param int|null $since Unix timestamp, or null for last 24 hours
 * @return string  Diff output (empty if synchronized)
 */
function checkSynchronized(?int $since = null): string
{
	$since = $since ?? time() - 86400;
	$sinceDate = date('Y-m-d H:i:s', $since);

	// --- Upstream ---
	$providerResult = getSmsProvider(logToDb: false);
	if ($providerResult->isFailure()) {
		return "ERROR: Cannot get SMS provider: " . $providerResult->getError() . "\n";
	}
	$provider = $providerResult->getValue();

	$upstreamResult = $provider->listRecentDeliveries($since);
	if ($upstreamResult->isFailure()) {
		return "ERROR: listRecentDeliveries failed: " . $upstreamResult->getError() . "\n";
	}
	/** @var \Sms\SmsDelivery[] $upstreamDeliveries */
	$upstreamDeliveries = $upstreamResult->getValue();

	$upstreamText = \Sms\formatDeliveryLines($upstreamDeliveries);
	// --- Local DB ---
	$db = $GLOBALS['db'];
	$dbRows = $db->queryAll(
		'SELECT sd.remote_id, sd.status, sd.delivered_at, sd.body,'
		. ' p.mobile_tel, s.created AS send_time'
		. ' FROM smsdelivery sd'
		. ' JOIN sms s ON s.id = sd.sms_id'
		. ' LEFT JOIN _person p ON p.id = sd.personid'
		. ' WHERE s.created >= ' . $db->quote($sinceDate)
		. ' ORDER BY s.created'
	);

	$dbDeliveries = [];
	foreach ($dbRows as $row) {
		$status = \Sms\SmsStatus::fromMySql((string)$row['status']);
		$sendTs = $row['send_time'] !== null ? strtotime((string)$row['send_time']) : null;
		$delTs = $row['delivered_at'] !== null ? strtotime((string)$row['delivered_at']) : null;
		$dbDeliveries[] = new \Sms\SmsDelivery(
			recipient: new \Sms\PhoneNumber((string)($row['mobile_tel'] ?? '0000000000')),
			status: $status,
			remoteId: $row['remote_id'] !== null ? (string)$row['remote_id'] : null,
			sendTimestamp: $sendTs ?: null,
			deliveryTimestamp: $delTs ?: null,
			message: (string)($row['body'] ?? ''),
		);
	}

	$dbText = \Sms\formatDeliveryLines($dbDeliveries);

	// --- Diff ---
	$upstreamFile = tempnam(sys_get_temp_dir(), 'sms-up-');
	$dbFile = tempnam(sys_get_temp_dir(), 'sms-db-');
	file_put_contents($upstreamFile, $upstreamText);
	file_put_contents($dbFile, $dbText);

	$diff = shell_exec('/usr/bin/diff ' . escapeshellarg($upstreamFile) . ' ' . escapeshellarg($dbFile) . ' 2>&1');

	unlink($upstreamFile);
	unlink($dbFile);

	$upCount = count($upstreamDeliveries);
	$dbCount = count($dbDeliveries);
	$header = "Upstream ($upCount) vs local DB ($dbCount) since $sinceDate:\n\n";

	if ($diff === null || $diff === '') {
		return $header . "(in sync — no differences)\n";
	}

	return $header . $diff;
}

/**
 * Import SMS history from the upstream provider.
 *
 * Fetches deliveries from listRecentDeliveries($since), stages them
 * verbatim in scratch tables (sms_new + smsdelivery_new), refines the
 * staged rows with SQL — feed dedup, batch grouping, personid resolution —
 * then deletes the staged rows already present locally and bulk-inserts
 * the rest.  See docs/docs/developer/reference/sms/history-sync.md for a
 * step-by-step description of the pipeline.
 *
 * Existing rows cannot be assumed to carry a remote_id, so duplicate
 * detection is heuristic:
 *  - a batch matches an sms row with the same body created within 10 minutes;
 *  - a delivery matches an smsdelivery row under that batch by remote_id
 *    when both sides have one, otherwise by recipient phone number
 *    (via personid or the raw_response destination), compared in
 *    international format.
 * New deliveries for a partially-imported batch are attached to the existing
 * sms row rather than creating a duplicate batch.
 *
 * The scratch tables are real (not TEMPORARY — MySQL cannot reference a
 * temporary table twice in one query, which the SQL refinement needs), so
 * concurrent runs are serialised with a named lock, and the CREATE/DROP
 * TABLE statements implicitly commit: do not call this function inside a
 * transaction whose atomicity you need to keep.
 *
 * @param int|null $since Unix timestamp, or null for last 24 hours
 * @return array{deleted: int, imported: int, batches: int, skipped: int}
 *   deleted  = staged deliveries discarded as duplicates of local rows,
 *   imported = smsdelivery rows inserted,
 *   batches  = sms rows created,
 *   skipped  = upstream batches whose deliveries were all already present.
 */
function synchronizeHistory(?int $since = null): array
{
	$since = $since ?? time() - 86400;

	$db = $GLOBALS['db'];

	// --- Fetch upstream ---
	$providerResult = getSmsProvider(logToDb: false);
	if ($providerResult->isFailure()) {
		throw new \RuntimeException('Cannot get SMS provider: ' . $providerResult->getError());
	}
	$provider = $providerResult->getValue();

	$upstreamResult = $provider->listRecentDeliveries($since);
	if ($upstreamResult->isFailure()) {
		throw new \RuntimeException('listRecentDeliveries failed: ' . $upstreamResult->getError());
	}
	/** @var \Sms\SmsDelivery[] $deliveries */
	$deliveries = $upstreamResult->getValue();
	$providerKey = $provider->getKey();

	// SQL fragment: normalise a phone expression to international digits
	// ('61…') so upstream numbers, _person.mobile_tel and raw_response
	// destinations all compare in the same form.  Mirrors
	// PhoneNumber::internationalise('0', '61').
	$intlSql = function (string $expr): string {
		$digits = "REGEXP_REPLACE(CONVERT(COALESCE($expr, '') USING utf8mb4), '[^0-9]', '')";
		// The explicit COLLATE lets the result compare against the staging
		// tables regardless of the source column's own collation.
		return "(CASE WHEN $digits LIKE '0%' THEN CONCAT('61', SUBSTRING($digits, 2)) ELSE $digits END) COLLATE utf8mb4_unicode_ci";
	};

	// Ranking used whenever several staged records describe the same
	// delivery (multipart segments, a 'sent' record followed by a
	// 'delivered' record): keep the most final status, preferring records
	// that carry a delivery time, then the latest record.
	$keepBestOrder = "FIELD(status, 'delivered', 'failed', 'cancelled', 'sent', 'sending', 'in-progress', 'queued', 'scheduled', 'test-message', 'unknown'), delivered_at IS NULL, id DESC";

	// Scratch tables are shared real tables, so serialise concurrent syncs.
	$locked = (int)$db->queryOne("SELECT GET_LOCK(CONCAT(DATABASE(), '.sms_sync'), 5)");
	if ($locked !== 1) {
		throw new \RuntimeException('Another SMS history synchronization is already running');
	}

	$ownTransaction = false;
	try {
		// (Re)build the scratch tables first: CREATE/DROP TABLE implicitly
		// commits, so all DDL happens before our transaction opens.
		$db->exec('DROP TABLE IF EXISTS smsdelivery_new, sms_new');
		$db->exec(
			'CREATE TABLE smsdelivery_new ('
			. ' id INT AUTO_INCREMENT PRIMARY KEY,'
			. " phone_intl VARCHAR(32) NOT NULL DEFAULT ''," // international digits, '' if unknown
			. ' personid INT NULL,'
			. ' remote_id VARCHAR(255) NULL,'
			. ' raw_response TEXT NULL,'
			. ' body TEXT NULL,'
			. ' send_at DATETIME NULL,'
			. ' delivered_at DATETIME NULL,'
			. ' status VARCHAR(20) NOT NULL,'
			. ' grp INT NULL,'      // session number within body (1-hour gap rule)
			. ' batch_id INT NULL,' // sms_new.id after batch grouping
			. ' sms_id INT NULL'    // destination sms.id once the batch is resolved
			. ') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
		);
		$db->exec(
			'CREATE TABLE sms_new ('
			. ' id INT AUTO_INCREMENT PRIMARY KEY,'
			. ' body TEXT NOT NULL,'
			. ' created DATETIME NOT NULL,'
			. ' grp INT NOT NULL,'
			. ' sms_id INT NULL'    // matching existing sms.id, if any
			. ') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
		);

		$ownTransaction = !$db->inTransaction();
		if ($ownTransaction) {
			$db->beginTransaction();
		}
		// --- Stage the upstream feed verbatim ---
		$values = [];
		foreach ($deliveries as $d) {
			$digits = preg_replace('/\D/', '', $d->recipient()->value);
			$phoneIntl = $digits === '' ? '' : (new \Sms\PhoneNumber($digits))->internationalise('0', '61')->value;
			$values[] = '('
				. $db->quote($phoneIntl) . ', '
				. ($d->remoteId() !== null ? $db->quote($d->remoteId()) : 'NULL') . ', '
				. $db->quote($d->rawResponse()) . ', '
				. ($d->message() !== null ? $db->quote($d->message()) : 'NULL') . ', '
				. ($d->sendTimestamp() !== null ? $db->quote(date('Y-m-d H:i:s', $d->sendTimestamp())) : 'NULL') . ', '
				. ($d->deliveryTimestamp() !== null ? $db->quote(date('Y-m-d H:i:s', $d->deliveryTimestamp())) : 'NULL') . ', '
				. $db->quote($d->status()->toMySql())
				. ')';
		}
		foreach (array_chunk($values, 200) as $chunk) {
			$db->exec(
				'INSERT INTO smsdelivery_new (phone_intl, remote_id, raw_response, body, send_at, delivered_at, status)'
				. ' VALUES ' . implode(', ', $chunk)
			);
		}

		// --- Refine: group into batches — same body, deliveries within 1 hour ---
		// Gap-based sessions per body: a >1-hour silence starts a new batch
		// (matches the migration logic in upgrades/2026-upgrade-to-2.40-sms.sql;
		// we don't have sender from upstream, so we cluster by body alone).
		// Deliveries without a send time join the batch they sort next to.
		$db->exec(
			'UPDATE smsdelivery_new d JOIN ('
			. ' SELECT id, SUM(brk) OVER (PARTITION BY body_key ORDER BY send_at IS NULL, send_at, id) AS grp'
			. ' FROM ('
			. "  SELECT id, COALESCE(body, '') AS body_key, send_at,"
			. '   CASE WHEN TIMESTAMPDIFF(SECOND,'
			. "    LAG(send_at) OVER (PARTITION BY COALESCE(body, '') ORDER BY send_at IS NULL, send_at, id),"
			. '    send_at) > 3600 THEN 1 ELSE 0 END AS brk'
			. '  FROM smsdelivery_new'
			. ' ) gaps'
			. ') g ON g.id = d.id'
			. ' SET d.grp = g.grp'
		);
		$db->exec(
			'INSERT INTO sms_new (body, created, grp)'
			. " SELECT COALESCE(body, ''), COALESCE(MIN(send_at), NOW()), grp"
			. ' FROM smsdelivery_new'
			. " GROUP BY COALESCE(body, ''), grp"
		);
		$db->exec(
			'UPDATE smsdelivery_new d'
			. " JOIN sms_new b ON b.body = COALESCE(d.body, '') AND b.grp = d.grp"
			. ' SET d.batch_id = b.id'
		);

		// --- Refine: collapse the feed to one record per delivery ---
		// Paged report fetches can return the same record twice (records shift
		// across page boundaries between fetches), and one recipient can have
		// several report entries within a batch.  Dedup by remote_id when
		// present, and by recipient phone within each batch.
		$db->exec(
			'DELETE FROM smsdelivery_new WHERE id IN ('
			. ' SELECT id FROM ('
			. '  SELECT id, ROW_NUMBER() OVER (PARTITION BY remote_id ORDER BY ' . $keepBestOrder . ') AS rn'
			. "  FROM smsdelivery_new WHERE COALESCE(remote_id, '') <> ''"
			. ' ) ranked WHERE rn > 1'
			. ')'
		);
		$db->exec(
			'DELETE FROM smsdelivery_new WHERE id IN ('
			. ' SELECT id FROM ('
			. '  SELECT id, ROW_NUMBER() OVER (PARTITION BY batch_id, phone_intl ORDER BY ' . $keepBestOrder . ') AS rn'
			. "  FROM smsdelivery_new WHERE phone_intl <> ''"
			. ' ) ranked WHERE rn > 1'
			. ')'
		);

		// --- Refine: resolve personid from the recipient phone number ---
		$db->exec(
			'UPDATE smsdelivery_new d JOIN ('
			. ' SELECT ' . $intlSql('mobile_tel') . ' AS phone_intl, MIN(id) AS personid'
			. ' FROM _person GROUP BY phone_intl'
			. ') p ON p.phone_intl = d.phone_intl'
			. " SET d.personid = p.personid WHERE d.phone_intl <> ''"
		);

		// --- Match batches against existing sms rows: same body ±10 minutes ---
		// Existing rows may predate remote_id logging, so body+time is the
		// only batch identity we can rely on.
		$db->exec(
			'UPDATE sms_new b SET b.sms_id = ('
			. ' SELECT s.id FROM sms s'
			. ' WHERE s.body = b.body COLLATE utf8mb4_unicode_ci'
			. '  AND s.created BETWEEN b.created - INTERVAL 10 MINUTE AND b.created + INTERVAL 10 MINUTE'
			. ' ORDER BY ABS(TIMESTAMPDIFF(SECOND, s.created, b.created)) LIMIT 1'
			. ')'
		);
		$db->exec('UPDATE smsdelivery_new d JOIN sms_new b ON b.id = d.batch_id SET d.sms_id = b.sms_id');

		// --- Delete staged deliveries already present under their matched sms
		// row: by remote_id when both sides have one …
		$deleted = (int)$db->exec(
			'DELETE d FROM smsdelivery_new d'
			. ' JOIN smsdelivery sd ON sd.sms_id = d.sms_id AND sd.remote_id = d.remote_id COLLATE utf8mb4_unicode_ci'
			. " WHERE d.remote_id <> ''"
		);
		// … otherwise by recipient phone (via personid or the raw_response
		// destination).
		$deleted += (int)$db->exec(
			'DELETE d FROM smsdelivery_new d'
			. ' JOIN smsdelivery sd ON sd.sms_id = d.sms_id'
			. ' LEFT JOIN _person p ON p.id = sd.personid'
			. " WHERE d.phone_intl <> '' AND d.phone_intl IN ("
			. $intlSql('p.mobile_tel') . ', '
			. $intlSql("CASE WHEN JSON_VALID(sd.raw_response) THEN JSON_UNQUOTE(JSON_EXTRACT(sd.raw_response, '\$.destination')) ELSE NULL END")
			. ')'
		);

		// --- Counts and final inserts ---
		$skipped = (int)$db->queryOne(
			'SELECT COUNT(*) FROM sms_new b'
			. ' WHERE b.sms_id IS NOT NULL'
			. ' AND NOT EXISTS (SELECT 1 FROM smsdelivery_new d WHERE d.batch_id = b.id)'
		);

		// Create sms rows for genuinely new batches that still have deliveries
		$newBatches = $db->queryAll(
			'SELECT b.id, b.body, b.created FROM sms_new b'
			. ' WHERE b.sms_id IS NULL'
			. ' AND EXISTS (SELECT 1 FROM smsdelivery_new d WHERE d.batch_id = b.id)'
		);
		foreach ($newBatches as $batch) {
			$db->exec(
				'INSERT INTO sms (body, created, scheduled_send_at) VALUES ('
				. $db->quote((string)$batch['body']) . ', '
				. $db->quote((string)$batch['created']) . ', NULL)'
			);
			$db->exec(
				'UPDATE sms_new SET sms_id = ' . (int)$db->queryOne('SELECT LAST_INSERT_ID()')
				. ' WHERE id = ' . (int)$batch['id']
			);
		}
		$batches = count($newBatches);
		$db->exec('UPDATE smsdelivery_new d JOIN sms_new b ON b.id = d.batch_id SET d.sms_id = b.sms_id');

		$imported = (int)$db->exec(
			'INSERT INTO smsdelivery (sms_id, personid, remote_id, raw_response, body, provider, delivered_at, status)'
			. " SELECT d.sms_id, d.personid, NULLIF(d.remote_id, ''), d.raw_response, d.body, "
			. $db->quote($providerKey) . ', d.delivered_at, d.status'
			. ' FROM smsdelivery_new d WHERE d.sms_id IS NOT NULL'
		);

		if ($ownTransaction) {
			$db->commit();
		}
	} catch (\Throwable $e) {
		if ($ownTransaction) {
			$db->rollBack();
		}
		throw $e;
	} finally {
		$db->exec('DROP TABLE IF EXISTS smsdelivery_new, sms_new');
		$db->queryOne("SELECT RELEASE_LOCK(CONCAT(DATABASE(), '.sms_sync'))");
	}

	return [
		'deleted' => $deleted,
		'imported' => $imported,
		'batches' => $batches,
		'skipped' => $skipped,
	];
}


// ---------------------------------------------------------------------------
// Opt-out helpers
// ---------------------------------------------------------------------------

/**
 * Get the set of opted-out phone numbers from the upstream provider.
 *
 * Normalises all numbers to international format (e.g. "61491570157").
 * Cached for the session duration via SessionSmsCache.
 *
 * @return string[]  International-format phone numbers (no "+" prefix)
 */
function getOptedOutPhoneNumbers(): array
{
	$providerResult = getSmsProvider(logToDb: false);
	if ($providerResult->isFailure()) {
		return [];
	}

	$provider = $providerResult->getValue();
	if (!$provider->hasCapability(\Sms\SmsCapability::LIST_OPT_OUTS)) {
		return [];
	}

	$result = $provider->listOptOuts();
	if ($result->isFailure()) {
		return [];
	}

	$numbers = [];
	foreach ($result->getValue() as $entry) {
		$numbers[] = $entry->number->value;
	}
	return $numbers;
}

/**
 * Check whether a person's mobile number has opted out upstream.
 *
 * Fails open — returns false when the provider is unreachable,
 * doesn't support opt-out listing, or the person has no mobile.
 *
 * @param \Person $person
 * @return bool
 */
function isPersonOptedOut(\Person $person): bool
{
	$mobile = $person->getValue('mobile_tel');
	if ((string) $mobile === '') {
		return false;
	}

	$intl = (new \Sms\PhoneNumber($mobile))->internationalise('0', '61');

	return \in_array($intl->value, getOptedOutPhoneNumbers(), true);
}



// ---------------------------------------------------------------------------
// Result formatting and post-send logging
// ---------------------------------------------------------------------------

/**
 * Format a send result into a human-readable summary string.
 *
 * Useful for scripts that need to report what happened (e.g. roster reminders).
 *
 * @param \Sms\SendSummary $sendResult The result from send()
 * @param array<int, string> $nameLookup Person ID => "First Last" map (may be empty)
 * @param string $contextLabel e.g. "roster" or "notification"
 * @return string Human-readable summary
 */
function formatSendSummary(\Sms\SendSummary $sendResult, array $nameLookup, string $contextLabel = 'SMS'): string
{
	if ($sendResult instanceof \Sms\Failed) {
		return "Unable to send {$contextLabel}\n\n".$sendResult->error."\n";
	}

	if ($sendResult instanceof \Sms\AllSent) {
		$out = "Sent {$contextLabel} successfully to:\n";
		$names = array_map(
			fn(JethroSmsRecipient $r) => $nameLookup[$r->personId] ?? 'Person #'.$r->personId,
			$sendResult->recipients,
		);
		return $out.implode(', ', $names).".\n\n";
	}

	if ($sendResult instanceof \Sms\PartialSuccess) {
		$out = "Sent {$contextLabel} successfully to:\n";
		$names = array_map(
			fn(JethroSmsRecipient $r) => $nameLookup[$r->personId] ?? 'Person #'.$r->personId,
			$sendResult->successes,
		);
		$out .= implode(', ', $names).".\n\n";
		$out .= "Failed to send {$contextLabel} to:\n";
		$names = array_map(
			fn(JethroSmsRecipient $r) => $nameLookup[$r->personId] ?? 'Person #'.$r->personId,
			$sendResult->failures,
		);
		$out .= implode(', ', $names).".\n\n";
		return $out;
	}

	return "Unknown send result for {$contextLabel}.\n";
}

/**
 * Render the delivery status indicator for an SMS delivery.
 *
 * Returns an HTML <span> with tick(s) or status text — no surrounding markup.
 *
 *   ✓✓  = delivered (with delivery timestamp tooltip)
 *   ✓   = sent (gateway accepted, not yet delivered)
 *   text = other final status (failed, cancelled)
 *   ref  = non-final, provider matches → polling span with data-on:load
 *
 * @param string $status              MySQL status value (e.g. 'delivered', 'sent')
 * @param int|null $deliveredAt       Unix timestamp of delivery, for tooltip
 * @param int|null $deliveryId        smsdelivery.id, for the AJAX polling span
 * @param string|null $remoteId       Remote message ID from the gateway
 * @param string|null $providerKey    Provider that sent this delivery
 * @param string|null $currentProviderKey  Currently configured provider (null = no polling possible)
 * @param string|null $scheduledAt  MySQL datetime of scheduled send, for the title tooltip
 * @return string  HTML <span> element, or '' if no indicator can be shown
 */
function renderSmsDeliveryStatusIndicator(
    string $status,
    ?int $deliveredAt = null,
    ?int $deliveryId = null,
    ?string $remoteId = null,
    ?string $providerKey = null,
    ?string $currentProviderKey = null,
    ?string $scheduledAt = null,
): string
{
	$smsStatus = \Sms\SmsStatus::fromMySql($status);
    $icon     = SmsStatusIcon::fromStatus($smsStatus)->icon();
    $pollable = $currentProviderKey !== null && $providerKey === $currentProviderKey && $deliveryId !== null;
    $ref      = ($remoteId !== null && $remoteId !== '') ? ents($remoteId) : '';

    $outerAttrs  = [];
    $statusAttrs = ['class' => 'message-status'];
    $content     = '';

    if ($deliveryId !== null) {
        $outerAttrs['id'] = 'sms-delivery-status-' . $deliveryId;
    }

    if ($smsStatus === \Sms\SmsStatus::DELIVERED) {
        $statusAttrs['title'] = $deliveredAt !== null && $deliveredAt > 0
            ? 'Delivery timestamp: ' . date('j M Y g:ia', $deliveredAt)
            : 'delivered';
        $statusAttrs['data-message-attribution'] = $status;
        $content = $icon;

    } elseif ($smsStatus === \Sms\SmsStatus::SENT) {
        $statusAttrs['title'] = 'sent';
        $statusAttrs['data-message-attribution'] = $status;
        $content = $icon;

    } elseif ($smsStatus->isFinal()) {
        $statusAttrs['title'] = $status;
        $statusAttrs['data-message-attribution'] = $status;
        $content = $icon !== '' ? $icon . ' ' . $status : $status;

    } elseif ($smsStatus->isImmediatelyPolled() && $pollable) {
        $outerAttrs['data-init'] = "@get('?call=sms_info&id={$deliveryId}')";
        $statusAttrs['data-message-attribution'] = $status;
        $statusAttrs['title'] = $status . ($scheduledAt !== null ? ': ' . format_datetime($scheduledAt) : '');
        $content = trim($icon . ' ' . $ref);

    } elseif ($smsStatus === \Sms\SmsStatus::SCHEDULED && $pollable) {
        // Thundering-herd gate in call_sms_info makes per-delivery jitter
        // unnecessary: only the lowest-ID scheduled delivery per sms_id
        // performs the upstream lookup; all other calls are no-ops.
        $pollSecs = smsScheduledPollIntervalSecs($scheduledAt);
        if ($pollSecs !== null) {
            $outerAttrs["data-on-interval__duration.{$pollSecs}s"] = "@get('?call=sms_info&id={$deliveryId}')";
        }
        // If the scheduled time has already passed, also trigger an immediate
        // status fetch — the DB may still say "scheduled" but the provider
        // may already have transitioned to delivered.  Gated on $pollSecs:
        // once a delivery is over an hour overdue polling stops altogether
        // (see smsScheduledPollIntervalSecs()), including this catch-up fetch.
        if ($pollSecs !== null && $scheduledAt !== null && strtotime($scheduledAt) < time()) {
            $outerAttrs['data-init'] = "@get('?call=sms_info&id={$deliveryId}')";
        }
        $statusAttrs['data-message-attribution'] = $status;
        $statusAttrs['title'] = $status . ($scheduledAt !== null ? ': ' . format_datetime($scheduledAt) : '');
        $content = trim($icon . ' ' . $ref);
        if ($scheduledAt !== null) {
            $seconds = strtotime($scheduledAt) - time();
            $content .= ' ' . ($seconds > 0 ? 'in ' . _formatDuration($seconds) : format_datetime($scheduledAt));
        }

    } else {
        return '';
    }

    $outerAttrStr = '';
    foreach ($outerAttrs as $name => $value) {
        $outerAttrStr .= ' ' . $name . '="' . ents($value) . '"';
    }
    $statusAttrStr = '';
    foreach ($statusAttrs as $name => $value) {
        $statusAttrStr .= ' ' . $name . '="' . ents($value) . '"';
    }
    return '<span' . $outerAttrStr . '><span' . $statusAttrStr . '>' . $content . '</span></span>';
}

/**
 * Datastar poll interval for a delivery still marked scheduled.
 *
 * Approaching the send time the interval shrinks (a tenth of the time
 * remaining, floor 2s, cap 300s); once the send time passes it grows
 * again at the same rate, so deliveries the provider never resolves
 * (e.g. stale or seeded data) back off instead of hammering
 * ?call=sms_info at the 2s floor.  After an hour overdue, polling
 * stops altogether.
 *
 * See docs/docs/developer/reference/sms/SMS_DATASTAR.md.
 *
 * @param string|null $scheduledAt MySQL datetime of the scheduled send (null = unknown, assume 2 minutes away)
 * @return int|null Seconds between polls (2–300), or null to stop polling
 */
function smsScheduledPollIntervalSecs(?string $scheduledAt): ?int
{
    $remaining = $scheduledAt !== null ? (int)(strtotime($scheduledAt) - time()) : 120;
    if ($remaining < -3600) {
        return null;
    }
    return max(2, min(300, (int)ceil(abs($remaining) / 10)));
}

/**
 * Render a delivery-status icon with title rollover — no polling, no text.
 *
 * Always returns a <span> with the status icon and a descriptive title.
 * Unlike renderSmsDeliveryStatusIndicator(), this never returns '' and
 * carries no Datastar polling attributes.
 *
 * @param string $status       MySQL status value (e.g. 'delivered', 'scheduled')
 * @param int|null $deliveredAt   Unix timestamp of delivery, for the title tooltip
 * @param string|null $scheduledAt  MySQL datetime of scheduled send, for the title tooltip
 * @param int|null $deliveryId    When provided, stamps id="sms-delivery-status-N" for Datastar morph
 * @return string  HTML <span> element
 */
function renderSmsDeliveryStatusIcon(
    string $status,
    ?int $deliveredAt = null,
    ?string $scheduledAt = null,
    ?int $deliveryId = null,
    ?string $providerKey = null,
    ?string $currentProviderKey = null,
    string $remoteId = '',
): string
{
    $smsStatus = \Sms\SmsStatus::fromMySql($status);
    $icon      = SmsStatusIcon::fromStatus($smsStatus)->icon();

    $title = $status;
    if ($smsStatus === \Sms\SmsStatus::DELIVERED && $deliveredAt !== null && $deliveredAt > 0) {
        $title = 'Delivered: ' . date('j M Y g:ia', $deliveredAt);
    } elseif ($scheduledAt !== null) {
        $title .= ': ' . format_datetime($scheduledAt);
    }

    $content = $icon;
    $outerAttrs = '';
    if ($smsStatus === \Sms\SmsStatus::SCHEDULED && $scheduledAt !== null) {
        $seconds = strtotime($scheduledAt) - time();
        $content = trim($icon . ' ' . ents($remoteId));
        $content .= ' ' . ($seconds > 0 ? 'in ' . _formatDuration($seconds, short: true) : format_datetime($scheduledAt));

        // Add polling when the provider matches and a delivery ID is available
        if ($deliveryId !== null && $providerKey !== null && $currentProviderKey === $providerKey) {
            // Thundering-herd gate in call_sms_info handles coalescing.
            $pollSecs = smsScheduledPollIntervalSecs($scheduledAt);
            if ($pollSecs !== null) {
                $outerAttrs = ' data-on-interval__duration.' . $pollSecs . 's="@get(\'?call=sms_info&amp;id=' . $deliveryId . '\')"';
            }
        }
    }

    $idAttr = $deliveryId !== null ? ' id="sms-delivery-status-' . $deliveryId . '"' : '';
    return '<span class="message-attribution"' . $idAttr . $outerAttrs . '><span class="message-status" title="' . ents($title) . '">' . $content . '</span></span>';
}

/**
 * Format a duration in seconds as a human-readable string.
 *
 * Resolution depends on magnitude:
 *   - < 1 day    → shows minutes: "1 hour 12 minutes"
 *   - < 7 days   → rounds to nearest hour: "23 hours"
 *   - ≥ 7 days   → rounds to nearest day: "8 days"
 *
 * @return string e.g. "45 minutes", "2 hours 30 minutes", "23 hours", "8 days"
 */
function _formatDuration(int $seconds, bool $short = false): string
{
    $perDay    = 86400;
    $perHour   = 3600;
    $perMinute = 60;

    if ($short) {
        if ($seconds >= 7 * $perDay) {
            $n = (int)round($seconds / $perDay);
            return $n . 'd';
        }
        if ($seconds >= $perDay) {
            $n = (int)round($seconds / $perHour);
            return $n . 'h';
        }
        // < 1 day: hours + minutes, short
        $hours   = intdiv($seconds, $perHour);
        $rem     = $seconds % $perHour;
        $minutes = intdiv($rem, $perMinute);
        $secs    = $rem % $perMinute;

        // Show seconds when under 2 minutes — same as the long format
        if ($hours === 0 && $minutes < 2) {
            $totalSecs = $minutes * 60 + $secs;
            if ($totalSecs < 60) {
                return $totalSecs . 's';
            }
            $parts = [];
            if ($minutes > 0) $parts[] = $minutes . 'm';
            if ($secs > 0)    $parts[] = $secs . 's';
            return implode(' ', $parts);
        }

        if ($secs >= 30) {
            $minutes++;
            if ($minutes === 60) { $hours++; $minutes = 0; }
        }
    }

    if ($seconds >= 7 * $perDay) {
        $n = (int)round($seconds / $perDay);
        return $n === 1 ? _('1 day') : sprintf(_('%d days'), $n);
    }

    if ($seconds >= $perDay) {
        $n = (int)round($seconds / $perHour);
        return $n === 1 ? _('1 hour') : sprintf(_('%d hours'), $n);
    }

    // < 1 day: show minutes resolution using integer arithmetic
    $hours   = intdiv($seconds, $perHour);
    $rem     = $seconds % $perHour;
    $minutes = intdiv($rem, $perMinute);
    $secs    = $rem % $perMinute;

    // Sub-minute resolution when the countdown is under 2 minutes —
    // matters for progressive N/10 polling which goes down to 2s intervals.
    if ($hours === 0 && $minutes < 2) {
        $totalSecs = $minutes * 60 + $secs;
        if ($totalSecs < 60) {
            return $totalSecs === 1 ? _('1 second') : sprintf(_('%d seconds'), $totalSecs);
        }
        $parts = [];
        if ($minutes > 0) {
            $parts[] = $minutes === 1 ? _('1 minute') : sprintf(_('%d minutes'), $minutes);
        }
        if ($secs > 0) {
            $parts[] = $secs === 1 ? _('1 second') : sprintf(_('%d seconds'), $secs);
        }
        return implode(' ', $parts);
    }

    if ($secs >= 30) {
        $minutes++;
        if ($minutes === 60) {
            $hours++;
            $minutes = 0;
        }
    }

    $parts = [];
    if ($hours > 0) {
        $parts[] = $hours === 1 ? _('1 hour') : sprintf(_('%d hours'), $hours);
    }
    if ($minutes > 0 || $parts === []) {
        $parts[] = $minutes === 1 ? _('1 minute') : sprintf(_('%d minutes'), $minutes);
    }
    return implode(' ', $parts);
}


/**
 * Render a RegistrationStep as an HTML snippet.
 *
 * Dispatches on {@see RegistrationStep::isComplete()}:
 *   - **Not complete** (fields !== []): renders an input form from the field
 *     schema — the admin fills this out and submits it to advance the state
 *     machine (formerly {@see Call_Admin_Statuspanel_Operation::buildFormHtml()}).
 *   - **Complete** (fields === []): renders the summary — message, instructions,
 *     contact link, and a table of submitted values.
 *
 * The CLI renders the same structure as text via
 * {@see renderRegistrationStepText()} in scripts/sms.php.
 *
 * @param string|null $method  Operation method name for the form element IDs
 *                             and data-operation attribute (e.g. 'registerSenderId').
 *                             Only used when rendering a form.
 */
function renderRegistrationStepHtml(\Sms\RegistrationStep $step, ?string $method = null, string $submitPostUrl = ''): string
{
	$html = '';

	// Message and instructions display in every state — Phase 1 schemas
	// often carry guidance text alongside the fields.
	if ($step->message !== '') {
		$cls = $step->registered ? ' text-success' : '';
		$html .= '<p class="reg-result-message' . $cls . '"><strong>' . $step->message . '</strong></p>';
	}

	if ($step->instructions !== '') {
		$html .= '<p>' . $step->instructions . '</p>';
	}

	if (!$step->isComplete()) {
		$html .= _renderRegistrationFormHtml($step->fields, $method ?? '', $submitPostUrl);
		return $html;
	}

	if ($step->contact !== '') {
		if (str_starts_with($step->contact, 'mailto:')) {
			$qPos = strpos($step->contact, '?');
			$display = $qPos !== false ? substr($step->contact, 7, $qPos - 7) : substr($step->contact, 7);
			$html .= '<p><a href="' . $step->contact . '">' . ents($display) . '</a></p>';
		} else {
			$html .= '<p><a href="mailto:' . ents($step->contact) . '">' . ents($step->contact) . '</a></p>';
		}
	}

	if ($step->form !== []) {
		$html .= '<table style="border-collapse:collapse">';
		foreach ($step->form as $row) {
			$html .= '<tr>'
				. '<td style="padding:4px 8px;font-weight:bold;vertical-align:top">' . ents((string) ($row['label'] ?? '')) . ':</td>'
				. '<td style="padding:4px 8px">' . ents((string) ($row['value'] ?? '')) . '</td>'
				. '</tr>';
		}
		$html .= '</table>';
	}

	return $html;
}

/**
 * Render field schema as an input form.
 *
 * When $submitPostUrl is provided (statuspanel Datastar context), the wrapper
 * is a <form onsubmit="return false"> and the submit button carries a Datastar
 * @post attribute.  The status_panel-op-result div is omitted — the whole
 * container is morphed by Datastar on POST.
 *
 * When $submitPostUrl is empty (legacy / sendernum context), the original
 * <div> wrapper and type="submit" button are used unchanged.
 *
 * @param \Sms\FormField[] $fields
 * @internal  Called by {@see renderRegistrationStepHtml()} when the step is not complete.
 */
function _renderRegistrationFormHtml(array $fields, string $method, string $submitPostUrl = ''): string
{
	$datastar = $submitPostUrl !== '';
	$tag      = $datastar ? 'form' : 'div';
	$extra    = $datastar ? ' onsubmit="return false"' : '';

	$html  = '<' . $tag . $extra . ' class="status_panel-op-form form-horizontal" data-operation="' . ents($method) . '">';
	$html .= '<input type="hidden" name="operation" value="' . ents($method) . '">';

	foreach ($fields as $f) {
		$name     = $f->name;
		$label    = $f->label;
		$type     = $f->type;
		$required = $f->required ? ' required' : '';
		$id       = 'spop_' . $method . '_' . $name;

		$html .= '<div class="control-group" id="' . ents($id) . '">';

		if ($type === 'checkbox') {
			$html .= '<div class="controls">'
				. '<label class="checkbox">'
				. '<input type="checkbox" name="' . ents($name) . '" id="' . ents($id) . '" value="1"' . $required . '>'
				. ents($label)
				. '</label>';
		} else {
			$html .= '<label class="control-label" for="' . ents($id) . '">' . ents($label) . '</label>';
			$html .= '<div class="controls">';

			if ($type === 'select' && $f->options !== null) {
				$html .= '<select name="' . ents($name) . '" id="' . ents($id) . '"' . $required . '>';
				foreach ($f->options as $opt) {
					$html .= '<option value="' . ents($opt) . '">' . ents($opt) . '</option>';
				}
				$html .= '</select>';
			} else {
				$inputType = match ($type) {
					'email' => 'email',
					'tel'   => 'tel',
					'url'   => 'url',
					default => 'text',
				};
				$html .= '<input type="' . $inputType . '" name="' . ents($name) . '" id="' . ents($id) . '" value="" class="" size="60"' . $required . '>';
			}
		}

		if ($f->description !== null && $f->description !== '') {
			$html .= '<p class="help-inline">' . ents($f->description) . '</p>';
		}

		$html .= '</div>'; // .controls or .control-group for checkbox
		$html .= '</div>'; // .control-group
	}

	$html .= '<div class="control-group"><div class="controls">';
	if ($datastar) {
		$escapedUrl = ents($submitPostUrl);
		$html .= '<button type="button" class="btn"'
			. " data-on:click=\"@post('{$escapedUrl}', {contentType: 'form'})\">Submit";
	} else {
		$html .= '<button type="submit" class="btn">Submit';
	}
	$testMode = filter_var(ifdef('SMS_TESTMODE', false), FILTER_VALIDATE_BOOLEAN);
	if ($testMode) $html .= ' (test mode - not really sent)';
	$html .= '</button>';
	$html .= '</div></div>';

	if (!$datastar) {
		$html .= ' <div class="status_panel-op-result"></div>';
	}

	$html .= '</' . $tag . '>';

	return $html;
}


/**
 * Print the SMS send modal dialog.
 * This is reused in the person view, person list view, roster 'SMS all assignees' and runsheet personnel sections.
 */
function printSmsModal(): void
{
	?>
	<div id="send-sms-modal" class="modal sms-modal hide" role="dialog" aria-hidden="true" data-note-type="<?php echo ents(($_REQUEST['view'] ?? '') === 'families' ? 'family' : 'person'); ?>">
		<div class="modal-header">
			<h4>Send SMS to <span class="sms_recipients"></span></h4>
		</div>
		<form class="modal-body form-horizontal" onsubmit="return false">
			<?php // Hidden recipient field, populated by the modal-open handler in
			      // jethro-sms.js. Lets the Datastar statusline post (contentType:form)
			      // resolve the single recipient. The actual send is via JS AJAX. ?>
			<input type="hidden" name="personid" value="" />
			<input type="hidden" name="sms_type" value="person" />
			<?php _printSmsFormFields(); ?>
		</form>
		<div class="modal-footer">
			<div class="results"></div>
			<div id="sms-call-failures"></div>
			<button class="btn sms-submit" accesskey="s" data-attr:disabled="$smsSendBlocked">Send</button>
			<button class="btn" data-dismiss="modal" aria-hidden="true">Close</button>
			<div class="alert alert-error sms-disabled-reason" data-text="$smsBlockReason" data-show="$smsSendBlocked" style="display:none"></div>
		</div>
	<?php
}

/**
 * Print the inline bulk-SMS form shown on list views.
 *
 * @param string $noteType 'person' (default) or 'family' — controls
 *                         whether notes created via "Save as Note" are
 *                         person_notes or family_notes.
 */
function printBulkSmsForm(string $noteType = 'person'): void
{
	?>
	<div class="bulk-action well form-horizontal" id="smshttp">
		<input type="hidden" name="note_type" value="<?php echo ents($noteType); ?>" />
		<div class="control-group">
			<label class="control-label"><?php echo _('To:')?></label>
			<div class="controls">
				<label class="radio">
					<input class="compulsory" type="radio" name="sms_type" value="person" id="sms_type_person" checked="checked"
					       data-on:change="@post('?call=sms_statusline', {contentType: 'form'})" />
					<?php echo _('the selected persons')?>
				</label>
				<label class="radio">
					<input type="radio" name="sms_type" value="family" id="sms_type_family"
					       data-on:change="@post('?call=sms_statusline', {contentType: 'form'})" />
					<?php echo _('the adults in the selected persons\' families')?>
				</label>
			</div>
		</div>
		<?php _printSmsFormFields('-bulk'); ?>
		<div class="control-group">
			<div class="controls">
				<input type="button" class="btn bulk-sms-submit" value="Send" data-attr:disabled="$smsSendBlocked" />
				<div class="help-block alert alert-error sms-disabled-reason" data-text="$smsBlockReason" data-show="$smsSendBlocked" style="display:none"></div>
			</div>
		</div>
		<div class="control-group" id="bulk-sms-results"></div>
		<div id="call-failures-bulk"></div>
	</div>
	<?php
}

/**
 * Print the Sender dropdown for SMS forms.
 */
function printSenderDropdown(string $idSuffix = ''): void
{
	?>
	<div class="control-group">
		<label class="control-label<?php if ($GLOBALS['user_system']->havePerm(PERM_SYSADMIN)) echo ' config-help'; ?>"<?php if ($GLOBALS['user_system']->havePerm(PERM_SYSADMIN)) echo ' title="Available sender options can be set in config — (SMS_SENDER, SMS_SENDER_OPTIONS, SMS_SENDER_DEFAULT) setting"'; ?>>Sender:</label>
		<div class="controls">
			<?php _printSenderDropdownControl($idSuffix); ?>
		</div>
	</div>
	<?php
}

/**
 * Print the "Create Note" checkbox for SMS forms.
 */
function printSaveAsNoteCheckbox(string $idSuffix = ''): void
{
	if (!$GLOBALS['user_system']->havePerm(PERM_EDITNOTE)) {
		return;
	}
	$checked = (defined('SMS_SAVE_TO_NOTE_BY_DEFAULT') && SMS_SAVE_TO_NOTE_BY_DEFAULT) ? 'checked="checked"' : '';
	$sig = 'saveasnote' . strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $idSuffix));
	?>
	<label class="checkbox">
		<input type="checkbox" name="saveasnote" class="saveasnote" data-bind:<?php echo ents($sig); ?> <?php echo $checked; ?> />
		Create Note&hellip;
	</label>
	<?php
}

/**
 * Print the shared SMS form fields used by both the modal and the inline bulk form:
 * sender dropdown, message textarea, note fields, optional schedule toggle, and the
 * AJAX response container.
 *
 * @param string $idSuffix  Appended to element IDs/suffixes — '' for the modal, '-bulk' for the bulk form.
 */
function _printSmsFormFields(string $idSuffix = ''): void
{
	printSenderDropdown($idSuffix);
	?>
	<div class="control-group">
    	<label class="control-label">Message:</label>

		<div class="controls">
			<?php printTextbox($idSuffix); ?>
		</div>
	</div>
	<div class="control-group">
		<div class="controls">
			<?php
			printSaveAsNoteCheckbox($idSuffix);
			printNoteSubjectField($idSuffix);
			printNoteActionDateField($idSuffix);
			?>
		</div>
	</div>
	<?php
	$smsSchedResult = getSmsProvider();
	$schedSig = '';
	$maxAttr = '';
	if ($smsSchedResult->isSuccess()):
		$provider = $smsSchedResult->getValue();
		if ($provider->hasCapability(\Sms\SmsCapability::DEFERRED_SEND)):
			$idClean = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $idSuffix));
			$schedSig = 'schedulesend' . $idClean;
			$maxDelay = $provider->getDeferredSendMaxDelay();
			if ($maxDelay !== null) {
				$maxAttr = ' max="' . date('Y-m-d\TH:i', time() + $maxDelay) . '"';
			}
		endif;
	endif;
	if ($schedSig !== ''): ?>
	<div class="control-group">
		<div class="controls">
			<label class="checkbox">
				<input type="checkbox" class="sms-schedule-toggle" data-bind:<?php echo ents($schedSig); ?> /> <?php echo _('Schedule Send&hellip;');?>
			</label>
			<div class="sms-schedule-picker" data-show="$<?php echo ents($schedSig); ?>">
				<label><?php echo _('Send at:');?>
					<input type="datetime-local" name="send_at" class="sms-schedule-datetime"<?php echo $maxAttr; ?> data-on:change="@post('?call=sms_statusline', {contentType: 'form'})" />
				</label>
			</div>
		</div>
	</div>
	<?php endif; ?>
	<div id="sms-send-response<?php echo $idSuffix; ?>" class="alert alert-error" style="display:none"></div>
	<?php
}


/**
 * Print the Subject field for SMS-save-as-note.
 * Pre-filled with SMS_SAVE_TO_NOTE_SUBJECT if defined.
 */
function printNoteSubjectField(string $idSuffix = ''): void
{
    if (!$GLOBALS['user_system']->havePerm(PERM_EDITNOTE)) return;

	$GLOBALS['system']->includeDBClass('person_note');
	$note = new \Person_Note();
	$defaultSubject = defined('SMS_SAVE_TO_NOTE_SUBJECT') ? SMS_SAVE_TO_NOTE_SUBJECT : 'SMS follow-up';
	$sig = 'saveasnote' . strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $idSuffix));
	?>
	<div class="control-group sms-note-field" data-show="$<?php echo ents($sig); ?>">
		<label class="control-label<?php if ($GLOBALS['user_system']->havePerm(PERM_SYSADMIN)) echo ' config-help'; ?>"<?php if ($GLOBALS['user_system']->havePerm(PERM_SYSADMIN)) echo ' title="Subject default can be set in config — SMS_SAVE_TO_NOTE_SUBJECT setting"'; ?>><?php echo ents($note->getFieldLabel('subject')); ?></label>
        <div class="controls">
			<?php print_widget('note_subject', $note->fields['subject'], $defaultSubject); ?>
		</div>
	</div>
	<?php
}

/**
 * Print the Action Date field for SMS-save-as-note.
 */
function printNoteActionDateField(string $idSuffix = ''): void
{
    if (!$GLOBALS['user_system']->havePerm(PERM_EDITNOTE)) return;

	$GLOBALS['system']->includeDBClass('person_note');
	$note = new \Person_Note();
	$defaultDate = date('Y-m-d');
	$sig = 'saveasnote' . strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $idSuffix));
	?>
	<div class="control-group sms-note-field" data-show="$<?php echo ents($sig); ?>">
		<label class="control-label"><?php echo ents($note->getFieldLabel('action_date')); ?></label>
		<div class="controls">
			<?php print_widget('note_action_date', $note->fields['action_date'], $defaultDate); ?>
		</div>
	</div>
	<?php
}

/**
 * Print the SMS text input widget.
 */
function printTextbox(string $idSuffix = ''): void
{
	$cfg = makeStatuslineConfig();
	$segmentCost = $cfg->segmentCost;

	// Datastar signal names cannot contain hyphens; derive a JS-safe suffix
	// so the bulk and modal composers have independent signals. The suffix
	// must stay lowercase: data-bind:<name> is an HTML attribute name, and
	// browsers always lowercase attribute names, so an embedded uppercase
	// letter (e.g. a camelCase 'Bulk') would never match the '$sig'-derived
	// signal name used in data-text="$sig..." below — the textarea's value
	// would silently never reach the signal. Keep both sides lowercase.
	//   bulk  ('-bulk') -> $smsmessagebulk
	//   modal ('')      -> $smsmessage
	// NOTE: Datastar separates the plugin name from its key with a COLON, not
	// a hyphen (data-on:input, data-bind:sig, data-attr:disabled). A hyphen
	// makes the whole string the plugin name (e.g. "on-input"), matching no
	// registered plugin, so it is silently skipped and no listener attaches.
	$sig = 'smsmessage' . strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $idSuffix));
	$statuslineId = 'sms-statusline' . $idSuffix;
	$previewId = 'sms-preview-panel' . $idSuffix;
	// Per-form signal toggled by the "Message Preview" checkbox (data-bind);
	// the panel wrapper's data-show follows it. Lowercased for the same
	// reason as $sig (browsers lowercase attribute names).
	$previewSig = 'smspreview' . strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $idSuffix));

	// Initial server-rendered statusline so the page is correct before the
	// first keystroke (empty message -> empty statusline). All cost/segment
	// maths is server-owned now; see jethro-sms/src/sms_statusline.php and
	// docs/docs/developer/reference/sms/SMS_DATASTAR.md.
	$initialStatus = \Sms\renderStatusline('', [], $cfg);

	// {contentType:'form'} submits the enclosing <form> so sender, sms_type,
	// personid[] and message_overrides ride along. The hidden id fields tell
	// the SSE endpoint which elements to morph.
	$post = "@post('?call=sms_statusline', {contentType: 'form'})";
	?>
    <input type="hidden" name="statusline_id" value="<?php echo ents($statuslineId); ?>" />
    <input type="hidden" name="preview_id" value="<?php echo ents($previewId); ?>" />
    <textarea class="sms-message span4" name="message"
              data-bind:<?php echo ents($sig); ?>
              data-on:input__debounce.300ms="<?php echo ents($post); ?>"
              data-is-sysadmin="<?php echo $GLOBALS['user_system']->havePerm(PERM_SYSADMIN); ?>"
              rows="5" cols="30" maxlength="<?php echo ifdef('SMS_MAX_LENGTH') ?: 160; ?>"></textarea>
    <?php // One combined counter line: the live client-side char count
          // ($<sig>.length — the only client-side maths left) immediately
          // followed by the server-rendered segment/cost/recipient figures,
          // morphed into the #<statuslineId> span via SSE. A CSS ::before on the
          // non-empty statusline draws the " · " separator, so there is exactly
          // one line and the character count is never shown twice. ?>
    <div class="smscharactercount soft">
        <span class="sms-charcount-instant" data-show="$<?php echo ents($sig); ?>.length > 0" data-text="$<?php echo ents($sig); ?>.length + ' chars'"></span><span id="<?php echo ents($statuslineId); ?>" class="sms-statusline-cost"><?php echo $initialStatus['html']; ?></span>
    </div>
	<?php
	$tokens = getAvailableTokens();
	if ($tokens !== []) {
		$tokenList = implode(', ', array_map(fn (string $t) => '<code>%'.$t.'%</code>', $tokens));
		?>
		<div id="sms-token-hint<?php echo $idSuffix; ?>" class="soft" style="margin-top:2px">
			<span class="icon-info-sign"></span>
			Personalise with: <?php echo $tokenList; ?>
		</div>
		<?php
	}
	?>
		<?php if (getAvailableTokens() !== []): ?>
		<label class="checkbox sms-preview-toggle">
			<input type="checkbox" class="sms-preview-checkbox" data-bind:<?php echo ents($previewSig); ?> />
			Message Preview
		</label>
		<?php endif; ?>
		<?php // Preview panel. Visibility follows the "Message Preview" checkbox
		     // via a Datastar signal on this wrapper, which the SSE never morphs;
		     // the inner #<previewId> is the SSE morph target for the server-
		     // rendered content. ?>
		<div class="sms-preview-wrap" style="display:none" data-show="$<?php echo ents($previewSig); ?>">
			<div id="<?php echo ents($previewId); ?>" class="sms-preview-panel" style="display:none;min-height:0"></div>
		</div>
	<?php
	}



// ---------------------------------------------------------------------------
// UI
// ---------------------------------------------------------------------------

/**
 * Print the Sender <select>/status markup (no surrounding label/control-group).
 * Split out of printSenderDropdown() so modal and bulk-form callers share one
 * .control-group/.control-label wrapper instead of duplicating it.
 *
 * SMS_SENDER_OPTIONS controls which options appear and in what order.  When the
 * constant is undefined or set to the empty string, defaults to
 * `_SENDER_IDS_,_USER_MOBILE_` so an out-of-the-box install still offers the
 * user's mobile and any upstream-registered sender IDs.
 * Recognised tokens:
 *   `_SENDER_IDS_`  — expands to every sender ID the provider reports
 *   `_USER_MOBILE_` — expands to the current user's mobile number
 * Empty segments from duplicate commas are silently discarded.
 *
 * `_printRegisterStatus()` is emitted exactly once (inside a hidden
 * `.sms-register-status-wrapper` revealed by JS when the user selects the
 * `_USER_MOBILE_` option and that number still needs verifying).
 */
function _printSenderDropdownControl(string $idSuffix = ''): void
{
	// ── Hardcoded SMS_SENDER — no dropdown ──────────────────────────────────
	if (defined('SMS_SENDER') && SMS_SENDER !== '') {
		$senderAvailable = true;
		if (SMS_SENDER === '_USER_MOBILE_') {
			$userMobile = getCurrentUserMobileNumber();
			if ($userMobile === null) {
				$senderAvailable = false;
				?>
				<span class="text-error">Your mobile number is not set. Add it to your profile to send SMS.</span>
				<?php
			} else {
				$needsReg = false;
				$label = '';
				$providerResult = getSmsProvider();
				if ($providerResult->isSuccess()) {
					$provider = $providerResult->getValue();
					if ($provider->hasCapability(\Sms\SmsCapability::REGISTER_SENDER_NUMBER)) {
						$verifyResult = $provider->verifySenderNumber($userMobile);
						if ($verifyResult->isSuccess()) {
							$needsReg = !$verifyResult->getValue();
						} else {
							$verifyError = $verifyResult->getError();
							$needsReg = true;
						}
						$currentUserId = (int) ($GLOBALS['user_system']->getCurrentUser('id') ?? 0);
						$label = 'person_' . $currentUserId;
					}
				}
				?>
				<?php echo ents('My mobile ('.$userMobile->value.')'); ?>
				<?php if ($needsReg): ?>
					<?php _printRegisterStatus($userMobile, $label); ?>
				<?php endif; ?>
				<?php if (isset($verifyError)): ?>
					<span class="text-warning" style="font-size:0.85em">
						<i class="icon-warning-sign"></i>
						Could not verify sender: <?php echo ents($verifyError); ?>
					</span>
				<?php endif; ?>
				<?php
			}
		} else {
			?>
			<div class="controls-text"><?php echo ents(SMS_SENDER); ?></div>
			<?php
		}
		// No <select> exists for a hardcoded sender, so checkSenderAvailability()
		// (JS) can't infer availability from option counts — this marker tells
		// it directly whether Send should be enabled.
		?>
		<span class="sms-sender-static" data-sender-available="<?php echo $senderAvailable ? '1' : '0'; ?>" style="display:none"></span>
		<?php
		return;
	}

	// ── Dropdown path ────────────────────────────────────────────────────────
	// Parse configured options (or default) into an ordered token list;
	// empty segments from duplicate commas are silently discarded.  An empty
	// SMS_SENDER_OPTIONS is treated the same as undefined — the constant lives
	// in the `setting` table now, where its seeded default was once blank, and
	// blank there should still produce a usable dropdown.
	$rawOptions = (string) ifdef('SMS_SENDER_OPTIONS', '');
	if ($rawOptions === '') {
		$rawOptions = '_SENDER_IDS_,_USER_MOBILE_';
	}
	$tokens = array_values(array_filter(
		array_map('trim', explode(',', $rawOptions)),
		fn(string $t) => $t !== '',
	));

	// Resolve the provider once; getSenderIds() handles SMS_SENDER_OPTIONS
	// internally (including _SENDER_IDS_ expansion and duplicate-comma cleanup).
	$senderIds     = [];
	$senderIdsError = null;
	$provider      = null;
	$providerResult = getSmsProvider();
	if ($providerResult->isSuccess()) {
		$provider  = $providerResult->getValue();
		$idsResult = $provider->getSenderIds(getAll: true);
		if ($idsResult->isFailure()) {
			$senderIdsError = $idsResult->getError();
		} else {
			$senderIds = $idsResult->getValue();
		}
	}

	// Resolve _USER_MOBILE_ and check registration status in one pass.
	$userMobile      = null;
	$needsRegistration = false;
	$label           = '';
	if (\in_array('_USER_MOBILE_', $tokens, true)) {
		$userMobile = getCurrentUserMobileNumber();
		if ($userMobile !== null && $provider !== null
			&& $provider->hasCapability(\Sms\SmsCapability::REGISTER_SENDER_NUMBER)
		) {
			$verifyResult = $provider->verifySenderNumber($userMobile);
			if ($verifyResult->isSuccess()) {
				if (!$verifyResult->getValue()) {
					$needsRegistration = true;
				}
			} else {
				$verifyError = $verifyResult->getError();
				$needsRegistration = true;
			}
			$currentUserId = (int) ($GLOBALS['user_system']->getCurrentUser('id') ?? 0);
			$label = 'person_' . $currentUserId;
		}
	}

	// Nothing to show at all — surface the most useful error.
	if ($senderIds === [] && $userMobile === null) {
		if ($senderIdsError !== null) {
			?>
			<span class="text-error"><?php echo ents($senderIdsError); ?></span>
			<?php
		} else {
			?>
			<span class="text-error">No senders available. Set SMS_SENDER_OPTIONS or add a mobile number to your profile (log out/log in to take effect).</span>
			<?php
		}
		return;
	}

	if ($senderIdsError !== null || isset($verifyError)) {
		if ($senderIdsError !== null) {
			?>
			<span class="text-error"><?php echo ents($senderIdsError); ?></span>
			<?php
		}
		if (isset($verifyError)) {
			?>
			<span class="text-warning" style="font-size:0.85em">
				<i class="icon-warning-sign"></i>
				Could not verify sender: <?php echo ents($verifyError); ?>
			</span>
			<?php
		}
	}

	$defaultSender = defined('SMS_SENDER_DEFAULT') ? SMS_SENDER_DEFAULT : '_USER_MOBILE_';

	// Track emitted values to avoid duplicate options when a literal token and
	// _SENDER_IDS_ would otherwise produce the same entry.
	$renderedValues = [];
	$sig = 'smssender' . strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $idSuffix));
	?>
	<select name="sender" id="sms_sender<?php echo $idSuffix; ?>" class="span4" data-bind:<?php echo ents($sig); ?>>
		<?php foreach ($tokens as $token):
			if ($token === '_USER_MOBILE_') {
				if ($userMobile !== null && !\in_array('_USER_MOBILE_', $renderedValues, true)) {
					$renderedValues[] = '_USER_MOBILE_';
					?>
					<option value="_USER_MOBILE_"
						<?php if ($defaultSender === '_USER_MOBILE_') echo 'selected'; ?>
						<?php if ($needsRegistration) echo 'data-needs-registration="1"'; ?>
						data-number="<?php echo ents($userMobile->value); ?>"
						data-label="<?php echo ents($label); ?>"
					><?php echo ents('My mobile: '.$userMobile->value); ?></option>
					<?php
				}
			} elseif ($token === '_SENDER_IDS_') {
				foreach ($senderIds as $senderId) {
					if (!\in_array($senderId->value, $renderedValues, true)) {
						$renderedValues[] = $senderId->value;
						?>
						<option value="<?php echo ents($senderId->value); ?>"
							<?php if ((string) $defaultSender === $senderId->value) echo 'selected'; ?>
						><?php echo ents($senderId->value); ?></option>
						<?php
					}
				}
			} else {
				if (!\in_array($token, $renderedValues, true)) {
					$renderedValues[] = $token;
					?>
					<option value="<?php echo ents($token); ?>"
						<?php if ((string) $defaultSender === $token) echo 'selected'; ?>
					><?php echo ents($token); ?></option>
					<?php
				}
			}
		endforeach; ?>
	</select>
	<?php if ($needsRegistration && $userMobile !== null): ?>
	<span class="sms-register-status-wrapper" data-show="$<?php echo ents($sig); ?> == '_USER_MOBILE_'">
		<?php _printRegisterStatus($userMobile, $label); ?>
	</span>
	<?php endif; ?>
	<?php
}

/**
 * Print the sender-number registration status block.
 *
 * Shows a "needs verifying" prompt and inline OTP verification UI.
 * Used when REGISTER_SENDER_NUMBER capability is present and the
 * user's mobile has not yet been verified with the upstream provider.
 */
function _printRegisterStatus(\Sms\PhoneNumber $userMobile, string $label): void
{
	$number = ents($userMobile->value);
	$labelE = ents($label);
	?>
	<div id="sms-register-<?php echo $labelE; ?>" class="sms-register-status" style="margin-top: 4px; font-size: 0.9em;">
		<span class="sms-register-prompt">
			<?php echo _('(your mobile number needs verifying to send &mdash;'); ?>
			<a href="#" class="sms-register-number"
			   data-on:click="@post('?call=sms_sendernum&action=register&number=<?php echo $number; ?>&label=<?php echo $labelE; ?>')"
			><?php echo _('verify now'); ?></a>)
		</span>
	<div class="sms-register-otp" style="display:none">
			<?php echo _('Verification code sent &mdash; enter code:'); ?>
			<input type="text" name="otp" data-bind:otp<?php echo $labelE; ?> class="sms-otp-input input-small" maxlength="6" style="width:70px; margin:0 4px" />
			<button type="button" class="btn btn-mini sms-otp-submit"
				data-on:click="@post('?call=sms_sendernum&action=validate&number=<?php echo $number; ?>&label=<?php echo $labelE; ?>&otp=' + $otp<?php echo $labelE; ?>)"
			><?php echo _('Verify'); ?></button>
			<span class="sms-register-error" style="display:none; color:#c00; margin-left:4px"></span>
	</div>
	</div>
	<?php
}



// ---------------------------------------------------------------------------
// Utilities
// ---------------------------------------------------------------------------

/**
 * Get the current SMS account balance.
 *
 * Has a TWO-LEVEL cache:
 *   1. Per-request static variable (avoids multiple provider calls within
 *      the same PHP request — e.g. both printTextbox() and printBulkSmsForm()
 *      calling getSmsBalance())
 *   2. Cross-request SmsCache (SessionSmsCache, wired in getSmsProvider) —
 *      avoids HTTP calls on every page load; invalidated after send
 *
 * Returns null (not 0) when the balance is unavailable, to distinguish
 * "provider doesn't support balance" from "balance is zero".
 *
 * @return int|null The balance as an integer, or null if unavailable or unsupported
 */
function getSmsBalance(): ?int
{
	static $balance = false;

	if ($balance !== false) {
		return $balance;
	}

	$balance = null;

	$providerResult = getSmsProvider();
	if ($providerResult->isSuccess()) {
		$result = $providerResult->getValue()->getBalance();
		if ($result->isSuccess()) {
			$balance = $result->getValue();
		}
	}

	return $balance;
}

/**
 * Get the short key (e.g. '5centsmsv5') for the currently configured SMS provider.
 *
 * Returns null when no provider is configured or an error occurs.
 * Cheap to call repeatedly — getSmsProvider() is memoized per request.
 *
 * @return string|null
 */
function getCurrentSmsProviderKey(): ?string
{
	$result = getSmsProvider();
	if ($result->isFailure()) {
		return null;
	}
	return $result->getValue()->getKey();
}

/**
 * Get the list of available %token% names for SMS message personalisation.
 *
 * These tokens are resolved per-recipient at send time using person data.
 * If the message contains no %, the message is sent as a single batch.
 * Otherwise each recipient receives an individual message with their own data.
 *
 * @return string[]  Token names (without the % delimiters), e.g. ['firstname', 'lastname', 'fullname']
 */
function getAvailableTokens(): array
{
	return ['firstname', 'lastname', 'fullname'];
}

/**
 * Snapshot Jethro state into the pure statusline config value object.
 *
 * This is the bridge-side factory for \Sms\SmsStatuslineConfig: it reads the
 * provider chain (segment cost), balance, config constants and the current
 * user's sysadmin bit — none of which the pure statusline layer may touch.
 * (Formerly SmsStatuslineConfig::fromConstants(); moved here during the
 * jethro-sms extraction — see jethro-sms/docs/extraction.md §4.)
 */
function makeStatuslineConfig(): \Sms\SmsStatuslineConfig
{
    $segmentCost = 0.0;
    $providerResult = \Jethro\Sms\getSmsProvider();
    if ($providerResult->isSuccess()) {
        $segmentCost = $providerResult->getValue()->getSegmentCost() / 100000;
    }

    $smsUnicode = ifdef('SMS_UNICODE_PERMITTED', 'when_free');
    if ($smsUnicode === 'when_free' || $smsUnicode === 2 || $smsUnicode === '2') {
        $unicodeMode = 'when_free';
    } elseif (filter_var($smsUnicode, FILTER_VALIDATE_BOOLEAN)) {
        $unicodeMode = 'enabled';
    } else {
        $unicodeMode = 'disabled';
    }

    return new \Sms\SmsStatuslineConfig(
        maxLength: (int) (ifdef('SMS_MAX_LENGTH') ?: 160),
        segmentLength: 160,
        ucs2SegmentLength: 70,
        segmentCost: $segmentCost,
        balance: ifdef('SMS_BALANCE_ENFORCED', false) ? \Jethro\Sms\getSmsBalance() : null,
        shortenUrls: ifdef('SMS_SHORTEN_URLS', false) && ifdef('URLSHORTENER', '') !== '',
        testMode: filter_var(ifdef('SMS_TESTMODE', false), FILTER_VALIDATE_BOOLEAN),
        unicodeMode: $unicodeMode,
        isSysadmin: isset($GLOBALS['user_system']) && $GLOBALS['user_system']->havePerm(PERM_SYSADMIN),
    );
}

/**
 * Insert a broadcast record and its per-recipient rows.
 *
 * Sets status (JSON from per-recipient SmsDelivery data) and isdelivered (0).
 * Per-recipient remote IDs are stored in smsdelivery.remoteId.
 * For failed sends where results is empty, status is left NULL.
 *
 * On DB failure, returns the sentinel `['smsId' => 0, 'deliveries' => []]`
 * and logs loudly via error_log(). The gateway send has already happened;
 * callers must still treat the send as successful (messages were delivered).
 * DbLoggingSmsProvider never caches a non-positive smsId so subsequent batch
 * calls will retry insertSms() rather than inheriting the failure.
 *
 * @param string $messageText
 * @param int|null $senderId
 * @param \Sms\SmsDelivery[] $results
 * @param \Sms\SmsRecipient[] $recipients  Original recipients
 * @return array{smsId: int, deliveries: array<string, int>}  smsId + person ID → smsdelivery ID map; smsId=0 on DB failure
 */
function insertSms(string $messageText, ?int $senderId, array $results, array $recipients, ?int $scheduledSendAt = null, string $provider = '', string $wireSender = ''): array
{
	$db = $GLOBALS['db'];

	$summary = \Sms\sendSummary($results, $recipients);

	// Re-index so positional checks are reliable.
	$results    = array_values($results);
	$recipients = array_values($recipients);

	// Detect positional pairing: same strategy as sendSummary() uses.
	// When counts match and every result phone equals the recipient phone,
	// we can pair by position — correctly handles shared mobile numbers.
	$usePositional = false;
	if (count($results) === count($recipients)) {
		$usePositional = true;
		foreach ($results as $i => $r) {
			if ($r->recipient()->value !== $recipients[$i]->getPhoneNumber()->value) {
				$usePositional = false;
				break;
			}
		}
	}

	// Insert all recipients on PartialSuccess so the audit trail records every attempt, not just successes
	$recipientsToInsert = match (true) {
		$summary instanceof \Sms\AllSent        => $summary->recipients,
		$summary instanceof \Sms\PartialSuccess  => array_merge($summary->successes, $summary->failures),
		$summary instanceof \Sms\Failed          => [],
		default                                   => [],
	};

	// Insert the sms body row via DB_Object
	$GLOBALS['system']->includeDBClass('sms');
	$sms = new \Sms();
	$sms->setValue('body', $messageText);
	$sms->setValue('sender', $senderId);
	$sms->setValue('created', date('Y-m-d H:i:s', time()));
	if ($wireSender !== '') {
		$sms->setValue('wire_sender', $wireSender);
	}
	if ($scheduledSendAt !== null) {
		$sms->setValue('scheduled_send_at', date('Y-m-d H:i:s', $scheduledSendAt));
	}
	if (!$sms->create()) {
		$recipientCount = count($recipients);
		$scheduledNote  = $scheduledSendAt !== null ? ', scheduledSendAt=' . date('Y-m-d H:i:s', $scheduledSendAt) : '';
		error_log(
			"[Jethro SMS] AUDIT FAILURE: insertSms() Sms::create() failed — "
			. "audit row NOT written. Gateway send already completed. "
			. "recipientCount={$recipientCount}, senderId=" . ($senderId ?? 'null')
			. ", provider={$provider}{$scheduledNote}"
		);
		return ['smsId' => 0, 'deliveries' => []];
	}
	$smsId = (int)$sms->id;

	// Insert per-recipient delivery rows.
	// When positional pairing holds, iterate (recipient, result) pairs directly
	// so shared-phone recipients each get their own correct delivery row.
	// When falling back to phone-keyed matching, find the first result whose
	// phone matches each recipient (pre-fix behaviour, unchanged).
	$personToBroadcast  = [];
	$personToDeliveryId = [];
	$recipStmt = $db->prepare('INSERT INTO smsdelivery (sms_id, personid, remote_id, raw_response, status, body, provider) VALUES (?, ?, ?, ?, ?, ?, ?)');

	if ($usePositional) {
		// Positional: pair each original recipient with its result by index.
		// recipientsToInsert is derived from the summary and may differ in
		// count/identity from $recipients (on Failed it's empty; on AllSent /
		// PartialSuccess the summary objects are the same objects as the
		// original recipients but sourced via sendSummary's output).
		// For correctness with shared phones we bypass recipientsToInsert and
		// drive directly off the ($recipients, $results) positional pairs —
		// but only for AllSent/PartialSuccess (Failed inserts nothing).
		if (!($summary instanceof \Sms\Failed)) {
			foreach ($recipients as $i => $recip) {
				$recipientResult = $results[$i];
				$remoteId        = $recipientResult->remoteId();
				$remoteId        = ($remoteId !== null && $remoteId !== '') ? $remoteId : null;
				$rawResponse     = $recipientResult->rawResponse();
				$status          = $recipientResult->status()->toMySql();
				$recipStmt->execute([$smsId, $recip->personId, $remoteId, $rawResponse, $status, $recipientResult->body(), $provider]);
				$personToBroadcast[(string)$recip->personId]  = $smsId;
				$personToDeliveryId[(string)$recip->personId] = (int)$db->lastInsertId();
			}
		}
	} else {
		// Phone-keyed fallback: pre-fix behaviour, byte-identical for the
		// common non-shared-phone case.
		$destRemoteIdMap = [];
		foreach ($results as $r) {
			$rid = $r->remoteId();
			if ($rid !== null && $rid !== '') {
				$destRemoteIdMap[$r->recipient()->value] = $rid;
			}
		}
		foreach ($recipientsToInsert as $recip) {
			$phone           = $recip->getPhoneNumber()->value;
			$recipientResult = null;
			foreach ($results as $r) {
				if ($r->recipient()->value === $phone) {
					$recipientResult = $r;
					break;
				}
			}
			$rawResponse = $recipientResult ? $recipientResult->rawResponse() : '';
			$status      = $recipientResult ? $recipientResult->status()->toMySql() : \Sms\SmsStatus::SENDING->toMySql();
			$recipStmt->execute([$smsId, $recip->personId, $destRemoteIdMap[$phone] ?? null, $rawResponse, $status, $recipientResult?->body(), $provider]);
			$personToBroadcast[(string)$recip->personId]  = $smsId;
			$personToDeliveryId[(string)$recip->personId] = (int)$db->lastInsertId();
		}
	}

	return ['smsId' => $smsId, 'deliveries' => $personToDeliveryId];
}

/**
 * Classify an aggregate of delivery status counts into a single log-file label.
 *
 * Used by the export-smslog CLI can
 * reconstruct the same status strings from the sms + smsdelivery tables.
 *
 * @param int $okCount    Number of successful deliveries (delivered, sent, etc.)
 * @param int $failCount  Number of failed/cancelled deliveries
 * @param int $totalCount Total delivery count for this send
 * @return string  'sent', 'partial', 'failed', or 'unknown'
 */
function classifySmsStatus(int $okCount, int $failCount, int $totalCount): string
{
    if ($totalCount === 0) {
        return 'unknown';
    }
    if ($okCount === $totalCount) {
        return 'sent';
    }
    if ($failCount === $totalCount) {
        return 'failed';
    }
    return 'partial';
}

/**
 * Get SMS delivery history for a person.
 * Viewers lacking PERM_VIEWSMS only see messages sent by themselves.
 *
 * @param int $personId
 * @return array<int, array<string, mixed>>  Deliveries keyed by smsdelivery ID, ordered by created DESC
 */
function getPersonSmsHistory(int $personId): array
{
	$db = $GLOBALS['db'];
	$SQL = 'SELECT s.id, COALESCE(sd.body, s.body) AS body, s.sender, s.created, sd.raw_response AS status,'
		. ' (sd.status IN (\'queued\',\'sent\',\'delivered\',\'test-message\')) AS isdelivered,'
		. ' sd.id AS delivery_id, sd.remote_id, COALESCE(NULLIF(sd.status, ""), "unknown") AS delivery_status,'
		. ' p.first_name AS sender_fn, p.last_name AS sender_ln,'
		. ' (SELECT COUNT(*) FROM smsdelivery WHERE sms_id = s.id) AS recipient_count,'
			. ' sn.note_id, sd.provider, sd.delivered_at, s.scheduled_send_at'
		. ' FROM sms s'
		. ' JOIN smsdelivery sd ON sd.sms_id = s.id'
		. ' LEFT JOIN _person p ON p.id = s.sender'
		. ' LEFT JOIN sms_note sn ON sn.smsdelivery_id = sd.id AND sn.note_personid = sd.personid'
		. ' WHERE sd.personid = ' . (int)$personId;
	if (!$GLOBALS['user_system']->havePerm(PERM_VIEWSMS)) {
		$currentUserId = (int) ($GLOBALS['user_system']->getCurrentUser('id') ?? 0);
		$SQL .= ' AND s.sender = ' . $currentUserId;
	}
	$SQL .= ' ORDER BY s.created DESC';
	return $db->queryAll($SQL, null, null, 'delivery_id');
}


/**
 * Check whether SMSes are both enabled and correctly configured, and Jethro is thus able to send SMSes.
 * Shortcut for `isFeatureEnabled() && isConfigured()`
 */
function isUsable(): bool
{
    return isFeatureEnabled() && isConfigured();
}

/**
 * Check whether the default SMS gateway is configured with all necessary settings.
 * Most callers should prefer {@link isUsable()}.
 */
function isConfigured(): bool
{
    return getSmsProvider()->isSuccess();
}

/**
 * Check if the SMS feature is enabled in the system settings.
 * Most callers should prefer {@link isUsable()}.
 */
function isFeatureEnabled(): bool
{
    // ENABLED_FEATURES is defined by Config_Manager::init() before
    // User_System is constructed, so we can read it directly here.
    // We cannot rely on $GLOBALS['system'] being available: it is only
    // assigned after a successful login (index.php), not during the
    // login-form POST itself.
    if (!isset($GLOBALS['system'])) {
        $features = array_map('strtoupper', explode(',', ifdef('ENABLED_FEATURES', '')));
        return in_array('SMS', $features);
    }
    return $GLOBALS['system']->featureEnabled('SMS');
}

/**
 * Check whether the configured SMS provider's POST_TEMPLATE uses the _USER_MOBILE_ keyword.
 */
function usesUserMobile(): bool
{
	$providerResult = getSmsProvider();
	if ($providerResult->isFailure()) return false;
	$provider = $providerResult->getValue();
	return $provider instanceof \Sms\Providers\TemplateSmsProvider && $provider->usesUserMobile();
}
