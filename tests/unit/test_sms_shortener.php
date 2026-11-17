<?php

/**
 * The SMS composer's "Shorter alternative": when a message qualifies
 * (Jethro\Sms\wantsShorterSuggestion()), how it is counted, how the box is
 * rendered, and how SMSSHORTENER is interpreted (smsShortenerConfig(), from
 * the jethro/jethro-sms-shortener package).
 *
 * See docs/docs/developer/reference/sms-shortener.md.
 */

namespace Test\SmsShortener;

use function Jethro\Sms\{renderShorterSuggestion, shorterSuggestionCacheGet, shorterSuggestionCacheKey, shorterSuggestionCachePut, shorterSuggestionCallAllowed, shorterSuggestionCountCall, shorterSuggestionMaxCut, shorterSuggestionMinSavings, shorterSuggestionWorthIt, templateSegmentCount, templateTokenCounts, wantsShorterSuggestion};
use function Test\{test, assert_eq, assert_true, assert_false, assert_contains, assert_not_contains};

require_once __DIR__ . '/../../include/general.php';
require_once __DIR__ . '/../../include/jethro_sms.php';

test('SMSSHORTENER: jethroau alias, http(s) base URLs, blank and other schemes', function () {
	foreach (['jethroau', 'JethroAu/', 'https://jethro.au', ' https://jethro.au/ '] as $value) {
		$config = \smsShortenerConfig($value);
		assert_true($config['supported'], "expected '$value' to be supported");
		assert_eq(strtolower($config['base']), 'https://jethro.au', "base for '$value'");
	}
	assert_eq(\smsShortenerConfig('http://127.0.0.1:8799/')['base'], 'http://127.0.0.1:8799');
	$blank = \smsShortenerConfig('');
	assert_false($blank['configured']);
	assert_false($blank['supported']);
	$ftp = \smsShortenerConfig('ftp://example.org');
	assert_true($ftp['configured']);
	assert_false($ftp['supported']);
});

test('only multi-segment messages to more than one recipient qualify', function () {
	$two = str_repeat('a', 161);
	assert_true(wantsShorterSuggestion($two, 2, false, 1.0));
	assert_false(wantsShorterSuggestion($two, 1, false, 1.0), 'one recipient');
	assert_false(wantsShorterSuggestion(str_repeat('a', 160), 5, false, 1.0), 'one segment');
	assert_false(wantsShorterSuggestion('   ', 5, false, 1.0), 'blank');
});

test('SMS_SHORTEN_MESSAGE_LIMIT defaults to 1.0: every multi-segment message qualifies', function () {
	if (defined('SMS_SHORTEN_MESSAGE_LIMIT')) return; // set by another test file; the default is untestable here
	assert_eq(shorterSuggestionMaxCut(), 1.0);
	assert_true(wantsShorterSuggestion(str_repeat('a', 300), 2, false, shorterSuggestionMaxCut()), '300 into 160: cut 47%');
});

test('a lower limit skips messages that need more than that share cut', function () {
	assert_true(wantsShorterSuggestion(str_repeat('a', 228), 2, false, 0.3), '228 into 160: cut 30%');
	assert_false(wantsShorterSuggestion(str_repeat('a', 230), 2, false, 0.3), '230 into 160: cut 30.4%');
	assert_false(wantsShorterSuggestion(str_repeat('a', 300), 2, false, 0.3), '300 into 160');
	assert_true(wantsShorterSuggestion(str_repeat('a', 310), 2, false, 0.3), '310 into 306: cut 1.3%');
	assert_true(wantsShorterSuggestion('’' . str_repeat('a', 100), 2, false, 0.3), 'UCS-2 within GSM-7 capacity');
	assert_false(wantsShorterSuggestion(str_repeat('a', 161), 2, false, 0.0), '0.0: any cut is too much');
});

test('SMS_SHORTEN_MESSAGE_MINSAVINGS defaults to $1', function () {
	if (defined('SMS_SHORTEN_MESSAGE_MINSAVINGS')) return; // set by another test file; the default is untestable here
	assert_eq(shorterSuggestionMinSavings(), 1.0);
});

test('a saving must reach the minimum, in cents; an unknown segment cost always passes', function () {
	assert_true(shorterSuggestionWorthIt(200, 1, 0.03, 5.0), '200 × 1 × $0.03 = $6.00');
	assert_false(shorterSuggestionWorthIt(100, 1, 0.03, 5.0), '$3.00');
	assert_true(shorterSuggestionWorthIt(100, 2, 0.025, 5.0), 'exactly $5.00');
	assert_false(shorterSuggestionWorthIt(4, 1, 0.02, 5.0), 'a small group: $0.08');
	assert_true(shorterSuggestionWorthIt(4, 1, 0.02, 0.0), 'a $0 minimum accepts any saving');
	assert_true(shorterSuggestionWorthIt(4, 1, 0.0, 5.0), 'no segment cost known: cannot be priced');
});

test('segments follow GSM-7 / UCS-2 rules, as the statusline counts them', function () {
	assert_eq(templateSegmentCount(str_repeat('a', 160), false), 1);
	assert_eq(templateSegmentCount(str_repeat('a', 161), false), 2);
	assert_eq(templateSegmentCount(str_repeat('€', 81), false), 2, 'extension characters count twice');
	assert_eq(templateSegmentCount('’' . str_repeat('a', 70), false), 2, 'one non-GSM character forces UCS-2');
});

test('with Shorten URLs on, a long URL counts as its shortened length', function () {
	$message = str_repeat('a', 120) . ' https://example.com/' . str_repeat('x', 60);
	assert_eq(templateSegmentCount($message, false), 2);
	assert_eq(templateSegmentCount($message, true), 1);
	assert_false(wantsShorterSuggestion($message, 3, true, 1.0));
});

test('the empty box is a hidden morph target', function () {
	assert_eq(renderShorterSuggestion('-bulk'), '<div id="sms-shorter-bulk" class="sms-shorter" style="display:none"></div>');
	assert_contains(renderShorterSuggestion(''), 'id="sms-shorter"');
});

test('a suggestion shows only while the textarea still holds the reworded text', function () {
	$bulk = renderShorterSuggestion('-bulk', 'Hi all', 2, 1);
	assert_contains($bulk, 'data-show="$smsmessagebulk === $smsshortersourcebulk"');
	assert_contains($bulk, '<strong>Shorter alternative</strong> <span class="sms-shorter-saving">(Saves 1 segment per recipient)</span>:');
	assert_contains($bulk, 'JethroSMS.useShorterSuggestion(el)');
	assert_contains(renderShorterSuggestion('', 'Hi all', 3, 2), 'data-show="$smsmessage === $smsshortersource"');
});

test('the heading gives the saving as old and new cost, or segments when cost is unknown', function () {
	// 3 recipients at $0.043 a segment: 2 segments = $0.26, 1 segment = $0.13 (as the statusline rounds).
	$html = renderShorterSuggestion('', 'Hi all', 2, 1, 3, 0.043);
	assert_contains($html, '<strong>Shorter alternative</strong> <span class="sms-shorter-saving">(Reduces cost from $0.26 to $0.13)</span>:');
	assert_not_contains($html, 'instead of', 'no segment counts when the cost is known');
	assert_contains($html, 'Use instead?</a></div>');
	assert_contains(renderShorterSuggestion('', 'Hi all', 3, 1, 3, 0.0), '(Saves 2 segments per recipient)');
});

test('"(shorter | longer)" re-ask for one segment fewer or more, within 1 and the typed message', function () {
	$middle = html_entity_decode(renderShorterSuggestion('-bulk', 'Hi all', 5, 3), ENT_QUOTES);
	assert_contains($middle, "@post('?call=sms_shorten&segments=2', {contentType: 'form'})\">shorter</a>");
	assert_contains($middle, ' | ');
	assert_contains($middle, "@post('?call=sms_shorten&segments=4', {contentType: 'form'})\">longer</a>");
	assert_contains($middle, 'data-indicator:smsrewordingbulk');
	$atOne = renderShorterSuggestion('-bulk', 'Hi all', 3, 1);
	assert_not_contains($atOne, '>shorter</a>', 'already one segment');
	assert_contains($atOne, '>longer</a>');
	$justUnder = renderShorterSuggestion('-bulk', 'Hi all', 4, 3);
	assert_not_contains($justUnder, '>longer</a>', 'one more would save nothing');
	assert_not_contains(renderShorterSuggestion('-bulk', 'Hi all', 2, 1), '(shorter', 'neither applies');
});

test('rewordings are cached per text, Shorten URLs and segment count, keeping the 20 most recent', function () {
	$saved = $_SESSION ?? null;
	$_SESSION = [];
	$key = shorterSuggestionCacheKey('Hello there', false, 2);
	assert_eq(shorterSuggestionCacheGet($key), null);
	shorterSuggestionCachePut($key, 'Hi there');
	assert_eq(shorterSuggestionCacheGet($key), 'Hi there');
	assert_eq(shorterSuggestionCacheGet(shorterSuggestionCacheKey('Hello there', false, 1)), null, 'other segment count');
	assert_eq(shorterSuggestionCacheGet(shorterSuggestionCacheKey('Hello there', true, 2)), null, 'Shorten URLs on');
	for ($i = 0; $i < 20; $i++) {
		shorterSuggestionCachePut(shorterSuggestionCacheKey("text $i", false, 1), "short $i");
	}
	assert_eq(shorterSuggestionCacheGet($key), null, 'the oldest is dropped');
	assert_eq(shorterSuggestionCacheGet(shorterSuggestionCacheKey('text 19', false, 1)), 'short 19');
	assert_eq(count($_SESSION['sms_shorter_cache']), 20);
	$_SESSION = $saved;
});

test('token counts: variables and function calls, not bare percent signs', function () {
	$message = 'Hi %firstname% (%selected.firstname%), 20% off! %firstname%: %(shorten "https://example.com/a%20b")%';
	assert_eq(templateTokenCounts($message), [
		'%(shorten "https://example.com/a%20b")%' => 1,
		'%firstname%' => 2,
		'%selected.firstname%' => 1,
	]);
	assert_eq(templateTokenCounts('Sale: 20% off, 30% more'), [], 'bare percent signs');
});

test('a rewording keeps its tokens only with the same tokens the same number of times', function () {
	$typed = 'Hi %firstname%, see %(shorten "https://example.com")% - %firstname%';
	assert_true(templateTokenCounts('%firstname%: %(shorten "https://example.com")% %firstname%') === templateTokenCounts($typed), 'reordered');
	assert_false(templateTokenCounts('Hi %firstname%, see %(shorten "https://example.com")%') === templateTokenCounts($typed), 'one dropped');
	assert_false(templateTokenCounts('Hi %first_name%, see %(shorten "https://example.com")% - %firstname%') === templateTokenCounts($typed), 'one renamed');
	assert_false(templateTokenCounts('Hi %firstname% %lastname%, see %(shorten "https://example.com")% - %firstname%') === templateTokenCounts($typed), 'one added');
});

test('SMS_SHORTEN_MESSAGE_SESSION_MAX: unlimited by default, then counted per session', function () {
	$saved = $_SESSION ?? null;
	$_SESSION = [];
	if (!defined('SMS_SHORTEN_MESSAGE_SESSION_MAX')) {
		for ($i = 0; $i < 100; $i++) {
			shorterSuggestionCountCall();
		}
		assert_true(shorterSuggestionCallAllowed(), 'unset = unlimited');
		$_SESSION = [];
		define('SMS_SHORTEN_MESSAGE_SESSION_MAX', 2);
	}
	$max = (int) SMS_SHORTEN_MESSAGE_SESSION_MAX;
	for ($i = 0; $i < $max; $i++) {
		assert_true(shorterSuggestionCallAllowed(), "call " . ($i + 1));
		shorterSuggestionCountCall();
	}
	assert_false(shorterSuggestionCallAllowed(), 'limit reached');
	$_SESSION = [];
	assert_true(shorterSuggestionCallAllowed(), 'a new session starts again');
	$_SESSION = $saved;
});

test('the suggestion is escaped in both the attribute and the text', function () {
	$html = renderShorterSuggestion('', "Tea & \"cake\" <b>now</b>\nbye", 2, 1);
	assert_contains($html, 'data-suggestion="Tea &amp; &quot;cake&quot; &lt;b&gt;now&lt;/b&gt;' . "\nbye\"");
	assert_contains($html, '<div class="sms-shorter-text">Tea &amp; &quot;cake&quot; &lt;b&gt;now&lt;/b&gt;');
	assert_not_contains($html, '<b>now');
});
