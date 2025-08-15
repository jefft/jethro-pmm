<?php

class checkin extends db_object
{
	/**
	 * Instances of this class form a record of somebody checking in at a given venue.
	 */
	// protected $_load_permission_level = 0; // want PERM_VIEWSERVICE | PERM_VIEWROSTER
	// protected $_save_permission_level = 0; // FUTURE: PERM_EDITSERVICE;

	protected static function _getFields()
	{
		$fields = [
			'venueid' => [
				'type' => 'reference',
				'references' => 'venue',
				'allow_empty' => false,
			],
			'timestamp' => [
				'type' => 'timestamp',
				'readonly' => true,
			],
			'name' => [
				'type' => 'text',
				'allow_empty' => false,
			],
			'tel' => [
				'type' => 'phone',
				'formats' => ifdef('MOBILE_TEL_FORMATS', '')."\n".ifdef('WORK_TEL_FORMATS', ''),
				'allow_empty' => true,
			],
			'email' => [
				'type' => 'email',
				'allow_empty' => true,
			],
			'pax' => [
				'type' => 'int',
				'width' => 6,
				'allow_empty' => false,
			],
		];

		return $fields;
	}

	public function validateFields()
	{
		if (!$this->getValue('tel') && !$this->getValue('email')) {
			trigger_error('Please enter either a phone number or email address');

			return false;
		}

		return parent::validateFields();
	}

	public function create()
	{
		if (parent::create()) {
			$venue = new Venue($this->getValue('venueid'));
			if ($venue->getValue('set_attendance')) {
				// Find a person that matches phone or email and set their attendance
				$fdata = $pdata = [];
				if ($this->getValue('tel')) {
					$fdata = $GLOBALS['system']->getDBObjectData('family', ['home_tel' => $this->getValue('tel')]);
					$pdata = $GLOBALS['system']->getDBObjectData('person', ['mobile_tel' => $this->getValue('tel'), 'work_tel' => $this->getValue('tel')]);
				}
				if ($this->getValue('email')) {
					$pdata += $GLOBALS['system']->getDBObjectData('person', ['email' => $this->getValue('email')]);
				}
				if ($fdata) {
					$pdata += $GLOBALS['system']->getDBObjectData('person', ['(familyid' => array_keys($fdata)]);
				}

				foreach ($pdata as $personid => $details) {
					if (empty($details['congregationid'])) {
						continue;
					}
					$cong = $GLOBALS['system']->getDBOBject('congregation', $details['congregationid']);
					if ($cong->canRecordAttendanceOn(date('Y-m-d'))) {
						$p = new Person($personid);
						$p->saveAttendance([date('Y-m-d') => 1], null, $this->id);
					}
				}
			}

			return true;
		} else {
			return false;
		}
	}
}
