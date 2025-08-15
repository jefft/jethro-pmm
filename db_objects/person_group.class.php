<?php
include_once 'include/db_object.class.php';
class Person_Group extends db_object
{
	protected $_save_permission_level = PERM_EDITGROUP;

	protected static function _getFields()
	{
		$fields = [
			'name' => [
				'type' => 'text',
				'width' => 40,
				'maxlength' => 128,
				'allow_empty' => false,
				'initial_cap' => true,
			],
			'categoryid' => [
				'type' => 'reference',
				'references' => 'person_group_category',
				'label' => 'Category',
				'allow_empty' => true,
				'order_by' => 'name',
			],
			'is_archived' => [
				'type' => 'select',
				'options' => ['Active', 'Archived'],
				'label' => 'Status',
				'default' => 0,
			],
			'owner' => [
				'type' => 'reference',
				'references' => 'staff_member',
				'label' => 'Visibility',
				'allow_empty' => true,
				'default' => null,
			],
			'show_add_family' => [
				'type' => 'select',
				'options' => [
					'yes' => 'Yes',
					'no' => 'No',
				],
				'default' => 'no',
				'label' => 'Show on add-family page?',
				'note' => 'Should this group be shown as an option when <a href="?view=families__add">adding a new family</a>?',
				'divider_before' => true,
				'heading_before' => 'Advanced Options:',
			],
			'share_member_details' => [
				'type' => 'select',
				'options' => ['No', 'Yes'],
				'default' => 0,
				'note' => 'Should members of this group be able to see each other\'s details in <a href="'.BASE_PATH.'/members">member portal</a>?',
				'label' => 'Share member details?',
			],
		];
		// Check if attendance is enabled
		$enabled = explode(',', ifdef('ENABLED_FEATURES', 'ATTENDANCE'));
		if (in_array('ATTENDANCE', $enabled, true)) {
			$fields['attendance_recording_days'] = [
				'type' => 'bitmask',
				'options' => [
					1 => 'Sunday',
					2 => 'Monday',
					4 => 'Tuesday',
					8 => 'Wednesday',
					16 => 'Thursday',
					32 => 'Friday',
					64 => 'Saturday',
				],
				'default' => 0,
				'label' => 'Attendance Recording Days',
				'cols' => 4,
				'note' => 'If you want to record attendance at this group, select the applicable weekdays. ',
				'show_unselected' => false,
			];
		}

		return $fields;
	}

	function __construct($id = null)
	{
		parent::__construct($id);

		if (!$this->id) {
			$this->fields['is_archived']['editable'] = false;
		}

		if (!empty($_REQUEST['categoryid'])) {
			$_SESSION['group_categoryid'] = $_REQUEST['categoryid'];
		} elseif (empty($this->id) && !empty($_SESSION['group_categoryid'])) {
			$this->values['categoryid'] = array_get($_SESSION, 'group_categoryid');
		}
	}

	function getInitSQL($table_name = null)
	{
		// Need to create the group-membership table as well as the group table
		return [
			parent::getInitSQL('_person_group'),

			'CREATE TABLE person_group_membership_status (
					id INT AUTO_INCREMENT PRIMARY KEY,
					label VARCHAR(255) NOT NULL,
					`rank` int not null default 0,
					is_default TINYINT(1) UNSIGNED DEFAULT 0,
					CONSTRAINT UNIQUE INDEX (label)
				) ENGINE=InnoDB;',

			"INSERT INTO person_group_membership_status (label, is_default)
				VALUES ('Member', 1);",

			"CREATE TABLE `person_group_membership` (
				  `personid` int(11) NOT NULL default '0',
				  `groupid` int(11) NOT NULL default '0',
				  `membership_status` int NOT NULL,
				  `created` timestamp NOT NULL default CURRENT_TIMESTAMP,
				  PRIMARY KEY  (`personid`,`groupid`),
				  INDEX personid (personid),
				  INDEX groupid (groupid),
				  CONSTRAINT `membership_status_fk` FOREIGN KEY (membership_status) REFERENCES person_group_membership_status (id) ON DELETE RESTRICT,
				  CONSTRAINT `pgm_personid` FOREIGN KEY (personid) REFERENCES _person(id) ON DELETE CASCADE
				) ENGINE=InnoDB",
		];
	}

	public function getForeignKeys()
	{
		return [
			'_person_group.categoryid' => '`person_group_category` (`id`) ON DELETE SET NULL',
		];
	}

	/**
	 * @return The SQL to run to create any database views used by this class
	 */
	public function getViewSQL()
	{
		return 'CREATE VIEW person_group AS
			SELECT * from _person_group g
			WHERE
			  getCurrentUserID() IS NOT NULL
			  AND
			  ((g.owner IS NULL) OR (g.owner = getCurrentUserID()))
			  AND
			  (NOT EXISTS (SELECT * FROM account_group_restriction gr WHERE gr.personid  = getCurrentUserID())
			  OR g.id IN (SELECT groupid FROM account_group_restriction gr WHERE gr.personid = getCurrentUserID()))';
	}

	function toString()
	{
		return $this->values['name'];
	}

	function getMembers($params = [], $order_by = null)
	{
		if ($order_by == null) {
			$order_by = 'pgms.`rank`, person.last_name, person.first_name';
		} else {
			// replace 'status' with membership status rank.
			// but retain 'person.status' unchanged.
			$order_by = preg_replace('/(^|[^.])status($| |,)/', '\\1pgms.`rank`\\2', $order_by);
		}
		$person = new Person();
		$comps = $person->getInstancesQueryComps($params, 'AND', $order_by);
		$comps['from'] .= '
			JOIN person_group_membership pgm ON pgm.personid = person.id
			LEFT JOIN person_group_membership_status pgms ON pgms.id = pgm.membership_status
			';
		if (strlen($comps['where'])) {
			$comps['where'] .= ' AND ';
		}
		$comps['where'] .= ' pgm.groupid = '.(int) $this->id;
		$comps['select'][] = 'pgm.membership_status as membership_status_id';
		$comps['select'][] = 'pgms.label as membership_status';
		$comps['select'][] = 'pgm.created as joined_group';

		return $person->_getInstancesData($comps);
	}

	function addMember($personid, $membership_status = null, $overwrite_existing = false)
	{
		if (!$GLOBALS['user_system']->havePerm(PERM_EDITGROUP)) {
			trigger_error('You do not have permission to add group members');

			return false;
		}
		[$statuses, $default_status] = self::getMembershipStatusOptionsAndDefault();
		if ($membership_status === null) {
			$membership_status = $default_status;
		}
		if (!isset($statuses[$membership_status])) {
			throw new RuntimeException("Invalid membership status value '$membership_status'");

			return false;
		}

		$new_member = $GLOBALS['system']->getDBObject('person', $personid);
		if ($new_member && $new_member->id) {
			$db = &$GLOBALS['db'];
			if ($overwrite_existing) {
				$sql = 'INSERT ';
			} else {
				$sql = 'INSERT IGNORE ';
			}
			$sql .= 'INTO person_group_membership (groupid, personid, membership_status)
					VALUES ('.$db->quote((int) $this->id).', '.$db->quote((int) $personid).', '.$db->quote($membership_status).')';
			if ($overwrite_existing) {
				$sql .= ' ON DUPLICATE KEY UPDATE membership_status=VALUES(membership_status)';
			}
			$res = $db->query($sql);

			return true;
		}

		return false;
	}

	function removeMember($personid)
	{
		if (!$GLOBALS['user_system']->havePerm(PERM_EDITGROUP)) {
			trigger_error('You do not have permission to remove group members');

			return false;
		}
		$new_member = $GLOBALS['system']->getDBObject('person', $personid);
		if ($new_member->id) {
			$db = &$GLOBALS['db'];
			$sql = 'DELETE FROM person_group_membership
					WHERE groupid = '.$db->quote((int) $this->id).'
						AND personid = '.$db->quote((int) $personid);
			$res = $db->query($sql);

			return true;
		}

		return false;
	}

	function removeMembers($personids)
	{
		if (!$GLOBALS['user_system']->havePerm(PERM_EDITGROUP)) {
			trigger_error('You do not have permission to remove group members');

			return false;
		}
		// Do a query first to make sure it's only persons we have access to.
		$members = $GLOBALS['system']->getDBObjectData('person', ['id' => $personids]);
		if ($members) {
			$db = &$GLOBALS['db'];
			$SQL = 'DELETE FROM person_group_membership
					WHERE groupid = '.$db->quote((int) $this->id).'
						AND personid IN ('.implode(',', array_map([$db, 'quote'], array_keys($members))).')';
			$res = $db->query($SQL);

			return true;
		}

		return false;
	}

	static function getGroups($personid, $includeArchived = false, $whichShareMemberDetails = null)
	{
		$db = &$GLOBALS['db'];
		$sql = 'SELECT g.id, g.name, gm.created, g.is_archived, g.categoryid, pgms.label as membership_status
				FROM person_group_membership gm
				JOIN person_group g ON gm.groupid = g.id
				LEFT JOIN person_group_membership_status pgms ON pgms.id = gm.membership_status
				WHERE gm.personid = '.$db->quote((int) $personid).'
				'.($includeArchived ? '' : ' AND NOT g.is_archived').'
				'.(null === $whichShareMemberDetails ? '' : ' AND g.share_member_details = '.(int) $whichShareMemberDetails).'
				ORDER BY g.is_archived ASC, g.name';
		$res = $db->queryAll($sql, null, null, true);

		return $res;
	}

	function printSummary()
	{
		?>
		<table class="standard">
			<tr>
				<th>Group Name</th>
				<td><?php echo $this->getValue('name'); ?></td>
			</tr>
			<tr>
				<th>Members</th>
				<td>
					<ul>
					<?php
					foreach ($this->getMembers() as $id => $details) {
						?>
						<li><a href="?view=persons&personid=<?php echo $id; ?>"><?php echo $details['first_name'].' '.$details['last_name']; ?></a></li>
						<?php
					}
		?>
					</ul>
				</td>
			</tr>
		</table>
		<?php
	}

	function getInstancesQueryComps($params, $logic, $order)
	{
		$res = parent::getInstancesQueryComps($params, $logic, $order);
		$res['from'] .= "\n LEFT JOIN person_group_category pgc ON person_group.categoryid = pgc.id ";
		$res['select'][] = 'pgc.name as category';

		// TODO: we don't need to join this all the time, only for the groups list all page
		$res['from'] .= "\n LEFT JOIN person_group_membership gm ON gm.groupid = person_group.id ";
		$res['from'] .= "\n LEFT JOIN person aperson ON gm.personid = aperson.id AND aperson.status NOT IN (SELECT id FROM person_status WHERE is_archived)";
		$res['select'][] = 'COUNT(aperson.id) as member_count';
		$res['group_by'] = 'person_group.id';

		return $res;
	}

	function delete()
	{
		$SQL = 'SELECT COUNT(*) FROM account_group_restriction WHERE groupid = '.(int) $this->id;
		$res = $GLOBALS['db']->queryOne($SQL);
		if ($res > 0) {
			add_message('This group cannot be deleted because it is used to restrict one or more user accounts', 'error');

			return false;
		}

		$roles = $GLOBALS['system']->getDBObjectData('roster_role', ['volunteer_group' => $this->id]);
		if ($roles) {
			$role = reset($roles);
			add_message("This group cannot be deleted because it used by the roster role '".$role['title']."'", 'error');

			return false;
		}

		$r = parent::delete();
		$db = &$GLOBALS['db'];
		$sql = 'DELETE FROM person_group_membership WHERE groupid = '.$db->quote($this->id);
		$res = $db->query($sql);

		return $r;
	}

	function printFieldValue($fieldname, $value = null)
	{
		if (null === $value) {
			$value = $this->values[$fieldname];
		}
		switch ($fieldname) {
			case 'attendance_recording_days':
				if ($value == 0) {
					echo _('No');

					return;
				}
				if ($value == 127) {
					echo _('Yes, any day');

					return;
				}

				return parent::printFieldValue($fieldname, $value);
				break;
			case 'owner':
				echo _(($value === null) ? 'Everyone' : 'Only me');
				break;

			case 'categoryid':
				if ($value == 0) {
					echo '<i>(Uncategorised)</i>';

					return;
				}
				// deliberate fall through
				// no break
			default:
				return parent::printFieldValue($fieldname, $value);
		}
	}

	function printFieldInterface($fieldname, $prefix = '')
	{
		switch ($fieldname) {
			case 'categoryid':
				$GLOBALS['system']->includeDBClass('person_group_category');
				Person_Group_Category::printChooser($prefix.$fieldname, $this->getValue('categoryid'));
				echo ' &nbsp; &nbsp;<small><a href="'.build_url(['view' => 'groups__manage_categories']).'">Manage categories</a></small>';
				break;
			case 'owner':
				$visibilityParams = [
					'type' => 'select',
					'options' => ['Visible to everyone', 'Visible only to me'],
				];
				print_widget('is_private', $visibilityParams, $this->getValue('owner') !== null);
				break;
			default:
				return parent::printFieldInterface($fieldname, $prefix);
		}
	}

	public function processFieldInterface($name, $prefix = '')
	{
		switch ($name) {
			case 'owner':
				$this->setValue('owner', empty($_REQUEST['is_private']) ? null : $GLOBALS['user_system']->getCurrentUser('id'));
				break;
			default:
				return parent::processFieldInterface($name, $prefix);
		}
	}

	public static function getMembershipStatusOptionsAndDefault($with_usages = false)
	{
		if ($with_usages) {
			$sql = 'SELECT s.*, COUNT(pgm.personid) as usages
					FROM person_group_membership_status s
					LEFT JOIN person_group_membership pgm ON pgm.membership_status = s.id
					ORDER BY s.`rank`';
		} else {
			$sql = 'SELECT s.*
					FROM person_group_membership_status s
					ORDER BY s.`rank`';
		}
		$res = $GLOBALS['db']->queryAll($sql, null, null, true);
		$options = [];
		$default = null;
		$usages = [];
		foreach ($res as $id => $detail) {
			$options[$id] = $detail['label'];
			if ($with_usages) {
				$usages[$id] = $detail['usages'];
			}
			if ($detail['is_default']) {
				$default = $id;
			}
		}
		if (empty($default)) {
			$default = key($options);
		}

		return [$options, $default, $usages];
	}

	public static function printMembershipStatusChooser($name, $value = null, $multi = false)
	{
		[$options, $default] = self::getMembershipStatusOptionsAndDefault();
		$params = [
			'type' => 'select',
			'options' => $options,
		];
		if (empty($value)) {
			$value = $default;
		}
		if ($multi) {
			$params['allow_multiple'] = true;
			if (substr($name, -2) != '[]') {
				$name .= '[]';
			}
		}
		print_widget($name, $params, $value);
	}

	public function updateMembershipStatuses($vals)
	{
		$GLOBALS['system']->doTransaction('BEGIN');
		[$options, $default] = self::getMembershipStatusOptionsAndDefault();
		foreach ($vals as $personid => $status) {
			if (!isset($options[$status])) {
				trigger_error("Invalid person status $status not saved");
				continue;
			}
			$res = $GLOBALS['db']->query('UPDATE person_group_membership
										SET membership_status = '.$GLOBALS['db']->quote($status).'
										WHERE groupid = '.(int) $this->id.'
											AND personid = '.(int) $personid);
		}
		$GLOBALS['system']->doTransaction('COMMIT');

		return true;
	}

	static function printMultiChooser($name, $value, $exclude_groups = [], $allow_category_select = false)
	{
		?>
		<table class="expandable">
		<?php
		foreach ($value as $id) {
			?>
			<tr>
				<td>
					<?php self::printChooser($name.'[]', $id, $exclude_groups, $allow_category_select); ?>
				</td>
			</tr>
			<?php
		}
		?>
			<tr>
				<td>
					<?php $gotGroups = self::printChooser($name.'[]', 0, $exclude_groups, $allow_category_select); ?>
				</td>
			</tr>
		</table>
		<?php
		return $gotGroups;
	}

	static function printChooser($fieldname, $value, $exclude_groups = [], $allow_category_select = false, $empty_text = '(Choose)')
	{
		static $cats = null;
		static $groupsCache = null;
		if ($cats === null) {
			$cats = $GLOBALS['system']->getDBObjectData('person_group_category', [], 'OR', 'name');
		}
		if ($value === null) {
			if ($groupsCache === null) {
				$groupsCache = $GLOBALS['system']->getDBObjectData('person_group', ['is_archived' => 0], 'OR', 'name');
			}
			$groups = $groupsCache;
		} else {
			$groups = $GLOBALS['system']->getDBObjectData('person_group', ['is_archived' => 0, 'id' => $value], 'OR', 'name');
		}

		if (empty($groups)) {
			?><i>There are no groups in the system yet</i> &nbsp;<?php
			return false;
		}
		?>
		<select name="<?php echo $fieldname; ?>">
			<option value=""><?php echo ents($empty_text); ?></option>
			<?php
			self::_printChooserOptions($cats, $groups, $value, $allow_category_select);
		if ($allow_category_select) {
			$sel = ($value === 'c0') ? ' selected="selected"' : '';
			?>
				<option value="c0" class="strong"<?php echo $sel; ?>>Uncategorised Groups (ALL)</option>
				<?php
			self::_printChooserGroupOptions($groups, 0, $value);
		} else {
			?>
				<optgroup label="Uncategorised Groups">
				<?php self::_printChooserGroupOptions($groups, 0, $value); ?>
				</optgroup>
				<?php
		}
		?>
		</select>
		<?php

		return true;
	}

	private static function _printChooserOptions($cats, $groups, $value, $allow_category_select = false, $parentcatid = 0, $prefix = '')
	{
		foreach ($cats as $cid => $cat) {
			if ($cat['parent_category'] != $parentcatid) {
				continue;
			}
			if ($allow_category_select) {
				$sel = ($value === 'c'.$cid) ? ' selected="selected"' : '';
				?>
				<option value="c<?php echo $cid; ?>" class="strong"<?php echo $sel; ?>><?php echo $prefix.ents($cat['name']); ?> (ALL)</option>
				<?php
				self::_printChooserGroupOptions($groups, $cid, $value, $prefix.'&nbsp;&nbsp;&nbsp;');
				self::_printChooserOptions($cats, $groups, $value, $allow_category_select, $cid, $prefix.'&nbsp;&nbsp;');
			} else {
				?>
				<optgroup label="<?php echo $prefix.ents($cat['name']); ?>">
				<?php
				self::_printChooserGroupOptions($groups, $cid, $value);
				self::_printChooserOptions($cats, $groups, $value, $allow_category_select, $cid, $prefix.'&nbsp;&nbsp;');
				?>
				</optgroup>
				<?php
			}
		}
	}

	private static function _printChooserGroupOptions($groups, $catid, $value, $prefix = '')
	{
		foreach ($groups as $gid => $group) {
			if ($group['categoryid'] != $catid) {
				continue;
			}
			$sel = ($gid == $value) ? ' selected="selected"' : '';
			?>
			<option value="<?php echo (int) $gid; ?>"<?php echo $sel; ?>><?php echo $prefix.ents($group['name']); ?></option>
			<?php
		}
	}

	public function canRecordAttendanceOn($date)
	{
		$testIndex = array_search(date('l', strtotime($date)), $this->fields['attendance_recording_days']['options'], true);

		return $testIndex & $this->getValue('attendance_recording_days');
	}

	/**
	 * If there is exactly one group whose name matches, return the group object.
	 * Matching is done case-insensitively, and considering spaces and underscores as the same.
	 *
	 * @param string $name
	 */
	public static function findByName($name)
	{
		static $warnings = [];
		$name = str_replace(' ', '_', strtolower($name));
		$SQL = 'SELECT * from person_group WHERE REPLACE(LOWER(name), " ", "_") = '.$GLOBALS['db']->quote($name);
		$res = $GLOBALS['db']->queryAll($SQL, null, null, true);
		if (count($res) > 1) {
			if (empty($warnings[$name])) {
				add_message('Could not match a single group called "'.$name.'" - there are several groups with that name', 'warning');
				$warnings[$name] = true;
			}
		}
		if (count($res) == 1) {
			$g = new self();
			$g->populate(key($res), reset($res));

			return $g;
		}

		return null;
	}
}
