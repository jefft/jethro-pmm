<?php
abstract class Abstract_User_System
{
	protected $_permission_levels = Array();
	protected $_data_permission_levels = Array();


	/**
	 * Get details of the currently-authorised person
	 * (may be via a user account (user_system) or a member account (member_user_system).
	 * Call this method when you don't care how they've logged in.
	 * @param string $field	Particular field to return; null=return all fields
	 * @return mixed
	 */

	abstract public function getCurrentPerson();

	public function getCurrentUser($field='')
	{
		return NULL;
	}

	public function getCurrentMember($field='')
	{
		return NULL;
	}

	public function getCurrentRestrictions($type=NULL)
	{
		return Array();
	}

	protected function _loadPermissionLevels()
	{
		if (!empty($this->_permission_levels)) return;
		include 'permission_levels.php';
		$enabled_features = explode(',', strtoupper(ifdef('ENABLED_FEATURES', '')));
		foreach ($PERM_LEVELS as $i => $detail) {
			list($define_symbol, $desc, $feature_code) = $detail;
			define('PERM_'.$define_symbol, $i);
			if (empty($feature_code) || in_array($feature_code, $enabled_features)) {
				$this->_permission_levels[$i] = $desc;
			}
		}
	}

	public function getPermissionLevels()
	{
		$this->_loadPermissionLevels();
		return $this->_permission_levels;
	}

	/**
	 * Load the enabled data-visibility permissions (bit => label).
	 * @return void
	 */
	protected function _loadDataPermissionLevels()
	{
		if (!empty($this->_data_permission_levels)) return;
		require_once __DIR__.'/data_permissions.php';
		$enabled_features = explode(',', strtoupper(ifdef('ENABLED_FEATURES', '')));
		foreach (get_data_permission_levels() as $i => $detail) {
			list(, $desc, $feature_code) = $detail;
			if (empty($feature_code) || in_array($feature_code, $enabled_features)) {
				$this->_data_permission_levels[$i] = $desc;
			}
		}
	}

	/**
	 * Enabled data-visibility permissions, for the "Viewable Person Fields" widget.
	 * @return array<int, string> bit => label
	 */
	public function getDataPermissionLevels()
	{
		$this->_loadDataPermissionLevels();
		return $this->_data_permission_levels;
	}

	/**
	 * Whether the current subject may see a data domain.
	 * Member and public/CLI sessions override this in the concrete user system;
	 * staff sessions default to deny.
	 * @param int $permission
	 * @return bool
	 */
	public function haveDataPerm($permission)
	{
		return !empty($this->getCurrentMember());
	}

	public function handle2FAMobileTelChange($person, $old_mobile)
	{
		throw new \RuntimeException("Essential function not implemented");
		exit;
	}

}
