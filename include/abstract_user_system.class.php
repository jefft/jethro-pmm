<?php

abstract class Abstract_User_System
{
	protected $_permission_levels = [];

	/**
	 * Get details of the currently-authorised person
	 * (may be via a user account (user_system) or a member account (member_user_system).
	 * Call this method when you don't care how they've logged in.
	 */
	abstract public function getCurrentPerson();

	public function getCurrentUser($field = '')
	{
		return null;
	}

	public function getCurrentMember($field = '')
	{
		return null;
	}

	public function getCurrentRestrictions($type = null)
	{
		return [];
	}

	protected function _loadPermissionLevels()
	{
		if (!empty($this->_permission_levels)) {
			return;
		}
		include 'permission_levels.php';
		$enabled_features = explode(',', strtoupper(ifdef('ENABLED_FEATURES', '')));
		foreach ($PERM_LEVELS as $i => $detail) {
			[$define_symbol, $desc, $feature_code] = $detail;
			define('PERM_'.$define_symbol, $i);
			if (empty($feature_code) || in_array($feature_code, $enabled_features, true)) {
				$this->_permission_levels[$i] = $desc;
			}
		}
	}

	public function getPermissionLevels()
	{
		$this->_loadPermissionLevels();

		return $this->_permission_levels;
	}

	public function handle2FAMobileTelChange($person, $old_mobile)
	{
		throw new RuntimeException('Essential function not implemented');
		exit;
	}
}
