---
title: SMS shortener (Shorter alternative)
sidebar_position: 26
---

# SMS shortener: the composer's "Shorter alternative"

When a multi-segment SMS is going to more than one recipient, the composer
suggests an AI-written rewording that is one segment shorter, with a
**Use instead?** link, headed by the saving ("Reduces cost from $2.40 to $1.20").

## Moving parts

| Piece | Role |
|---|---|
| [jethro-sms-shortener](https://github.com/jethro-chms/jethro-sms-shortener) | Separate project: a Cloudflare Worker (`POST https://jethro.au/api/sms-shorten`) that rewords with Workers AI, plus the PHP client `getSmsShortener()` / `smsShortenerConfig()`. Composer package `jethro/jethro-sms-shortener`, pulled in like `jethro/jethro-url-shortener`. |
| `include/sms_shortener.php` | Bridge: loads the package through the Composer autoloader. Required by `include/jethro_sms.php`. |
| `Jethro\Sms\printTextbox()` | Adds `data-on:input__debounce.3000ms="@post('?call=sms_shorten', …)"` to the textarea (only when `shorterSuggestionAvailable()`), and the empty `#sms-shorter<suffix>` box beside it in a wrapping flex row (`.sms-compose-row`). |
| `calls/call_sms_shorten.class.php` (`Call_SMS_Shorten`) | SSE endpoint: decides whether to ask, asks, re-counts, and streams the box and the `$smsshortersource<suffix>` signal. |
| `Jethro\Sms\wantsShorterSuggestion()`, `templateSegmentCount()` | The gate (below). |
| `Jethro\Sms\renderShorterSuggestion()` | The box: suggestion text, `data-suggestion`, `data-show`, link and saving. |
| `JethroSMS.useShorterSuggestion()` (`resources/js/jethro-sms.js`) | Puts the suggestion into the textarea and fires `input`. |
| `.sms-shorter` (`resources/less/jethro.less.php`) | 0.8 s fade-in each time `data-show` reveals the box. |

## Settings

- Suggestions need SMS shortening enabled in the *jethro.au Services*
  section (`JethroAu::serviceEnabled('sms-shortener')`: a `JETHROAU_API_KEY`
  that has agreed to the SMS shortener's terms). Otherwise there is no
  3-second listener. See [jethro.au services](jethroau-services.md).
- `SMS_SHORTEN_MESSAGE_LIMIT` (DB setting, text, default `1.0`): the largest
  share of a message's text, 0.0 to 1.0, that may need cutting for a
  suggestion to be offered (see Flow, step 2). Blank or non-numeric = 1.0.
- `SMS_SHORTEN_MESSAGE_MINSAVINGS` (DB setting, text, default `1`): the
  smallest saving, in dollars across all recipients, worth a suggestion
  (`shorterSuggestionMinSavings()`, `shorterSuggestionWorthIt()`). Priced
  with the statusline's segment cost; with none known it has no effect.
- `SMS_SHORTEN_MESSAGE_SESSION_MAX` (conf.php only, optional): the most AI
  calls one login session may make (`shorterSuggestionSessionMax()`,
  counted in `$_SESSION['sms_shorter_calls']` by
  `shorterSuggestionCountCall()`). Unset, `0` or non-numeric = unlimited.
- `SMSSHORTENER` (conf.php only, optional): `jethroau` (= `https://jethro.au`,
  the default) or the base URL of another Worker, e.g. a local test double.

## Enabling it

SMS shortening is one of the [jethro.au services](jethroau-services.md):
**Turn on SMS shortening** in the *jethro.au Services* status panel has the
admin agree to the SMS shortener's terms on behalf of the organisation: on
jethro.au after signing in (registering the site first if it has no key), or
in Jethro on a provider-managed site.
The Worker refuses keys without that agreement: it asks the account Worker
`GET /api/authorize?service=sms-shortener` through a service binding.

### Privacy

SMS text leaves the church's systems, so before sending the admin to
jethro.au the panel shows `JethroAu::services()['sms-shortener']['notice']`
(what is sent, to whom) and links the privacy page
(`smsShortenerConfig()['privacy']`, `https://jethro.au/sms-shortener/privacy`,
served by the Worker). jethro.au shows the same terms and records the
agreement.

## Flow

1. The textarea posts the form to `?call=sms_shorten` after 3 seconds without
   typing. Every keystroke restarts the debounce, and Datastar cancels an
   in-flight request from the same element when the next one starts.
2. `Call_SMS_Shorten` asks only when `wantsShorterSuggestion()` holds:
   - more than one recipient (`getRecipientsFromRequest()->recipients`);
   - the composed text (`%tokens%` unexpanded; long URLs at their shortened
     length when **Shorten URLs** is on) uses more than one segment;
   - fitting one segment fewer means cutting at most `SMS_SHORTEN_MESSAGE_LIMIT`
     of the text (`shorterSuggestionMaxCut()`: 0.0 to 1.0, default 1.0 =
     always), measured against GSM-7 capacity because rewordings are GSM-7.
     At 0.3, 161 characters (cut 1) qualify and 300 (cut 140) do not.
3. It reads the segment cost (`makeStatuslineConfig()->segmentCost`), closes
   the session (the model call takes seconds and would otherwise block the
   statusline posts), and, if the most any rewording could save (all but one
   segment, per recipient) reaches `SMS_SHORTEN_MESSAGE_MINSAVINGS` and the
   session has calls left (`shorterSuggestionCallAllowed()`; the call is
   counted before the session closes, so failed calls count too), calls
   `getSmsShortener()->shorten($message, $segments - 1, $maxCut)`.
   Recipients are counted per phone number, as the statusline does.
   The Worker applies the same limit (`max_cut`; its own default is 0.3 for
   other API clients) before calling the model.
   The segment count is a maximum: the Worker drops emoji (which force UCS-2)
   and may aim lower, e.g. a 6-segment UCS-2 message can come back as 2 GSM-7
   segments.
4. A rewording is offered only if it keeps every `%token%` exactly
   (`templateTokenCounts()` of the rewording and the typed text are equal:
   same tokens, same number of each), Jethro's own count agrees it saves a
   segment, and the actual saving reaches `SMS_SHORTEN_MESSAGE_MINSAVINGS`.
   A rewording whose tokens differ is logged and not offered.
   API errors are logged and give an empty box. The client gives up after
   12 s (3 s to connect), so an unreachable or hung jethro.au doesn't hold a
   PHP worker for long; the user just sees no suggestion.
5. The response patches the `$smsshortersource<suffix>` signal (the text that
   was reworded, with `\n` line ends to match the textarea), then the box.
6. The box's "(shorter | longer)" links (`shorterSuggestionResizeLinks()`)
   post the form again with `?segments=N`, one segment fewer or more than the
   suggestion (shorter while it is over one segment; longer while one more
   still saves a segment). `N` is capped at one fewer than the typed message
   has, so a hand-made URL can't ask for a rewording that saves nothing.
   That request ignores `SMS_SHORTEN_MESSAGE_LIMIT`
   and `SMS_SHORTEN_MESSAGE_MINSAVINGS`, as the user asked for it, and leaves
   the box alone if it fails. `$smsrewording<suffix>` (data-indicator) shows
   "rewording…" meanwhile.
7. Rewordings are cached in the session, keyed by the composed text, the
   Shorten URLs setting and the segment count asked for
   (`shorterSuggestionCacheKey()`), keeping the 20 most recent. So "shorter"
   then "longer", or the same text posted again (e.g. after ticking
   recipients), shows the earlier rewording without another AI call. The
   session is closed during the AI call and briefly reopened to store the
   result.

## Staleness

The box's `data-show` is `$smsmessage<suffix> === $smsshortersource<suffix>`,
so the box is shown only while the textarea holds exactly the text that was
reworded. Typing hides it at once, and a reply that arrives after more
typing is never shown. **Use instead?** changes the textarea, so the box
hides itself; the `input` event it fires re-runs the statusline (300 ms) and
the 3-second check, which finds the new text one segment shorter and morphs
the box back to empty.

## The Worker

See the project's README. In short: it normalises typographic characters to
GSM-7, works out its target from the text without emoji (dropping them is
sometimes enough on its own), and asks `@cf/meta/llama-3.3-70b-instruct-fp8-fast`
for the smallest edits that land just under the boundary, keeping the original
wording and details. It counts segments itself, retries up to 3 times (too
long, a lost token, or cut far more than needed), and returns the longest
rewrite that fits.
Placeholders, URLs and phone numbers must survive verbatim. Keys are capped at
20 requests a minute. Workers AI includes 10,000 Neurons a day; one attempt
costs about 19.

## Tests

- `tests/unit/test_sms_shortener.php`: the gate, segment counting, token
  counting, the session call limit, the box's rendering, escaping and saving
  line, and `smsShortenerConfig()`.
- The Worker's own tests (`node test_worker.js`) run offline with a fake
  Workers AI binding.
- There is no functional test: the mock SMS server has no SMS shortener
  endpoint. To try the UI locally, point `SMSSHORTENER` at a Worker run with
  `npx cf dev` (needs a Cloudflare login, because Workers AI runs remotely).
