<?php

class Call_Documents extends Call
{
	function run()
	{
		$dirs = explode('|', MEMBER_FILES_DIRS);

		$dirOK = false;
		$filepath = realpath(Documents_Manager::getRootPath().'/'.$_REQUEST['getfile']);
		foreach ($dirs as $dir) {
			$fulldir = Documents_Manager::getRootPath().'/'.$dir;
			if (str_starts_with($filepath, $fulldir)) {
				$dirOK = true;
			}
		}

		if (!$dirOK) {
			trigger_error('Illegal file directory requested');
			exit;
		}

		Documents_Manager::serveFile(Documents_Manager::getRootPath().'/'.$_REQUEST['getfile']);
	}
}
