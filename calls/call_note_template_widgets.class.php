<?php
class Call_Note_Template_Widgets extends Call
{

	public static function getRequiredPermissionLevel()
	{
		return PERM_EDITNOTE;
	}

	function run()
	{
		$GLOBALS['system']->initErrorHandler();
		$template = $GLOBALS['system']->getDBObject('note_template', (int)$_REQUEST['templateid']);
		if ($template) {
			$template->printNoteFieldWidgets();
			?>
			<script>
				$('input[name=subject]').val("<?php echo ents($template->getValue('subject')); ?>");
			</script>
			<?php
		}
	}
}