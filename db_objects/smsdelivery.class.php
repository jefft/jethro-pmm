<?php
/**
 * SMS delivery — one row per recipient within a send.
 *
 * Table: `smsdelivery` (created by upgrades/2026-upgrade-to-2.40.sql)
 *
 * Not to be confused with \Sms\SmsDelivery (the pure-layer value object in jethro-sms/src/sms.php).
 */
class SmsDelivery extends DB_Object
{
	protected static function _getFields()
	{
		return [
			'sms_id' => [
				'type' => 'reference',
				'label' => 'Send',
				'references' => 'sms',
				'allow_empty' => false,
			],
			'personid' => [
				'type' => 'reference',
				'label' => 'Recipient',
				'references' => 'person',
				'allow_empty' => true,
			],
			'remote_id' => [
				'type' => 'text',
				'label' => 'Provider message ID',
				'allow_empty' => true,
			],
			'raw_response' => [
				'type' => 'text',
				'label' => 'Raw provider response',
				'allow_empty' => true,
			],
			'body' => [
				'type' => 'text',
				'label' => 'Expanded message',
				'allow_empty' => true,
			],
			'delivered_at' => [
				'type' => 'datetime',
				'label' => 'Delivered at',
				'allow_empty' => true,
			],
			'status' => [
				'type' => 'text',
				'label' => 'Delivery status',
				'default' => 'sending',
            ],
            'provider' => [
                'type' => 'text',
                'label' => 'Provider key',
                'allow_empty' => true,
            ],
    ];
    }
	public function getInitSQL($table_name = NULL)
	{
		return "
			CREATE TABLE `smsdelivery` (
				`id` INT NOT NULL AUTO_INCREMENT,
				`sms_id` INT NOT NULL,
				`personid` INT NULL DEFAULT NULL,
				`remote_id` VARCHAR(255) NULL DEFAULT NULL,
				`raw_response` TEXT NULL DEFAULT NULL,
				`body` TEXT NULL DEFAULT NULL,
				`provider` ENUM('5centsmsv5','cellcast','smsbroadcast','5csmsv4') NULL DEFAULT NULL,
				`delivered_at` DATETIME NULL DEFAULT NULL,
				`status` ENUM('queued','sent','delivered','failed','in-progress','scheduled','cancelled','sending','test-message','unknown') NOT NULL DEFAULT 'sending',
				PRIMARY KEY (`id`),
				INDEX `sms_id` (`sms_id`),
				INDEX `personid` (`personid`),
				INDEX `remote_id` (`remote_id`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
	}

	public function getForeignKeys()
	{
		return Array(
			'sms_id' => '`sms`(`id`) ON DELETE CASCADE',
			'personid' => '`_person`(`id`) ON DELETE CASCADE',
		);
	}
	public function getInstancesQueryComps($params, $logic, $order)
	{
		$res = parent::getInstancesQueryComps($params, $logic, $order);
		$res['from'] = 'smsdelivery';
		// Drop columns never used in views: raw_response (TEXT), remote_id, delivered_at
		$res['select'] = array_values(array_filter(
			$res['select'],
			fn(string $col): bool => !str_ends_with($col, '.raw_response')
				&& !str_ends_with($col, '.remote_id')
				&& !str_ends_with($col, '.delivered_at')
		));
		// Recipient name
		$res['select'][] = "CONCAT(COALESCE(_person.first_name, ''), ' ', COALESCE(_person.last_name, '')) AS recipient_name";
		$res['from'] .= ' LEFT JOIN _person ON _person.id = smsdelivery.personid';
		return $res;
	}

	public function toString()
	{
		return 'SMS delivery #'.$this->id.' ('.((string) $this->getValue('status')).')';
	}
}
