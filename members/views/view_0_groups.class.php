<?php
class View__Groups extends View
{
	private $group = NULL;
	
	function getTitle()
	{
		if ($this->group) return $this->group->getValue('name');
	}

	function processView()
	{
		$this->group = $GLOBALS['system']->getDBObject('person_group', (int)$_REQUEST['groupid']);
	}
	
	function printView()
	{
		if (empty($_REQUEST['groupid'])) {
			trigger_error("Group ID missing");
			return;
		}

		$GLOBALS['system']->includeDBClass('member');
		$persons = $this->group->getMembers(Array('!(status' => Person_Status::getArchivedIDs()));
		// The mailto is only rendered to a verified viewer, and it must carry
		// real addresses; masking is a display concern (the member list masks
		// its own values). Pre-verification collect nothing at all.
		$verified = $GLOBALS['user_system']->hasVerifiedMemberEdit();
		$emails = Array();
		if ($verified) {
			foreach ($persons as $member) {
				if ($e = $member['email']) $emails[] = $e;
			}
		}

		include 'templates/member_list.template.php';

		if (!$verified) {
			// Contact values are masked pre-verification; the mailto link would
			// carry the real addresses.
			echo '<p><i>Verify your identity via Edit to see member contact details.</i></p>';
		} else if (count($persons) < EMAIL_CHUNK_SIZE) {
			echo '<a href="mailto:'.implode(',', $emails).'">Email all members</a>';
		}

	}

}
