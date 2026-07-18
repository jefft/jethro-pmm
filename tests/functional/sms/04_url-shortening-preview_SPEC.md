# Spec 04: URL shortening — preview shows shortened URL

## Goal
Verify that when `SMS_SHORTEN_URLS=true` is configured and the message contains an `https://` URL, the preview panel displays a locally-shortened URL (`https://jethro.au/s/{hash}`) instead of the original long URL.

**Scope**: Preview mode only. `previewShorten()` is deterministic and makes no external API calls, so this test is safe. The actual Send is NOT clicked to avoid hitting the real `jethro.au` shortener API.

## Test people
**Family 2: The Calvins** (familyid=2).  Open via the family bulk-SMS form
(`?view=families&familyid=2`), which has a preview panel — unlike the
single-person modal.

Do not use persons 4 or 5.

## Playwright scenario name
`sms-url-shortening-preview`

## `.conf` override file
`tests/functional/sms/sms-url-shortening-preview.conf`

```php
<?php
// Test scenario: sms-url-shortening-preview
// SMS_SHORTEN_URLS auto-wraps bare https:// URLs via %(shorten "...")%.
// Preview uses previewShorten() which is local/deterministic — no external call.
define('SMS_CELLCAST_URL', 'http://127.0.0.1:12345/cellcast');
define('SMS_SHORTEN_URLS', true);
define('URLSHORTENER', 'jethroau');
define('URLSHORTENER_API_KEY', 'test-key-not-used-in-preview');
```

## Mock config
Reuse **`tests/functional/sms/smsmockserver/cellcast.json`** (Send is not clicked, but the statusline POST still goes through the PHP stack).

## Playwright config entry
Add `"sms-url-shortening-preview"` to the `SCENARIOS` array in `playwright.config.ts`.

## Test steps
### Test: "Preview shows shortened URL, not original URL"

1. Navigate to `?view=families&familyid=2` (The Calvins).
2. The bulk SMS form is hidden by default — select `smshttp` from the
   `#bulk-action-chooser` dropdown to reveal it.
3. Check the `.sms-preview-checkbox` to enable preview mode.
4. In the `.sms-message` textarea, type:
   `See details at https://example.com/very-long-path/to/some/event?utm_source=church&utm_medium=sms`
5. Wait for the statusline SSE response (`call=sms_statusline`) and the
   `.sms-preview-panel` to become visible.
6. In the preview panel, assert:
   - The original URL `https://example.com/very-long-path/...` is **not** present.
   - A shortened URL matching `https://jethro.au/s/` is present.
7. **Do NOT click Send** — leave the form open or navigate away.

## Expected short URL computation
The preview hash uses `substr(URLSHORTENER_API_KEY, 0, 8)` as the campaign salt:
`substr(hash('sha256', '<url>::test-key'), 0, 6)`

For the test URL above:
`https://jethro.au/s/e733b0`

Assert the exact value (`e733b0`), or assert `toContain('https://jethro.au/s/')`
if the exact hash is not needed for confidence.

## Assertions summary
- Preview panel contains `https://jethro.au/s/e733b0` (not the original URL).

## Safety note
`previewShorten()` is purely local (SHA-256 hash, no HTTP). No real SMS gateway is called because Send is not clicked. `URLSHORTENER_API_KEY` is a placeholder and is never used in this test.
