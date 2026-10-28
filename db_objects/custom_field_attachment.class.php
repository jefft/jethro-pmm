<?php
/**
 * Metadata for a person custom-field attachment stored on the filesystem.
 *
 * The file itself lives under ATTACHMENTS_ROOT_PATH (see Attachments_Manager).
 * A custom_field_value row stores this object's ID in value_text.
 * @see docs/docs/developer/reference/custom-field-attachments.md
 */
class Custom_Field_Attachment extends DB_Object
{
	/** @var array<string, array{tmp_name:string, filename:string, mime:string, size:int}> */
	private static $staged_uploads = Array();

	/** @var array<int, string> */
	private static $filename_cache = Array();

	/** @var array<int, string> */
	private static $uploader_cache = Array();

	/** @var array<string, array{ok:bool, actual:string, message:string}> */
	private static $integrity_cache = Array();

	protected static function _getFields()
	{
		return Array(
			'personid' => Array(
				'type' => 'reference',
				'references' => 'person',
				'allow_empty' => FALSE,
			),
			'fieldid' => Array(
				'type' => 'reference',
				'references' => 'custom_field',
				'allow_empty' => FALSE,
			),
			'token' => Array(
				'type' => 'text',
				'allow_empty' => FALSE,
				'width' => 40,
			),
			'filename' => Array(
				'type' => 'text',
				'allow_empty' => FALSE,
				'width' => 60,
			),
			'mime' => Array(
				'type' => 'text',
				'allow_empty' => TRUE,
				'width' => 40,
			),
			'size' => Array(
				'type' => 'int',
				'allow_empty' => FALSE,
			),
			'created_by' => Array(
				'type' => 'reference',
				'references' => 'person',
				'allow_empty' => TRUE,
				'default' => NULL,
			),
			'sha1' => Array(
				'type' => 'text',
				'allow_empty' => TRUE,
				'width' => 40,
			),
			'created' => Array(
				'type' => 'datetime',
				'default' => 'CURRENT_TIMESTAMP',
				'readonly' => TRUE,
			),
		);
	}

	/**
	 * Same definition as upgrades/2026-upgrade-to-2.40.sql; foreign keys are
	 * added by the installer from getForeignKeys().
	 * @param string|null $table_name
	 * @return string
	 */
	public function getInitSQL($table_name=NULL)
	{
		return "CREATE TABLE `custom_field_attachment` (
			`id` int(11) NOT NULL AUTO_INCREMENT,
			`personid` int(11) NOT NULL,
			`fieldid` int(11) NOT NULL,
			`token` varchar(255) NOT NULL,
			`filename` varchar(255) NOT NULL,
			`mime` varchar(255) NULL DEFAULT NULL,
			`size` int(11) NOT NULL,
			`created_by` int(11) NULL DEFAULT NULL,
			`sha1` char(40) NULL DEFAULT NULL,
			`created` datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`),
			UNIQUE KEY `token` (`token`),
			INDEX `person_field` (`personid`, `fieldid`)
		) ENGINE=InnoDB;";
	}

	public function getForeignKeys()
	{
		return Array(
			'personid' => '_person(id) ON DELETE CASCADE',
			'fieldid' => 'custom_field(id) ON DELETE CASCADE',
			'created_by' => '_person(id) ON DELETE SET NULL',
		);
	}

	public function toString()
	{
		return (string)$this->getValue('filename');
	}

	/**
	 * Validate and remember an uploaded file until the owning person is saved.
	 * The returned value is a temporary marker, not a database ID.
	 *
	 * @param int $fieldid
	 * @param int $personid
	 * @return string|null       "new:<token>" or null if rejected
	 */
	public static function stageUpload($file, $params, $fieldLabel, $fieldid=0, $personid=0)
	{
		$upload = Attachments_Manager::validateUpload($file, $params, $fieldLabel);
		if (!$upload) return NULL;
		$token = bin2hex(random_bytes(16));
		$staging = self::_stagingDir();
		if ($staging) {
			$path = $staging.'/'.$token;
			if (@move_uploaded_file($upload['tmp_name'], $path)) {
				@chmod($path, 0600);
				$userid = !empty($GLOBALS['user_system']) ? (int)$GLOBALS['user_system']->getCurrentPerson('id') : 0;
				$meta = Array('filename' => $upload['filename'], 'mime' => $upload['mime'], 'size' => $upload['size'],
					'fieldid' => (int)$fieldid, 'personid' => (int)$personid, 'userid' => $userid);
				@file_put_contents($path.'.json', json_encode($meta));
				$upload['tmp_name'] = $path;
				$upload['fieldid'] = (int)$fieldid;
				$upload['personid'] = (int)$personid;
				$upload['userid'] = $userid;
			}
		}
		self::$staged_uploads[$token] = $upload;
		return 'new:'.$token;
	}

	/**
	 * Directory for uploads staged across requests.
	 * @return string|null
	 */
	private static function _stagingDir()
	{
		$root = Attachments_Manager::getRootPath();
		$dir = rtrim($root, '/\\').'/.staging';
		if (!is_dir($dir) && !@mkdir($dir, 0700, TRUE) && !is_dir($dir)) return NULL;
		self::_cleanStaging($dir);
		return $dir;
	}

	/**
	 * Remove staging files older than a day (abandoned forms).
	 * @param string $dir
	 * @return void
	 */
	private static function _cleanStaging($dir)
	{
		foreach ((array)glob($dir.'/*') as $path) {
			if (is_file($path) && filemtime($path) < time() - 86400) @unlink($path);
		}
	}

	/**
	 * Read a staged upload, whether it was staged in this request or persisted.
	 * @param string $token
	 * @return array|null
	 */
	private static function _readStaged($token)
	{
		if (isset(self::$staged_uploads[$token])) return self::$staged_uploads[$token];
		if (!preg_match('/^[0-9a-f]{32}$/', $token)) return NULL;
		$staging = self::_stagingDir();
		if (!$staging) return NULL;
		$path = $staging.'/'.$token;
		$meta = @json_decode((string)@file_get_contents($path.'.json'), TRUE);
		if (!is_array($meta) || !is_file($path)) return NULL;
		return Array(
			'tmp_name' => $path,
			'filename' => (string)($meta['filename'] ?? ''),
			'mime' => (string)($meta['mime'] ?? ''),
			'size' => (int)($meta['size'] ?? 0),
			'fieldid' => (int)($meta['fieldid'] ?? 0),
			'personid' => (int)($meta['personid'] ?? 0),
			'userid' => (int)($meta['userid'] ?? 0),
		);
	}

	/** Check that a temporary marker belongs to this person, field and user. */
	public static function isValidStagedUpload($value, $personid, $fieldid)
	{
		if (0 !== strpos((string)$value, 'new:')) return FALSE;
		$upload = self::_readStaged(substr((string)$value, 4));
		$userid = !empty($GLOBALS['user_system']) ? (int)$GLOBALS['user_system']->getCurrentPerson('id') : 0;
		return $upload
			&& (int)$upload['personid'] === (int)$personid
			&& (int)$upload['fieldid'] === (int)$fieldid
			&& (int)$upload['userid'] === $userid;
	}

	/**
	 * Look at a staged upload without consuming it (for re-rendering a form
	 * after a validation error).
	 * @param string $value "new:<token>"
	 * @return array|null
	 */
	public static function peekStagedUpload($value)
	{
		if (0 !== strpos((string)$value, 'new:')) return NULL;
		return self::_readStaged(substr((string)$value, 4));
	}

	/**
	 * Take a staged upload for insertion. The stage is consumed so a marker
	 * cannot store the same temporary file twice.
	 *
	 * @param string $value  "new:<token>"
	 * @return array|null
	 */
	public static function takeStagedUpload($value)
	{
		if (0 !== strpos((string)$value, 'new:')) {
			return NULL;
		}
		$token = substr((string)$value, 4);
		$res = self::_readStaged($token);
		if (!$res) return NULL;
		unset(self::$staged_uploads[$token]);
		$staging = self::_stagingDir();
		if ($staging) @unlink($staging.'/'.$token.'.json');
		return $res;
	}

	/**
	 * Filename for a staged upload, used in change-history text before save.
	 * @param string $value
	 * @return string
	 */
	public static function getStagedFileName($value)
	{
		if (0 !== strpos((string)$value, 'new:')) {
			return '';
		}
		$upload = self::peekStagedUpload($value);
		return $upload ? $upload['filename'] : '';
	}

	/**
	 * Move a staged upload into the attachment tree and create its metadata row.
	 * @param int $personid
	 * @param int $fieldid
	 * @param array $upload  As returned by Attachments_Manager::validateUpload()
	 * @return Custom_Field_Attachment|null
	 */
	public static function storeStagedUpload($personid, $fieldid, $upload)
	{
		$token = Attachments_Manager::storeUploadedFile(
			$upload['tmp_name'],
			$upload['filename'],
			$personid,
			$fieldid
		);
		if (!$token) {
			self::_discardStagedPayload($upload);
			add_message('The attachment "'.$upload['filename'].'" could not be stored.', 'error');
			return NULL;
		}

		$path = Attachments_Manager::getPath($personid, $fieldid, $token);
		$sha1 = $path ? @sha1_file($path) : FALSE;
		if (!$sha1) {
			Attachments_Manager::deleteFile($personid, $fieldid, $token);
			add_message('The attachment "'.$upload['filename'].'" could not be hashed.', 'error');
			return NULL;
		}
		$createdBy = NULL;
		if (!empty($GLOBALS['user_system']) && method_exists($GLOBALS['user_system'], 'getCurrentPerson')) {
			$currentPersonId = (int)$GLOBALS['user_system']->getCurrentPerson('id');
			if ($currentPersonId) $createdBy = $currentPersonId;
		}

		$attachment = new Custom_Field_Attachment();
		$attachment->setValue('personid', $personid);
		$attachment->setValue('fieldid', $fieldid);
		$attachment->setValue('token', $token);
		$attachment->setValue('filename', $upload['filename']);
		$attachment->setValue('mime', $upload['mime']);
		$attachment->setValue('size', $upload['size']);
		$attachment->setValue('created_by', $createdBy);
		$attachment->setValue('sha1', $sha1);
		try {
			$created = $attachment->create();
		} catch (\Throwable $e) {
			Attachments_Manager::deleteFile($personid, $fieldid, $token);
			throw $e;
		}
		if (!$created) {
			Attachments_Manager::deleteFile($personid, $fieldid, $token);
			return NULL;
		}
		self::$filename_cache[(int)$attachment->id] = $upload['filename'];

		// If the surrounding transaction is rolled back (e.g. the duplicate-family
		// check), the metadata row disappears but the file would otherwise remain.
		$attachmentId = (int)$attachment->id;
		register_shutdown_function(function() use ($attachmentId, $personid, $fieldid, $token) {
			try {
				if (!Custom_Field_Attachment::getRow($attachmentId)) {
					Attachments_Manager::deleteFile($personid, $fieldid, $token);
				}
			} catch (\Throwable $e) {
				// The request is already ending; nothing useful to do.
			}
		});
		return $attachment;
	}

	/**
	 * Remove the payload of a staged upload whose store failed. Only files
	 * inside the staging directory are removed: an upload that was never
	 * staged still points at PHP's own upload tmp file, which must not be
	 * unlinked here.
	 * @param array $upload
	 * @return void
	 */
	private static function _discardStagedPayload($upload)
	{
		$path = (string)($upload['tmp_name'] ?? '');
		if ($path === '') {
			return;
		}
		$staging = rtrim(Attachments_Manager::getRootPath(), '/\\').'/.staging';
		if (0 !== strpos($path, $staging.'/')) {
			return;
		}
		if (is_file($path)) @unlink($path);
	}

	/**
	 * Get one attachment's metadata without loading any file contents.
	 * @param int $id
	 * @return array|null
	 */
	public static function getRow($id)
	{
		$sql = 'SELECT id, personid, fieldid, token, filename, mime, size, created_by, sha1, created
				FROM custom_field_attachment
				WHERE id = '.(int)$id;
		$row = $GLOBALS['db']->queryRow($sql);
		return $row ?: NULL;
	}

	/**
	 * Get the display filename for an attachment ID, with a per-request cache.
	 * @param int|string $id
	 * @return string
	 */
	public static function getDisplayName($id)
	{
		if (0 === strpos((string)$id, 'new:')) {
			return self::getStagedFileName($id);
		}
		$id = (int)$id;
		if ($id <= 0) {
			return '';
		}
		if (!array_key_exists($id, self::$filename_cache)) {
			self::$filename_cache[$id] = (string)$GLOBALS['db']->queryOne(
				'SELECT filename FROM custom_field_attachment WHERE id = '.$id
			);
		}
		return self::$filename_cache[$id];
	}

	/**
	 * Check that an attachment ID belongs to the given person and field.
	 * @param int $id
	 * @param int $personid
	 * @param int $fieldid
	 * @return bool
	 */
	public static function isValidForPersonField($id, $personid, $fieldid)
	{
		return (bool)$GLOBALS['db']->queryOne(
			'SELECT 1 FROM custom_field_attachment
			 WHERE id = '.(int)$id.'
			   AND personid = '.(int)$personid.'
			   AND fieldid = '.(int)$fieldid
		);
	}

	/**
	 * Verify the stored SHA-1 against the file on disk.
	 * Missing hashes are recorded once, for attachments created before hashing.
	 *
	 * @param array $row  A custom_field_attachment metadata row
	 * @return array{ok:bool, actual:string, message:string}
	 */
	public static function checkIntegrity($row)
	{
		$path = Attachments_Manager::getPath(
			(int)$row['personid'],
			(int)$row['fieldid'],
			(string)$row['token']
		);
		if (!$path || !is_file($path)) {
			return Array('ok' => FALSE, 'actual' => '', 'message' => 'File is missing.');
		}

		$stored = strtolower(trim((string)array_get($row, 'sha1', '')));
		$mtime = @filemtime($path);
		$size = @filesize($path);
		$cacheKey = (int)$row['id'].':'.$mtime.':'.$size.':'.$stored;
		if (isset(self::$integrity_cache[$cacheKey])) {
			return self::$integrity_cache[$cacheKey];
		}

		$actual = @sha1_file($path);
		if ($actual === FALSE) {
			return Array('ok' => FALSE, 'actual' => '', 'message' => 'File could not be read.');
		}

		if ($stored === '') {
			$GLOBALS['db']->query(
				'UPDATE custom_field_attachment SET sha1 = '.$GLOBALS['db']->quote($actual).'
				 WHERE id = '.(int)$row['id']
			);
			$stored = $actual;
		}

		$ok = hash_equals($stored, $actual);
		$result = Array(
			'ok' => $ok,
			'actual' => $actual,
			'message' => $ok ? '' : 'SHA-1 hash does not match.',
		);
		self::$integrity_cache[$cacheKey] = $result;
		return $result;
	}

	/**
	 * Human-readable uploader name. Falls back to "Unknown" if the person is gone.
	 * @param array $row
	 * @return string
	 */
	public static function getUploaderName($row)
	{
		$id = (int)array_get($row, 'created_by', 0);
		if ($id <= 0) {
			return 'Unknown';
		}
		if (!array_key_exists($id, self::$uploader_cache)) {
			$person = $GLOBALS['db']->queryRow(
				'SELECT first_name, last_name FROM _person WHERE id = '.$id
			);
			self::$uploader_cache[$id] = $person
				? trim($person['first_name'].' '.$person['last_name'])
				: '';
		}
		return strlen(self::$uploader_cache[$id]) ? self::$uploader_cache[$id] : 'Unknown';
	}

	/**
	 * Uploader and timestamp for a hover title.
	 * @param array $row
	 * @return string
	 */
	public static function getUploadTitle($row)
	{
		$uploaded = array_get($row, 'created', '');
		$when = strlen($uploaded) ? format_datetime($uploaded) : 'Unknown time';
		return 'Uploaded by '.self::getUploaderName($row).' on '.$when;
	}

	/**
	 * @param int $personid
	 * @param int $fieldid
	 * @return array
	 */
	public static function getRowsForPersonField($personid, $fieldid)
	{
		return $GLOBALS['db']->queryAll(
			'SELECT id, personid, fieldid, token, filename, mime, size, created_by, sha1, created
			 FROM custom_field_attachment
			 WHERE personid = '.(int)$personid.'
			   AND fieldid = '.(int)$fieldid
		);
	}

	/**
	 * @param int $fieldid
	 * @return array
	 */
	public static function getRowsForField($fieldid)
	{
		return $GLOBALS['db']->queryAll(
			'SELECT id, personid, fieldid, token, filename, mime, size, created_by, sha1, created
			 FROM custom_field_attachment
			 WHERE fieldid = '.(int)$fieldid
		);
	}

	/**
	 * @param int $personid
	 * @return array
	 */
	public static function getRowsForPerson($personid)
	{
		return $GLOBALS['db']->queryAll(
			'SELECT id, personid, fieldid, token, filename, mime, size, created_by, sha1, created
			 FROM custom_field_attachment
			 WHERE personid = '.(int)$personid
		);
	}

	/**
	 * Delete an attachment row and remember its file for deletion after commit.
	 * @param int $id
	 * @param int $personid
	 * @param int $fieldid
	 * @param array $paths  Rows whose files should be unlinked after commit
	 * @return bool
	 */
	public static function deleteById($id, $personid, $fieldid, &$paths)
	{
		$row = self::getRow($id);
		if (!$row
			|| (int)$row['personid'] !== (int)$personid
			|| (int)$row['fieldid'] !== (int)$fieldid
		) {
			return FALSE;
		}
		$paths[] = $row;
		$GLOBALS['db']->query('DELETE FROM custom_field_attachment WHERE id = '.(int)$id);
		unset(self::$filename_cache[(int)$id]);
		return TRUE;
	}

	/**
	 * Unlink stored files for metadata rows.
	 * @param array $rows
	 * @return void
	 */
	public static function deleteFiles($rows)
	{
		foreach ((array)$rows as $row) {
			Attachments_Manager::deleteFile(
				(int)$row['personid'],
				(int)$row['fieldid'],
				(string)$row['token']
			);
			unset(self::$filename_cache[(int)$row['id']]);
		}
	}

	/**
	 * Delete all attachment metadata and files for a person.
	 * @param int $personid
	 * @return void
	 */
	public static function deleteForPerson($personid)
	{
		$GLOBALS['db']->query('DELETE FROM custom_field_attachment WHERE personid = '.(int)$personid);
		self::$filename_cache = Array();
		Attachments_Manager::deletePersonDir($personid);
	}

	public function delete()
	{
		$path = Attachments_Manager::getPath(
			(int)$this->getValue('personid'),
			(int)$this->getValue('fieldid'),
			(string)$this->getValue('token')
		);
		$res = parent::delete();
		if ($res && $path && is_file($path)) {
			@unlink($path);
			Attachments_Manager::pruneEmptyDirs(
				(int)$this->getValue('personid'),
				(int)$this->getValue('fieldid')
			);
		}
		unset(self::$filename_cache[(int)$this->id]);
		return $res;
	}
}
