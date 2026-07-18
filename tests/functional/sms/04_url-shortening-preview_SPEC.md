# Spec 04: URL shortening — preview shows shortened URL

## Goal
Verify that when `SMS_SHORTEN_URLS=true` is configured and the message contains an `https://` URL, the preview panel displays a locally-shortened URL (`https://jethro.au/s/{hash}`) instead of the original long URL.

**Scope**: Preview mode only. `previewShorten()` is deterministic and makes no external API calls, so this test is safe. The actual Send is NOT clicked to avoid hitting the real `jethro.au` shortener API.

## Test people
**Person 2: John Calvin** (familyid=2), mobile 0491570156.
Open via the SMS modal on John Calvin's person page (`?view=persons&personid=2`).

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

1. Navigate to `?view=persons&personid=2` (John Calvin).
2. Click the mobile number link to open the SMS modal (or the SMS trigger button).
3. In the message textarea, type: `See details at https://example.com/very-long-path/to/some/event?utm_source=church&utm_medium=sms`
4. Check the "Message Preview" checkbox (if present in single-person modal, otherwise skip — the statusline preview panel is the goal).
   - If the modal doesn't have a preview checkbox, just wait for the statusline to render after the debounce.
5. Wait for the preview panel (`#sms-preview-panel`) to appear and populate.
6. In the preview text for John Calvin's row, assert:
   - The original URL `https://example.com/very-long-path/...` is **not** present.
   - A shortened URL matching `https://jethro.au/s/` is present.
7. Also check the statusline character count reflects the shorter URL length (should be significantly shorter than the full URL).
8. **Do NOT click Send** — leave the modal open or close it via the Cancel/X button.

## Expected short URL computation
The preview hash is: `substr(hash('sha256', 'https://example.com/very-long-path/to/some/event?utm_source=church&utm_medium=sms' . '::sms'), 0, 6)`

The implementing agent should compute this and assert the exact value, or assert `toContain('https://jethro.au/s/')` if the exact hash is not needed for confidence.

## Assertions summary
- Preview panel contains `https://jethro.au/s/` (not the original URL).
- Statusline char count reflects shortened URL length.

## Safety note
`previewShorten()` is purely local (SHA-256 hash, no HTTP). No real SMS gateway is called because Send is not clicked. `URLSHORTENER_API_KEY` is a placeholder and is never used in this test.
