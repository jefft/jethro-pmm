<?php
class View_Display_Role_Description extends View
{
	var $_role;

	function processView()
	{
		if (!empty($_REQUEST['role'])) {
			$this->_role = $GLOBALS['system']->getDBObject('roster_role', (int) $_REQUEST['role']);
		}
	}

	function getTitle()
	{
		if ($this->_role) {
			return 'Roster Role: '.$this->_role->getFormattedValue('congregationid').' '.$this->_role->getValue('title');
		} else {
			return 'Display Role Description';
		}
	}

	function printView()
	{
		if ($this->_role) {
			?>
			<a class="soft pull-right no-print" href="<?php echo BASE_PATH; ?>/?view=_edit_roster_role&roster_roleid=<?php echo $this->_role->id; ?>"><i class="icon-wrench"></i>Edit</a>
			<?php
			echo $this->_role->getValue('details');
		} else {
			$printed = false;
			foreach ($GLOBALS['system']->getDBObjectdata('congregation', ['!meeting_time' => ''], 'AND', 'meeting_time') as $congid => $cong_details) {
				$roles = $GLOBALS['system']->getDBObjectData('roster_role', ['!details' => '', 'congregationid' => $congid, 'active' => 1], 'AND', 'title');
				if (empty($roles)) {
					continue;
				}
				?>
				<h3><?php echo ents($cong_details['name']); ?></h3>
				<ul>
				<?php
				foreach ($roles as $id => $detail) {
					?>
					<li><a href="<?php echo build_url(['role' => $id]); ?>"><?php echo ents($detail['title']); ?></a></li>
					<?php
				}
				?>
				</ul>
				<?php
				$printed = true;
			}
			$roles = $GLOBALS['system']->getDBObjectData('roster_role', ['!details' => '', 'congregationid' => null, 'active' => 1], 'AND', 'title');
			if (!empty($roles)) {
				?>
				<h3>Non-Congregational</h3>
				<ul>
						<?php
						foreach ($roles as $id => $detail) {
							?>
								<li><a href="<?php echo build_url(['role' => $id]); ?>"><?php echo ents($detail['title']); ?></a></li>
								<?php
						}
				?>
				</ul>
				<?php
				$printed = true;
			}

			if (!$printed) {
				?>
				<p><i>No roles to show</i></p>
				<?php
			}
		}
	}
}
