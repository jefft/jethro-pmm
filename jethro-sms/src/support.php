<?php

/**
 * Standalone fallbacks for the two tiny Jethro globals the engine uses.
 *
 * When jethro-sms runs embedded in Jethro, the main app's include/general.php
 * has already defined ifdef() and ents(). When the package runs standalone
 * (CLI, its own test suite), these definitions apply.
 *
 * Bodies are verbatim copies of include/general.php — keep them in sync
 * (they have not changed in years; see docs/extraction.md §2).
 */

// When the main app is present, load its canonical definitions first so the
// function_exists guards below become no-ops.
$mainGeneralPath = __DIR__ . '/../../include/general.php';
if (file_exists($mainGeneralPath)) {
	require_once $mainGeneralPath;
}

if (!function_exists('ifdef')) {
	/**
	 * Safe constant read with fallback.
	 * @param string $constantName
	 * @param mixed $fallback
	 * @return mixed
	 */
	function ifdef($constantName, $fallback = null)
	{
		return defined($constantName) ? constant($constantName) : $fallback;
	}
}

if (!function_exists('ents')) {
	/**
	 * Multibyte-aware version of htmlentities. Also has a shorter name.
	 * @param string|null $str  The string to entitise
	 * @return string
	 */
	function ents($str)
	{
		if ($str === null) return '';
		if (trim(strval($str)) == '') {
			return '';
		}
		return htmlspecialchars(strval($str), ENT_QUOTES, "UTF-8", false);
	}
}
