<?php
abstract class Call
{
	abstract public function run();

	/**
	 * The permission level required to run this call. Checked by
	 * System_Controller before run().
	 *
	 * Semantics:
	 *  - PERM_NONE  any authenticated staff account (Jethro has no
	 *               PERM_VIEWPERSON - person-data reads are staff-visible
	 *               by design).
	 *  - int        the PERM_* constant to require, e.g. PERM_VIEWROSTER.
	 *  - NULL       no dispatcher check. Only for pre-auth/public calls
	 *               (call_2fa_verify / call_2fa_wait) or calls whose run()
	 *               performs its own compound permission checks
	 *               (call_sms_info). Requires justification.
	 *
	 * @return int|null
	 */
	public static function getRequiredPermissionLevel()
	{
		return PERM_NONE;
	}

}//end class
