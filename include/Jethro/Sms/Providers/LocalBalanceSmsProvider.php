<?php

namespace Jethro\Sms\Providers;

use Sms\DecoratingSmsProvider;
use Sms\SmsCache;
use Sms\SmsProvider;
use Sms\SmsSender;

/**
 * LocalBalanceSmsProvider — decorates any SmsProvider with a local balance.
 *
 * In multi-tenant deployments where the upstream SMS provider (e.g. 5cent)
 * is shared across tenants, the balance returned by the upstream API reflects
 * the shared pool, not the allocation for a particular tenant.
 *
 * ## SMS_BALANCE modes
 *
 * The SMS_BALANCE constant controls the source of the balance figure:
 *
 * | Value | Behaviour |
 * |-------|-----------|
 * | Unset / `''` | Delegate to inner provider (upstream API). No local override. |
 * | Numeric (e.g. `"100"`, `"0"`) | Hardcoded balance. Useful for testing, overrides, or as a send cap with `SMS_BALANCE_ENFORCED`. Does NOT decrement on send — the number is static. |
 * | `'database'` | Live balance from `sms_purchases` table: `SUM(quantity) - COUNT(sms)`. Decrements automatically as sends happen. |
 *
 * ## SMS_BALANCE_ENFORCED
 *
 * Blocks sends when the remaining balance (as returned by getBalance()) is
 * less than the recipient count.  Works with ALL SMS_BALANCE modes:
 *
 * - Numeric: acts as a per-send cap.  E.g. `SMS_BALANCE=0` + `SMS_BALANCE_ENFORCED=true`
 *   prevents ALL sending — useful as a kill-switch or for testing.
 * - `'database'`: acts as a running-balance gate.  Each send decrements the
 *   calculated balance, so the enforcement tracks real usage.
 *
 * The enforcement check runs BEFORE delegating to the inner provider,
 * so blocked sends never reach the upstream API and never create sms/smsdelivery rows.
 *
 * All other behaviour (send, getSenderIds, sender ID caching, test mode,
 * verbose logging) is delegated unchanged to the inner provider via
 * DecoratingSmsProvider.
 */

class LocalBalanceSmsProvider extends DecoratingSmsProvider
{
	/** Manual balance override. When set, skips the DB-based balance calculation. */
	private string $balance = '';

	public function __construct(SmsProvider $inner, string $balance = '')
	{
		parent::__construct($inner);
		$this->balance = $balance;
	}

	public function getDescription(): string
	{
		return $this->inner->getDescription() . ' (local balance)';
	}

	public function withCache(SmsCache $cache): static
	{
		return new static($this->inner->withCache($cache), $this->balance);
	}

	public function getBalance(): \Result
	{
		$balance = trim($this->balance);
		if ($balance !== '' && ctype_digit($balance)) {
			return \Result::success((int) $balance);
		}
		if ($balance === 'database') {
			try {
				$purchased = (int) $GLOBALS['db']->queryOne('SELECT COALESCE(SUM(quantity), 0) FROM sms_purchases');
				$sent = (int) $GLOBALS['db']->queryOne('SELECT COUNT(*) FROM sms');
				return \Result::success($purchased - $sent);
			} catch (\Exception $e) {
				throw new \RuntimeException(
					'Could not read SMS balance from sms_purchases table. '
					. 'Create the table manually:\n'
					. "CREATE TABLE IF NOT EXISTS `sms_purchases` (\n"
					. "\t`id` INT NOT NULL AUTO_INCREMENT,\n"
					. "\t`purchasedate` DATE NOT NULL COMMENT 'Date of the purchase/top-up',\n"
					. "\t`quantity` INT NOT NULL COMMENT 'Number of SMS credits purchased',\n"
					. "\t`cost` DECIMAL(10, 2) NOT NULL COMMENT 'Cost in currency',\n"
					. "\tPRIMARY KEY (`id`),\n"
					. "\tINDEX `purchasedate` (`purchasedate`)\n"
					. ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;',
					0,
					$e,
				);
			}
		}
		return parent::getBalance();
	}

	/** @return \Result<\Sms\SmsDeliveryBatch, string> */
	public function send(array $entries, SmsSender $sender, ?int $sendAt = null, bool $preview = false): \Result
{
		if (!$preview && !$this->hasEnoughBalance($entries)) {
			return \Result::failure(sprintf(
				'Insufficient balance to send to %d recipients (%d segments needed)',
				$this->totalRecipients($entries),
				$this->totalSegments($entries),
			));
		}

		return parent::send($entries, $sender, $sendAt, $preview);
	}
}
