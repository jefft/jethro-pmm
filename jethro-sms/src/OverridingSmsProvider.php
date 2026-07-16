<?php

declare(strict_types=1);

namespace Sms;

/**
 * Decorator that applies conf.php constant overrides before delegating.
 *
 *   - send(): validates the sender matches SMS_SENDER if set, or the
 *     SMS_SENDER_OPTIONS allowlist otherwise; applies SMS_SEND_COOLOFF
 *     delay to immediate sends.
 *   - getBalance(): if SMS_BALANCE is a hardcoded integer, returns it.
 *   - getSenderIds(): if SMS_SENDER_OPTIONS is set, parses and returns the CSV list.
 * @see DecoratingSmsProvider
 * @see PhoneNumber
 * @see SenderID
 */

class OverridingSmsProvider extends DecoratingSmsProvider
{
	public function __construct(
		SmsProvider $inner,
		private bool $userInitiated = true,
	) {
		parent::__construct($inner);
	}

	public function send(
		array     $entries,
		SmsSender $sender,
		?int      $sendAt = null,
		bool      $preview = false,
	): \Result
	{
		$hs = (string) ifdef('SMS_SENDER', '');
		if ($hs === '') { $hs = (string) ifdef('SENDER_ID', ''); }

		if ($hs !== '' && (string) $sender !== $hs) {
			return \Result::failure(
				'Sender mismatch: SMS_SENDER is set to "' . $hs
				. '" but caller passed "' . (string) $sender . '"'
			);
		}

		// Enforce the SMS_SENDER_OPTIONS allowlist server-side.
		if ($hs === '' && !$this->senderAllowed($sender)) {
			return \Result::failure(
				'Sender "' . (string) $sender . '" is not permitted by SMS_SENDER_OPTIONS.'
			);
		}

		// Apply SMS_SEND_COOLOFF delay to immediate (non-deferred) sends
		// only when user-initiated and the provider supports both deferred
		// send and cancellation — the cooloff is an undo window; without
		// cancel it's just added latency.  System-initiated sends (2FA,
		// reminders) skip the delay.
		if ($sendAt === null && $this->userInitiated) {
			$cooloff = (int) ifdef('SMS_SEND_COOLOFF', 30);
			if ($cooloff > 0
				&& $this->inner->hasCapability(SmsCapability::DEFERRED_SEND)
				&& $this->inner->hasCapability(SmsCapability::DEFERRED_SEND_CANCEL)) {
				$sendAt = time() + $cooloff;
			}
		}

		return parent::send($entries, $sender, $sendAt, $preview);
	}

	/**
	 * Whether the given sender passes the SMS_SENDER_OPTIONS allowlist.
	 *
	 * No allowlist configured → all senders allowed.  Literal match always
	 * passes.  Special tokens:
	 *   `_USER_MOBILE_` — permits any PhoneNumber sender (bridge layer resolves
	 *                      the token to the user's actual number before send()).
	 *   `_SENDER_IDS_`  — permits any sender whose value appears in the inner
	 *                      provider's getSenderIds() list.
	 * Empty segments from duplicate commas are ignored.
	 *
	 * Unit tests: jethro-sms/tests/overriding/test_sender_allowlist.php
	 */
	private function senderAllowed(SmsSender $sender): bool
	{
		$raw = (string) ifdef('SMS_SENDER_OPTIONS', '');
		if ($raw === '') {
			return true;
		}
		$configured = array_values(array_filter(
			array_map('trim', explode(',', $raw)),
			fn(string $s) => $s !== '',
		));
		// Literal match
		if (\in_array((string) $sender, $configured, true)) {
			return true;
		}
		// _USER_MOBILE_ permits any PhoneNumber sender
		if ($sender instanceof PhoneNumber && \in_array('_USER_MOBILE_', $configured, true)) {
			return true;
		}
		// _SENDER_IDS_ permits any sender whose value is in the inner provider's list
		if (\in_array('_SENDER_IDS_', $configured, true)) {
			$idsResult = parent::getSenderIds(getAll: true);
			if ($idsResult->isSuccess()) {
				foreach ($idsResult->getValue() as $s) {
					if ($s->value === (string) $sender) {
						return true;
					}
				}
			}
		}
		return false;
	}

    /**
     * Returns the sender IDs for the dropdown, honouring SMS_SENDER_OPTIONS.
     *
     * When SMS_SENDER_OPTIONS is absent, delegates unchanged to the inner provider.
     *
     * When present, two modes apply depending on whether `_SENDER_IDS_` appears
     * in the configured list:
     *
     * **`_SENDER_IDS_` present** — the token expands to every sender ID the inner
     * provider reports (via GET_SENDER_IDS).  Any additional literal IDs in the
     * configured list are appended (deduped by value, preserving inner provider
     * metadata where available).  If the inner provider has no GET_SENDER_IDS
     * capability, `_SENDER_IDS_` expands to nothing; only literal IDs remain.
     * An inner API failure is propagated as-is.
     *
     * **`_SENDER_IDS_` absent** — existing intersection behaviour: if the inner
     * provider has GET_SENDER_IDS, only upstream IDs whose values appear in the
     * configured list are returned; if there is no capability, the configured
     * list is parsed directly as the full sender-ID set.
     *
     * `_USER_MOBILE_` and empty segments are always ignored when building the
     * return list.
     *
     * @inheritDoc
     */
    public function getSenderIds(bool $getAll = false): \Result
    {
        if (!defined('SMS_SENDER_OPTIONS') || SMS_SENDER_OPTIONS === '') {
            return parent::getSenderIds($getAll);
        }

        $configured = array_values(array_filter(
            array_map('trim', explode(',', SMS_SENDER_OPTIONS)),
            fn(string $s) => $s !== '',
        ));

        $specialTokens = ['_USER_MOBILE_', '_SENDER_IDS_'];

        // ── _SENDER_IDS_ mode ────────────────────────────────────────────────
        // The token expands to every ID the inner provider knows about, plus
        // any literal IDs explicitly listed alongside it.
        if (\in_array('_SENDER_IDS_', $configured, true)) {
            $literalIds = array_values(array_filter(
                $configured,
                fn(string $s) => !\in_array($s, $specialTokens, true),
            ));

            $ids = [];
            if ($this->inner->hasCapability(SmsCapability::GET_SENDER_IDS)) {
                $innerResult = parent::getSenderIds(getAll: true);
                if ($innerResult->isFailure()) {
                    return $innerResult;
                }
                $ids = $innerResult->getValue();
            }

            // Append literal IDs not already present from the inner provider
            $innerValues = array_map(fn(SenderID $s) => $s->value, $ids);
            foreach ($literalIds as $lit) {
                if (!\in_array($lit, $innerValues, true)) {
                    $ids[] = new SenderID($lit);
                }
            }

            return \Result::success($getAll ? $ids : array_values(array_filter(
                $ids,
                fn(SenderID $s) => $s->acmaApproved === true,
            )));
        }

        // ── Existing intersection / CSV-parse logic ───────────────────────────
        // If the inner provider has no sender-ID API, SMS_SENDER_OPTIONS is
        // authoritative — parse it directly as the full list.
        if (!$this->inner->hasCapability(SmsCapability::GET_SENDER_IDS)) {
            $ids = parseSenderIdsFromCsv(SMS_SENDER_OPTIONS);
            return \Result::success($getAll ? $ids : array_values(array_filter(
                $ids,
                fn(SenderID $s) => $s->acmaApproved === true,
            )));
        }

        // Inner provider supports sender-ID discovery — fetch all IDs and
        // intersect with the admin-configured SMS_SENDER_OPTIONS allowlist.
        $innerResult = parent::getSenderIds(getAll: true);
        if ($innerResult->isFailure()) {
            return $innerResult;
        }
        $innerIds = $innerResult->getValue();

        if ($innerIds === []) {
            return \Result::failure(
                'No sender IDs registered upstream. '
                . 'Register one at your provider dashboard, or remove SMS_SENDER_OPTIONS to fall back to user mobile.'
            );
        }

        $filtered = array_values(array_filter(
            $innerIds,
            fn(SenderID $s) => \in_array($s->value, $configured, true),
        ));

        if ($filtered === []) {
            $available = implode(', ', array_map(fn(SenderID $s) => $s->value, $innerIds));
            $wanted = implode(', ', $configured);
            return \Result::failure(
                'SMS_SENDER_OPTIONS (' . $wanted . ') contains a Sender ID not registered with the SMS provider.'
                . ' Available: ' . $available . '.'
            );
        }

        return \Result::success($getAll ? $filtered : array_values(array_filter(
            $filtered,
            fn(SenderID $s) => $s->acmaApproved === true,
        )));
    }

    public function getBalance(): \Result
    {
        if (defined('SMS_BALANCE') && SMS_BALANCE !== '' && ctype_digit((string)SMS_BALANCE)) {
            return \Result::success((int)SMS_BALANCE);
        }
        return parent::getBalance();
    }

    public function getSegmentCost(): int
    {
        if (defined('SMS_SEGMENT_COST') && SMS_SEGMENT_COST !== '') {
            return (int) round((float) SMS_SEGMENT_COST * 100000);
        }
        return parent::getSegmentCost();
    }
}
