<?php

/**
 * SSE endpoint for the SMS composer's "Shorter alternative" suggestion.
 *
 * The composer textarea posts its form here after 3 seconds without typing
 * (printTextbox()). When the message goes to more than one recipient and is
 * longer than one segment (Jethro\Sms\wantsShorterSuggestion()), and the
 * saving could reach SMS_SHORTEN_MESSAGE_MINSAVINGS dollars
 * (Jethro\Sms\shorterSuggestionWorthIt()), this asks the jethro.au SMS
 * shortener (\getSmsShortener(), Workers AI) for a rewording one segment
 * shorter. A rewording that keeps every %token% (same tokens, same counts:
 * Jethro\Sms\templateTokenCounts()) and that Jethro's own segment maths
 * agrees saves at least that much is streamed back as the
 * #sms-shorter<suffix> box, plus the $smsshortersource<suffix> signal
 * holding the text it rewords; anything else (not qualifying, not
 * configured, API error, a lost or changed token, too small a saving, the
 * session's SMS_SHORTEN_MESSAGE_SESSION_MAX AI calls used up) morphs the
 * box back to empty.
 *
 * With ?segments=N (the box's "shorter" / "longer" links), it asks for at
 * most N segments (at most one fewer than the message has) regardless of
 * SMS_SHORTEN_MESSAGE_LIMIT and SMS_SHORTEN_MESSAGE_MINSAVINGS, and leaves
 * the current box alone if that fails.
 *
 * See docs/docs/developer/reference/sms-shortener.md.
 *
 * @see \Jethro\Sms\renderShorterSuggestion()
 */
class Call_SMS_Shorten extends Call
{
	public function run(): void
	{
		if (!$GLOBALS['user_system']->havePerm(PERM_SENDSMS)) {
			http_response_code(403);
			return;
		}

		require_once JETHRO_ROOT . '/include/jethro_sms.php';
		require_once JETHRO_ROOT . '/include/sse.php';

		$idSuffix = ($_POST['sms_form'] ?? '') === 'bulk' ? '-bulk' : '';
		// The textarea's value (and so its bound signal) uses \n; form posts use \r\n.
		$message = str_replace("\r\n", "\n", (string)array_get($_POST, 'message', ''));

		// "(shorter | longer)" ask for a given segment count: the admin's limits
		// (SMS_SHORTEN_MESSAGE_LIMIT, SMS_SHORTEN_MESSAGE_MINSAVINGS) are for
		// unasked-for suggestions, so they don't apply.
		$requested = isset($_GET['segments']) ? max(1, (int)$_GET['segments']) : null;

		$suggestion = null;
		$fromSegments = $toSegments = $recipientCount = 0;
		$segmentCost = 0.0;
		if (\Jethro\Sms\shorterSuggestionAvailable()) {
			$shortenUrls = \Jethro\Sms\shortenUrlsFromRequest();
			// One message per phone number, as the statusline counts them: people
			// who share a mobile get one SMS between them.
			$recipientCount = count(array_unique(array_map(
				fn ($r): string => $r->getPhoneNumber()->value,
				\Jethro\Sms\getRecipientsFromRequest()->recipients ?? []
			)));
			$maxCut = $requested !== null ? 1.0 : \Jethro\Sms\shorterSuggestionMaxCut();
			if (\Jethro\Sms\wantsShorterSuggestion($message, $recipientCount, $shortenUrls, $maxCut)) {
				// Read before closing the session: the config may cache provider data in it.
				$segmentCost = \Jethro\Sms\makeStatuslineConfig($shortenUrls)->segmentCost;
				$fromSegments = \Jethro\Sms\templateSegmentCount($message, $shortenUrls);
				$minSavings = $requested !== null ? 0.0 : \Jethro\Sms\shorterSuggestionMinSavings();
				// Never ask for as many segments as the message already has:
				// such a rewording could save nothing.
				$asked = min($requested ?? $fromSegments - 1, $fromSegments - 1);
				$cacheKey = \Jethro\Sms\shorterSuggestionCacheKey($message, $shortenUrls, $asked);
				$reworded = \Jethro\Sms\shorterSuggestionCacheGet($cacheKey);
				// Skip the AI call when even the best rewording (down to one
				// segment) couldn't save SMS_SHORTEN_MESSAGE_MINSAVINGS, or the
				// session has used its SMS_SHORTEN_MESSAGE_SESSION_MAX calls.
				if ($reworded === null
					&& \Jethro\Sms\shorterSuggestionWorthIt($recipientCount, $fromSegments - 1, $segmentCost, $minSavings)
					&& \Jethro\Sms\shorterSuggestionCallAllowed()
				) {
					// Counted before the call, so a failed call counts too.
					\Jethro\Sms\shorterSuggestionCountCall();
					// The model call takes seconds: don't hold the session lock,
					// which would stall the statusline posts meanwhile.
					if (session_status() === PHP_SESSION_ACTIVE) {
						session_write_close();
					}
					try {
						$reworded = \getSmsShortener()->shorten($message, $asked, $maxCut)['message'];
						// Briefly reopen the session to remember it, so "shorter"
						// then "longer" (or a re-post of the same text) needs no
						// second AI call. No output has been sent yet.
						if (session_status() === PHP_SESSION_NONE && session_start()) {
							\Jethro\Sms\shorterSuggestionCachePut($cacheKey, $reworded);
							session_write_close();
						}
					} catch (\RuntimeException $e) {
						error_log('Call_SMS_Shorten: ' . $e->getMessage());
					}
				}
				if ($reworded !== null) {
					$toSegments = \Jethro\Sms\templateSegmentCount($reworded, $shortenUrls);
					$tokensKept = \Jethro\Sms\templateTokenCounts($reworded) === \Jethro\Sms\templateTokenCounts($message);
					if (!$tokensKept) {
						error_log('Call_SMS_Shorten: rewording dropped, added or changed a %token%; not offered');
					}
					if ($tokensKept
						&& $toSegments < $fromSegments
						&& \Jethro\Sms\shorterSuggestionWorthIt($recipientCount, $fromSegments - $toSegments, $segmentCost, $minSavings)
					) {
						$suggestion = $reworded;
					}
				}
			}
		}

		\Jethro\sseStart();
		if ($suggestion === null && $requested !== null) {
			// A "shorter"/"longer" that couldn't be done keeps the current suggestion.
			return;
		}
		if ($suggestion !== null) {
			// Before the element, so its data-show sees the new source text.
			\Jethro\ssePatchSignals([\Jethro\Sms\shorterSuggestionSignals($idSuffix)['source'] => $message]);
		}
		\Jethro\ssePatchElements(\Jethro\Sms\renderShorterSuggestion($idSuffix, $suggestion, $fromSegments, $toSegments, $recipientCount, $segmentCost));
	}
}
