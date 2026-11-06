<?php
include_once JETHRO_ROOT.'/include/db_object.class.php';
include_once JETHRO_ROOT.'/db_objects/person.class.php';
include_once JETHRO_ROOT.'/db_objects/family.class.php';
class Member extends DB_Object
{

	function toString()
	{
		return $this->values['first_name'].' '.$this->values['last_name'];
	}

	protected static function _getFields()
	{
		$memberFields = Array('first_name', 'last_name', 'gender', 'age_bracketid', 'congregationid', 'email', 'mobile_tel', 'work_tel', 'familyid', 'family_name', 'address_street', 'address_suburb', 'address_state', 'address_postcode', 'home_tel');
		
		$p = new Person();
		foreach ($p->_getFields() as $k => $v) {
			if (in_array($k, $memberFields)) $res[$k] = $v;
		}
		$f = new Family();
		foreach ($f->_getFields() as $k => $v) {
			if (in_array($k, $memberFields)) $res[$k] = $v;
		}
		return $res;
	}

	static function getCongregations()
	{
		$SQL = 'SELECT c.id, c.name
				from congregation c
				join member m on m.congregationid = c.id
				group by c.id';
		$res = $GLOBALS['db']->queryAll($SQL, null, null, true, false);
		return $res;

	}

	/**
	 * Mask member-area contact values; street addresses retain only their
	 * leading number, a space and first ASCII letter, followed by an ellipsis.
	 * Empty values stay empty; phones reveal only their final two digits.
	 * @param string $field 'email', 'mobile_tel', 'home_tel' or 'address_street'
	 * @param string $value
	 * @return string
	 */
	public static function maskContact($field, $value)
	{
		$value = (string)$value;
		if ($value === '') return '';
		if ($field == 'address_street') {
			return (preg_match('/^[0-9]+ [a-zA-Z]/', $value, $prefix) ? $prefix[0] : '').'…';
		}
		if ($field == 'email') {
			$at = strpos($value, '@');
			if ($at === FALSE) return '•••';
			return substr($value, 0, 1).'•••@'.substr($value, $at + 1);
		}
		$digits = preg_replace('/\D/', '', $value);
		return strlen($digits) >= 2 ? '•••• ••• '.substr($digits, -2) : '••••';
	}

	/**
	 * Current viewer's live family ID, read through the member visibility view.
	 * Cache once per viewer per request; do not use the login-time family ID.
	 * @return int|null Null when no visible member record exists.
	 */
	public static function getCurrentFamilyID(): ?int
	{
		$id = (int)$GLOBALS['user_system']->getCurrentMember('id');
		if (!$id) return NULL;
		static $familyIDs = Array();
		if (!array_key_exists($id, $familyIDs)) {
			$familyID = $GLOBALS['db']->queryOne('SELECT familyid FROM member WHERE id = '.$id.' LIMIT 1');
			$familyIDs[$id] = $familyID ? (int)$familyID : NULL;
		}
		return $familyIDs[$id];
	}

	/**
	 * Mask only the viewer's family, never unrelated contacts already exposed
	 * by the member view's sharing rules. Missing family identity fails closed.
	 * @see docs/docs/developer/reference/password-reset.mdx
	 * @param string $name
	 * @return bool
	 */
	private function shouldMaskContact($name): bool
	{
		if (!in_array($name, Array('email', 'mobile_tel', 'home_tel', 'address_street'))) return FALSE;
		$us = $GLOBALS['user_system'] ?? NULL;
		if (!$us instanceof Member_User_System) return FALSE;
		$currentFamilyID = self::getCurrentFamilyID();
		$recordFamilyID = (int)array_get($this->values, 'familyid', 0);
		if ($currentFamilyID !== NULL && $recordFamilyID && $recordFamilyID !== $currentFamilyID) return FALSE;
		return in_array($name, Array('home_tel', 'address_street')) || !$us->hasVerifiedMemberEdit();
	}

	public function getFormattedValue($name, $value = NULL)
	{
		if ($this->shouldMaskContact($name)) {
			return self::maskContact($name, array_get($this->values, $name));
		}
		return parent::getFormattedValue($name, $value);
	}

	public function printFieldValue($name, $value = NULL)
	{
		if ($this->shouldMaskContact($name)) {
			// Plain text mask: no tel:/mailto link that could carry the real value.
			echo ents(self::maskContact($name, array_get($this->values, $name)));
			return;
		}
		parent::printFieldValue($name, $value);
	}

	public function printFieldInterface($name, $prefix = '')
	{
		if ($this->shouldMaskContact($name)) {
			echo ents(self::maskContact($name, array_get($this->values, $name)));
			return;
		}
		parent::printFieldInterface($name, $prefix);
	}

	static function getList($search=NULL, $congregationid=NULL)
	{
		$t = new Member();
		$order = 'family_name, member.familyid, ab.`rank` ASC, gender DESC';
		$conds = Array();
		if (!empty($search)) {
			$conds['first_name'] = $search.'%';
			$conds['last_name'] = $search.'%';
			$conds['family_name'] = $search.'%';
		}
		$query_bits = $t->getInstancesQueryComps($conds, 'OR', $order);
		if (!empty($congregationid)) {
			if (strlen(trim($query_bits['where']))) {
				$query_bits['where'] = "(\n".$query_bits['where'].")\n AND congregationid = ".(int)$congregationid;
			} else {
				$query_bits['where'] = "congregationid = ".(int)$congregationid;
			}
		}
		$query_bits['from'] .= "\n  LEFT JOIN family_photo fp ON fp.familyid = member.familyid";
		$query_bits['select'][] = 'IF(fp.familyid IS NULL, 0, 1) as has_family_photo';
		return $t->_getInstancesData($query_bits);
	}
	
	function getInstancesQueryComps($params, $logic, $order)
	{
		$res = parent::getInstancesQueryComps($params, $logic, $order);
		$res['select'][] = 'c.name as congregation, ab.label as age_bracket';
		$res['from'] = '('.$res['from'].')
						LEFT JOIN congregation c ON member.congregationid = c.id
						JOIN age_bracket ab on ab.id = member.age_bracketid ';
		return $res;
	}	

}