<?php
/**
 * SMS send — one row per send operation.
 *
 * Table: `sms` (created by upgrades/2026-upgrade-to-2.40.sql)
 */
class Sms extends DB_Object
{
	protected static function _getFields()
	{
		return [
			'body' => [
				'type' => 'text',
				'label' => 'Message',
				'allow_empty' => false,
			],
			'sender' => [
				'type' => 'reference',
				'label' => 'Sent by',
				'references' => 'staff_member',
				'allow_empty' => true,
			],
			'created' => [
				'type' => 'datetime',
				'label' => 'Created',
				'default' => 'CURRENT_TIMESTAMP',
			],
			'scheduled_send_at' => [
				'type' => 'datetime',
				'label' => 'Scheduled send',
				'allow_empty' => true,
			],
		'wire_sender' => [
			'type' => 'text',
			'label' => 'Wire sender',
			'allow_empty' => true,
		],
		];
	}

	public function getInstancesQueryComps($params, $logic, $order)
	{
		$res = parent::getInstancesQueryComps($params, $logic, $order);
		$res['from'] = 'sms';
		// Sender name
		$res['select'][] = 'COALESCE(_person.first_name, \'\') AS sender_fn';
		$res['select'][] = 'COALESCE(_person.last_name, \'\') AS sender_ln';
		$res['from'] .= ' LEFT JOIN _person ON _person.id = sms.sender';
		// Recipient count and status aggregates
		$res['select'][] = 'COUNT(smsdelivery.id) AS recipient_count';
		$res['select'][] = 'SUM(smsdelivery.status IN (\'queued\',\'sent\',\'delivered\',\'test-message\')) AS delivered_count';
		$res['select'][] = 'SUM(smsdelivery.status IN (\'failed\',\'cancelled\')) AS failed_count';
		$res['select'][] = 'SUM(smsdelivery.status = \'scheduled\') AS scheduled_count';
		// Cost: sum of per-delivery cost for successfully delivered messages, keyed by provider.
		// Costs read from each provider's SEGMENT_COST_MILLICENTS class constant.
		$res['select'][] = self::costSqlExpr('smsdelivery');
		$res['from'] .= ' JOIN smsdelivery ON smsdelivery.sms_id = sms.id';
		$res['group_by'] = 'sms.id';
		return $res;
	}

	public function toString()
	{
		$body = (string) $this->getValue('body');
		return mb_strlen($body) > 60 ? mb_substr($body, 0, 57).'...' : $body;
	}

	/**
	 * SQL expression for total cost of accepted SMS deliveries, grouped by sms.id.
	 *
	 * Counts deliveries where smsdelivery.status is in {@see \Sms\SmsStatus::ACCEPTED_STATUSES}
	 * — the canonical set of statuses where the gateway accepted the message for delivery.
	 * Cost per delivery is read from each provider's SEGMENT_COST_MILLICENTS class constant.
	 *
	 * @param string $tableAlias Table name or alias for smsdelivery (e.g. 'smsdelivery' or 'rd')
	 * @return string SQL expression aliased AS cost
	 */
	public static function costSqlExpr(string $tableAlias = 'smsdelivery'): string
	{
		$v5 = \Sms\Providers\FiveCentSmsV5Provider::SEGMENT_COST_MILLICENTS / 100000;
		$cellcast = \Sms\Providers\CellcastSmsProvider::SEGMENT_COST_MILLICENTS / 100000;
		$broadcast = \Sms\Providers\SmsBroadcastSmsProvider::SEGMENT_COST_MILLICENTS / 100000;
		$v4 = \Sms\Providers\TemplateSmsProvider::SEGMENT_COST_MILLICENTS / 100000;
		$accepted = "'" . implode("','", \Sms\SmsStatus::ACCEPTED_STATUSES) . "'";

		return "COALESCE(SUM(CASE WHEN {$tableAlias}.status IN ({$accepted}) THEN
			CASE {$tableAlias}.provider
				WHEN '5centsmsv5' THEN {$v5}
				WHEN '5csmsv5'   THEN {$v5}
				WHEN 'cellcast' THEN {$cellcast}
				WHEN 'smsbroadcast' THEN {$broadcast}
				WHEN '5csmsv4' THEN {$v4}
				ELSE 0
			END
		ELSE 0 END), 0) AS cost";
	}
}
