<?php
/**
 * Serve a person custom-field attachment.
 *
 * Access follows the restricted person view and the field's visibility (custom
 * fields view, plus sensitive-fields view for a sensitive field), so users only
 * receive files they are allowed to see. Files are always sent as downloads;
 * they are never rendered inline.
 */
class Call_Custom_Field_Attachment extends Call
{
	function run()
	{
		$id = (int)array_get($_REQUEST, 'id', 0);
		$row = $id ? Custom_Field_Attachment::getRow($id) : NULL;
		if (!$row) {
			http_response_code(404);
			echo 'Attachment not found';
			return;
		}

		$visible = $GLOBALS['db']->queryOne(
			'SELECT 1 FROM person WHERE id = '.(int)$row['personid']
		);
		if (!$visible) {
			http_response_code(404);
			echo 'Attachment not found';
			return;
		}

		$field = new Custom_Field((int)$row['fieldid']);
		if (!$field->id || !$field->isVisibleToCurrentUser()) {
			http_response_code(404);
			echo 'Attachment not found';
			return;
		}

		$path = Attachments_Manager::getPath(
			(int)$row['personid'],
			(int)$row['fieldid'],
			(string)$row['token']
		);
		if (!$path || !is_file($path)) {
			http_response_code(404);
			echo 'Attachment not found';
			return;
		}

		$check = Custom_Field_Attachment::checkIntegrity($row);
		if (!$check['ok']) {
			http_response_code(409);
			echo 'Attachment integrity check failed: '.$check['message'];
			return;
		}

		$filename = str_replace(Array('\\', '"', "\r", "\n"), Array('_', '_', '', ''), (string)$row['filename']);
		if (!strlen($filename)) {
			$filename = 'attachment';
		}
		$fallback = preg_replace('/[^\x20-\x7E]/', '_', $filename);

		header('Content-Type: application/octet-stream');
		header('Content-Length: '.filesize($path));
		header('Content-Disposition: attachment; filename="'.$fallback.'"; filename*=UTF-8\'\''.rawurlencode($filename));
		header('X-Content-Type-Options: nosniff');
		header('Cache-Control: private, no-store');
		header('Pragma: no-cache');
		readfile($path);
	}
}
