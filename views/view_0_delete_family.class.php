<?php
class View__Delete_Family extends View
{
	private $_family = NULL;

	static function getMenuPermissionLevel()
	{
		return PERM_SYSADMIN;
	}

	function processView()
	{
		if (!empty($_REQUEST['familyid'])) {
			$this->_family = $GLOBALS['system']->getDBObject('family', (int)$_REQUEST['familyid']);
		}
		if (empty($this->_family)) throw new \RuntimeException("Family not found");

		if (!empty($_POST['confirm_delete'])) {
			$name = $this->_family->toString();
			// Delete all members first (required by FK constraint)
			$members = $this->_family->getMemberData();
			foreach ($members as $personid => $data) {
				$person = $GLOBALS['system']->getDBObject('person', $personid);
				if ($person) $person->delete();
			}
			$this->_family->delete();
			add_message($name.' has been deleted', 'success');
			redirect('home');
		}
	}

	public function getTitle()
	{
		return _('Delete Family: ').$this->_family->toString();
	}

	public function printView()
	{
		?>
		<p class="text"><?php echo _('Deleting a family cannot be undone. All members will also be deleted.'); ?></p>
		<form method="post">
			<input type="hidden" name="familyid" value="<?php echo (int)$this->_family->id; ?>" />
			<input type="submit" class="btn btn-danger" name="confirm_delete" value="<?php echo _('Delete Family'); ?>" />
			<button type="button" class="btn back"><?php echo _('Cancel'); ?></button>
		</form>
		<?php
	}
}
