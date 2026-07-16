<?php

namespace Jethro\Sms;

use Sms\PhoneNumber;
use Sms\SmsRecipient;


/**
 * A person (sender or recipient) with a phone number and Jethro person ID.
 */
final readonly class JethroSmsRecipient implements SmsRecipient
{
	public function __construct(
		public int              $personId,
		public \Sms\PhoneNumber  $number,
	)
	{}

    public function __toString(): string
    {
        return $this->number->value;
    }

	public function getPhoneNumber(): PhoneNumber
	{
		return $this->number;
	}
}