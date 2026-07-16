<?php

namespace Jethro\Sms\Providers;

use Jethro\Sms\JethroSmsDelivery;
use Jethro\Sms\JethroSmsDeliveryBatch;
use Jethro\Sms\JethroSmsRecipient;
use Sms\ContactPhoneNumber;
use Sms\DecoratingSmsProvider;
use Sms\PhoneNumber;
use Sms\RegistrationStep;
use Sms\SmsDelivery;
use Sms\SmsDeliveryBatch;
use Sms\SmsProvider;
use Sms\SmsSender;
use function Jethro\Sms\getAvailableTokens;
use function Jethro\Sms\insertSms;

/**
 * SmsProvider decorator that logs sends to the sms/smsdelivery tables
 * and caches updateDelivery()/cancel() results in smsdelivery.
 *
 * On send(), inserts a sms row and per-recipient smsdelivery rows,
 * then wraps SmsDelivery objects in JethroSmsDelivery with person IDs
 * and database IDs for downstream callers.
 *
 * On updateDelivery(), caches the final status in smsdelivery.raw_response.
 * Subsequent calls for the same delivery skip the upstream API.
 */
final class DbLoggingSmsProvider extends \Sms\DecoratingSmsProvider
{
		public function __construct(\Sms\SmsProvider $inner)
	{
		parent::__construct($inner);
	}

	public function send(
		array $entries,
		\Sms\SmsSender $sender,
		?int $sendAt = null,
		bool $preview = false,
	): \Result
	{
		// Pre-flight: if any entry's message contains known %tokens%,
		// every recipient in that entry must be a JethroSmsRecipient so
		// we can resolve person data.
		foreach ($entries as $entry) {
			if (\Sms\messageHasTokens($entry['message'], getAvailableTokens())) {
				foreach ($entry['recipients'] as $r) {
					if (!$r instanceof JethroSmsRecipient) {
						return \Result::failure(
							'Token expansion requires JethroSmsRecipient recipients. '
							. 'Got ' . get_debug_type($r) . '. '
							. 'Use person IDs instead of raw phone numbers, or avoid %tokens% in the message.'
						);
					}
				}
			}
		}

		$result = parent::send($entries, $sender, $sendAt, $preview);
		if ($result->isFailure()) {
			return $result;
		}

		/** @var \Sms\SmsDeliveryBatch $innerBatch */
		$innerBatch = $result->getValue();
		$deliveries = $innerBatch->deliveries;

		// Flatten all entries into one recipient list. Deliveries arrive in
		// entry order, so positional pairing (improvement 39) matches correctly.
		$allRecipients = [];
		$allPersonIds  = [];
		foreach ($entries as $entry) {
			foreach ($entry['recipients'] as $r) {
				$allRecipients[] = $r;
				$allPersonIds[] = ($r instanceof JethroSmsRecipient) ? $r->personId : null;
			}
		}

		if ($preview) {
			$wrapped = [];
			foreach ($deliveries as $i => $d) {
				$pid = $allPersonIds[$i] ?? null;
				$wrapped[] = new JethroSmsDelivery(
					inner: $d,
					recipientPersonId: $pid,
					databaseId: null,
				);
			}
			return \Result::success(new \Sms\SmsDeliveryBatch(null, $wrapped));
		}

		// One sms row for the entire batch.
		$senderId = $GLOBALS['user_system']->getCurrentUser()['id'] ?? null;
		$insertResult = insertSms(
			$entries[0]['template'] ?? $entries[0]['message'] ?? '',
			$senderId,
			$deliveries,
			$allRecipients,
			$sendAt,
			$this->getKey(),
			(string) $sender,
		);
		$personToDeliveryId = $insertResult['deliveries'];

		$wrapped = [];
		foreach ($deliveries as $i => $d) {
			$pid = $allPersonIds[$i] ?? null;
			$wrapped[] = new JethroSmsDelivery(
				inner: $d,
				recipientPersonId: $pid,
				databaseId: $pid !== null ? ($personToDeliveryId[(string)$pid] ?? null) : null,
			);
		}
		$smsId = $insertResult['smsId'] ?? 0;
		if ($smsId > 0) {
			return \Result::success(new JethroSmsDeliveryBatch((string)$smsId, $wrapped, $senderId === null ? null : (int)$senderId));
		}
		return \Result::success(new \Sms\SmsDeliveryBatch(null, $wrapped));
	}

	public function updateDelivery(\Sms\SmsDelivery $delivery): \Result
	{
		$remoteId = $delivery->remoteId();
		if ($remoteId === null) {
			return \Result::failure('No remote ID on delivery');
		}

		$db = $GLOBALS['db'];

		// Check if already final — return early without an upstream round-trip.
		try {
			$row = $db->queryRow(
				'SELECT status, delivered_at FROM smsdelivery WHERE remote_id = ' . $db->quote($remoteId)
			);

			if ($row) {
				$status = \Sms\SmsStatus::fromMySql((string) ($row['status'] ?? ''));
				if ($status->isFinal()) {
					$delTs = !empty($row['delivered_at']) ? strtotime($row['delivered_at']) : null;
					return \Result::success($delivery->with(
						status: $status,
						deliveryTimestamp: $delTs ?: null,
					));
				}
			}
		} catch (\PDOException $e) {
			// Table doesn't exist — skip cache, fetch from upstream
		}

		// Fetch from upstream
		$result = parent::updateDelivery($delivery);
		if ($result->isFailure()) {
			return $result;
		}

		/** @var \Sms\SmsDelivery $updated */
		$updated = $result->getValue();

		$status = $updated->status()->toMySql();
		$rawResponse = $updated->rawResponse();
		$deliveredAt = $updated->deliveryTimestamp()
			? date('Y-m-d H:i:s', $updated->deliveryTimestamp())
			: null;

		try {
			$db->exec(
				'UPDATE smsdelivery SET raw_response = ' . $db->quote($rawResponse)
				. ', status = ' . $db->quote($status)
				. ($deliveredAt !== null ? ', delivered_at = ' . $db->quote($deliveredAt) : '')
				. ' WHERE remote_id = ' . $db->quote($remoteId)
			);
		} catch (\PDOException $e) {
			// Table doesn't exist — skip cache write
		}

		return \Result::success($updated);
	}

	/**
	 * Cancel the deliveries in the batch, then persist updated statuses to smsdelivery.
	 *
	 * Delegates to the inner provider's cancel(), then for each returned delivery
	 * with a non-null remoteId, updates smsdelivery.raw_response and smsdelivery.status.
	 * Deliveries with null remoteId are skipped (no DB row to update).
	 * PDOException on any individual update is silently swallowed (same pattern as
	 * updateDelivery — the table may not exist in all environments).
	 *
	 * @return \Result<\Sms\SmsDeliveryBatch, string>
	 */
	public function listRecentDeliveries(?int $since = null): \Result
	{
		$result = parent::listRecentDeliveries($since);
		if ($result->isSuccess()) {
			$this->persistStatuses($result->getValue());
		}
		return $result;
	}

	/**
	 * Persist delivery status changes to smsdelivery so that page refreshes
	 * show the correct status without waiting for AJAX polling.
	 *
	 * For each delivery with a non-null remoteId, updates smsdelivery.status,
	 * raw_response, and delivered_at.  PDOException on any individual update
	 * is silently swallowed (table may not exist in all environments).
	 *
	 * @param \Sms\SmsDelivery[] $deliveries
	 */
	private function persistStatuses(array $deliveries): void
	{
		$db = $GLOBALS['db'];
		foreach ($deliveries as $delivery) {
			$remoteId = $delivery->remoteId();
			if ($remoteId === null) {
				continue;
			}
			try {
				$status = $delivery->status()->toMySql();
				$rawResponse = $delivery->rawResponse();
				$deliveredAt = $delivery->deliveryTimestamp()
					? date('Y-m-d H:i:s', $delivery->deliveryTimestamp())
					: null;

				$db->exec(
					'UPDATE smsdelivery SET raw_response = ' . $db->quote($rawResponse)
					. ', status = ' . $db->quote($status)
					. ($deliveredAt !== null ? ', delivered_at = ' . $db->quote($deliveredAt) : '')
					. ' WHERE remote_id = ' . $db->quote($remoteId)
				);
			} catch (\PDOException $e) {
				// Table doesn't exist — skip cache write
			}
		}
	}

	public function cancel(\Sms\SmsDeliveryBatch $batch): \Result
	{
		$result = parent::cancel($batch);
		if ($result->isFailure()) {
			return $result;
		}

		/** @var \Sms\SmsDeliveryBatch $cancelledBatch */
		$cancelledBatch = $result->getValue();
		$this->persistStatuses($cancelledBatch->deliveries);
		return \Result::success($cancelledBatch);
	}

	public function verifySenderNumber(\Sms\PhoneNumber $number): \Result
	{
		// 1. DB check — persists across sessions
		$db = $GLOBALS['db'];
		try {
			$exists = (bool) $db->queryOne(
				'SELECT 1 FROM sms_registered_sender WHERE phone = ' . $db->quote($number->value)
			);
			if ($exists) {
				return \Result::success(true);
			}
		} catch (\PDOException $e) {
			// Table doesn't exist yet — fall through to inner
		}

		// 2. Delegate to inner provider (session cache or upstream)
		return $this->inner->verifySenderNumber($number);
	}

	public function registerSenderNumber(?\Sms\ContactPhoneNumber $contact = null, ?array $validationParams = null): \Result
	{
		$result = $this->inner->registerSenderNumber($contact, $validationParams);
		if ($result->isSuccess()) {
			$step = $result->getValue();
			// Persist to DB when registration is complete (Phase 2 or immediate)
			if ($step instanceof \Sms\RegistrationStep && $step->registered && $step->number !== null) {
				$db = $GLOBALS['db'];
				try {
					$db->exec(
						'INSERT IGNORE INTO sms_registered_sender (phone) VALUES ('
						. $db->quote($step->number) . ')'
					);
				} catch (\PDOException $e) {
					// Table doesn't exist — session cache still applies
				}
			}
		}
		return $result;
	}
}
