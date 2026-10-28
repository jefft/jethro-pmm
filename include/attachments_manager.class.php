<?php
/**
 * Filesystem storage for person custom-field attachments.
 *
 * Attachments live in a directory tree under ATTACHMENTS_ROOT_PATH:
 *
 *   <root>/person/<personid>/field/<fieldid>/<token>
 *
 * The tree is deliberately separate from DOCUMENTS_ROOT_PATH so attachments do
 * not appear in the Documents view.  When ATTACHMENTS_ROOT_PATH is not defined
 * the default is <Jethro root>/attachments, which is denied by the bundled
 * nginx config and by its .htaccess file.
 *
 * @see Custom_Field_Attachment for the database metadata that points here.
 * @see docs/docs/developer/reference/custom-field-attachments.md
 */
class Attachments_Manager
{
	const DEFAULT_MAX_MB = 10;

	const DEFAULT_EXTENSIONS = 'pdf,jpg,jpeg,png,gif,doc,docx,odt,rtf,xls,xlsx,ods,ppt,pptx,txt,csv';

	/**
	 * Get the root directory for stored attachments.
	 * @return string
	 */
	public static function getRootPath()
	{
		$configured = ifdef('ATTACHMENTS_ROOT_PATH');
		if (is_string($configured) && strlen(trim($configured))) {
			$configured = rtrim(trim($configured), '/\\');
			if ($configured !== '' && $configured[0] !== '/'
				&& !preg_match('/^[A-Za-z]:[\\\\\/]/', $configured)
			) {
				$configured = rtrim(JETHRO_ROOT, '/\\').'/'.$configured;
			}
			return $configured;
		}
		return rtrim(JETHRO_ROOT, '/\\').'/attachments';
	}

	/**
	 * Directory for one person's values of one attachment field.
	 * @param int $personid
	 * @param int $fieldid
	 * @return string
	 */
	public static function getPersonFieldDir($personid, $fieldid)
	{
		return self::getRootPath().'/person/'.(int)$personid.'/field/'.(int)$fieldid;
	}

	/**
	 * Get the absolute filesystem path for a stored attachment.
	 * @param int $personid
	 * @param int $fieldid
	 * @param string $token
	 * @return string|null
	 */
	public static function getPath($personid, $fieldid, $token)
	{
		if ((int)$personid <= 0 || (int)$fieldid <= 0 || !self::isValidToken($token)) {
			return NULL;
		}
		return self::getPersonFieldDir($personid, $fieldid).'/'.$token;
	}

	/**
	 * Storage tokens are random hex, optionally followed by a short extension.
	 * @param string $token
	 * @return bool
	 */
	public static function isValidToken($token)
	{
		return (bool)preg_match('/^[a-f0-9]{32}(?:\.[a-z0-9]{1,10})?$/', (string)$token);
	}

	/**
	 * Generate a storage token, preserving a safe extension for administrator
	 * convenience when inspecting the directory tree.
	 * @param string $originalName
	 * @return string
	 */
	public static function generateToken($originalName)
	{
		$ext = strtolower(pathinfo((string)$originalName, PATHINFO_EXTENSION));
		if (!preg_match('/^[a-z0-9]{1,10}$/', $ext)) {
			$ext = '';
		}
		return bin2hex(random_bytes(16)).($ext ? '.'.$ext : '');
	}

	/**
	 * Move a validated uploaded file into the attachment tree.
	 * @param string $tmpName
	 * @param string $originalName
	 * @param int $personid
	 * @param int $fieldid
	 * @return string|null  The storage token, or null on failure.
	 */
	public static function storeUploadedFile($tmpName, $originalName, $personid, $fieldid)
	{
		if (!self::ensureRoot()) {
			return NULL;
		}
		$dir = self::getPersonFieldDir($personid, $fieldid);
		if (!is_dir($dir) && !@mkdir($dir, 0750, TRUE) && !is_dir($dir)) {
			return NULL;
		}
		if (!is_writable($dir)) {
			// Unwritable store (read-only mount, full disk, permissions): report
			// failure instead of letting move_uploaded_file() emit a warning,
			// which would break the caller's redirect.
			return NULL;
		}
		$token = self::generateToken($originalName);
		$path = $dir.'/'.$token;
		$moved = @move_uploaded_file($tmpName, $path);
		if (!$moved && is_file($tmpName)) {
			// A staging file persisted from an earlier request is not an HTTP upload.
			$moved = @rename($tmpName, $path) || (@copy($tmpName, $path) && @unlink($tmpName));
		}
		if (!$moved) {
			return NULL;
		}
		@chmod($path, 0640);
		return $token;
	}

	/**
	 * Delete one stored file and prune directories that become empty.
	 * @param int $personid
	 * @param int $fieldid
	 * @param string $token
	 * @return bool
	 */
	public static function deleteFile($personid, $fieldid, $token)
	{
		$path = self::getPath($personid, $fieldid, $token);
		if (!$path || !is_file($path)) {
			return FALSE;
		}
		$res = @unlink($path);
		self::pruneEmptyDirs($personid, $fieldid);
		return $res;
	}

	/**
	 * Delete the whole tree for a person. Used when the person record is deleted
	 * or archived-and-cleaned.
	 * @param int $personid
	 * @return void
	 */
	public static function deletePersonDir($personid)
	{
		if ((int)$personid <= 0) {
			return;
		}
		self::deleteTree(self::getRootPath().'/person/'.(int)$personid);
	}

	/**
	 * Remove empty field/person directories after an attachment is deleted.
	 * @param int $personid
	 * @param int $fieldid
	 * @return void
	 */
	public static function pruneEmptyDirs($personid, $fieldid)
	{
		$root = rtrim(self::getRootPath(), '/\\');
		$dir = self::getPersonFieldDir($personid, $fieldid);
		while (strlen($dir) > strlen($root) && 0 === strpos($dir.'/', $root.'/')) {
			if (!@rmdir($dir)) {
				break;
			}
			$dir = dirname($dir);
		}
	}

	/**
	 * Parse the configured allowed extensions for an attachment field.
	 * @param array $params  Custom field params
	 * @return string[]
	 */
	public static function getAllowedExtensions($params)
	{
		$raw = array_get((array)$params, 'allowed_extensions', '');
		if (is_array($raw)) {
			$parts = $raw;
		} else {
			$parts = preg_split('/[\s,]+/', (string)$raw, -1, PREG_SPLIT_NO_EMPTY);
		}
		if (empty($parts)) {
			$parts = explode(',', self::DEFAULT_EXTENSIONS);
		}
		$res = Array();
		foreach ($parts as $part) {
			$part = strtolower(trim($part));
			$part = ltrim($part, '.');
			if (preg_match('/^[a-z0-9]{1,10}$/', $part)) {
				$res[] = $part;
			}
		}
		$res = array_values(array_unique($res));
		return $res ?: explode(',', self::DEFAULT_EXTENSIONS);
	}

	/**
	 * @param array $params  Custom field params
	 * @return int  Maximum size in bytes.
	 */
	public static function getMaxBytes($params)
	{
		$maxMb = (int)array_get((array)$params, 'max_mb', 0);
		if ($maxMb <= 0) {
			$maxMb = self::DEFAULT_MAX_MB;
		}
		$max = $maxMb * 1024 * 1024;
		$phpMax = file_upload_max_size();
		if ($phpMax > 0) {
			$max = min($max, $phpMax);
		}
		return $max;
	}

	/**
	 * Human-readable maximum size for help text.
	 * @param array $params
	 * @return string
	 */
	public static function getMaxSizeLabel($params)
	{
		$bytes = self::getMaxBytes($params);
		if ($bytes >= 1024 * 1024) {
			return rtrim(rtrim(number_format($bytes / (1024 * 1024), 1), '0'), '.').' MB';
		}
		return max(1, (int)round($bytes / 1024)).' KB';
	}

	/**
	 * Build an HTML accept attribute value from the allowed extensions.
	 * @param array $params
	 * @return string
	 */
	public static function getAcceptAttribute($params)
	{
		return implode(',', array_map(function($ext) {
			return '.'.$ext;
		}, self::getAllowedExtensions($params)));
	}

	/**
	 * Validate one uploaded file against an attachment field's configuration.
	 * @param array $file       A single entry from $_FILES
	 * @param array $params     Custom field params
	 * @param string $fieldLabel Label used in error messages
	 * @return array|null       ['tmp_name', 'filename', 'mime', 'size'] or null
	 */
	public static function validateUpload($file, $params, $fieldLabel)
	{
		$error = (int)array_get((array)$file, 'error', UPLOAD_ERR_NO_FILE);
		if ($error === UPLOAD_ERR_NO_FILE) {
			return NULL;
		}
		if (in_array($error, Array(UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE))) {
			add_message('The attachment for '.$fieldLabel.' could not be saved because it is too big (maximum '.self::getMaxSizeLabel($params).').', 'error');
			return NULL;
		}
		if ($error !== UPLOAD_ERR_OK) {
			add_message('There was a problem uploading the attachment for '.$fieldLabel.' (error #'.$error.').', 'error');
			return NULL;
		}

		$tmpName = (string)array_get((array)$file, 'tmp_name', '');
		$originalName = (string)array_get((array)$file, 'name', '');
		$size = (int)array_get((array)$file, 'size', 0);
		if (!is_uploaded_file($tmpName)) {
			add_message('Security error: the uploaded attachment for '.$fieldLabel.' was not accepted.', 'error');
			return NULL;
		}
		if ($size <= 0) {
			add_message('The uploaded attachment for '.$fieldLabel.' is empty and has not been saved.', 'error');
			return NULL;
		}
		$max = self::getMaxBytes($params);
		if ($size > $max) {
			add_message('The uploaded attachment for '.$fieldLabel.' is too big (maximum '.self::getMaxSizeLabel($params).').', 'error');
			return NULL;
		}

		$filename = self::sanitizeFileName($originalName);
		$allowed = self::getAllowedExtensions($params);
		$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
		if (!in_array($ext, $allowed)) {
			add_message('The uploaded attachment for '.$fieldLabel.' must be one of: '.implode(', ', $allowed).'.', 'error');
			return NULL;
		}

		$mime = 'application/octet-stream';
		if (function_exists('finfo_open')) {
			$finfo = finfo_open(FILEINFO_MIME_TYPE);
			if ($finfo) {
				$detected = finfo_file($finfo, $tmpName);
				if ($detected) {
					$mime = $detected;
				}
			}
		}
		if (preg_match('#(text/html|image/svg|php|javascript)#i', $mime)) {
			add_message('The uploaded attachment for '.$fieldLabel.' is not an accepted file type.', 'error');
			return NULL;
		}

		return Array(
			'tmp_name' => $tmpName,
			'filename' => $filename,
			'mime' => $mime,
			'size' => $size,
		);
	}

	/**
	 * Strip path components and unsafe characters from an uploaded filename.
	 * @param string $name
	 * @return string
	 */
	public static function sanitizeFileName($name)
	{
		$name = str_replace('\\', '/', (string)$name);
		$name = basename($name);
		if (!mb_check_encoding($name, 'UTF-8')) {
			// Browsers on older Windows send Windows-1252 names.
			$name = mb_convert_encoding($name, 'UTF-8', 'Windows-1252');
		}
		$name = (string)preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
		$name = (string)preg_replace('/[^\p{L}\p{N}\p{Pd}._ ()\-]/u', '_', $name);
		$name = trim($name, " .\t\n\r");
		$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
		$base = pathinfo($name, PATHINFO_FILENAME);
		if (!preg_match('/^[a-z0-9]{1,10}$/', $ext)) {
			$ext = '';
		}
		$base = mb_substr($base, 0, 200);
		if (!strlen($base)) {
			$base = 'attachment';
		}
		return $base.($ext ? '.'.$ext : '');
	}

	/**
	 * Create the configured root directory if needed.
	 * @return bool
	 */
	private static function ensureRoot()
	{
		$root = self::getRootPath();
		if (is_dir($root)) {
			return TRUE;
		}
		return @mkdir($root, 0750, TRUE) || is_dir($root);
	}

	/**
	 * Recursively delete a path, but only if it is inside the attachments root.
	 * @param string $path
	 * @return void
	 */
	private static function deleteTree($path)
	{
		$root = realpath(self::getRootPath());
		$real = realpath($path);
		if (!$root
			|| !$real
			|| $real === rtrim($root, '/')
			|| 0 !== strpos($real.'/', rtrim($root, '/').'/')
		) {
			return;
		}
		if (is_file($real) || is_link($real)) {
			@unlink($real);
			return;
		}
		if (!is_dir($real)) {
			return;
		}
		$items = @scandir($real);
		if ($items) {
			foreach ($items as $item) {
				if ($item === '.' || $item === '..') continue;
				self::deleteTree($real.'/'.$item);
			}
		}
		@rmdir($real);
	}
}
