-- Set flag causing the home page to warn sysadmin users that the database charset needs upgrading
INSERT IGNORE INTO setting
(symbol, type, value, note)
VALUES ('NEEDS_UTF8MB4_UPGRADE', 'hidden', "1", "Whether the database charset upgrade to utf8mb4 needs to be run");

-- Separate members-area identity from staff-account identity (#1173, #1433).
-- @current_user_id is the staff account; its congregation/group restrictions are
-- applied by the person and person_group views.  The members area now sets
-- @current_member_id instead, so a member who also has a restricted staff account
-- no longer loses sight of their own family / roster assignees in the members area.
-- See docs/docs/developer/reference/db-session-identity.md.  Synced with
-- include/installer.class.php, db_objects/person.class.php and
-- db_objects/person_group.class.php.  Idempotent.
-- DROP+CREATE rather than CREATE OR REPLACE FUNCTION: MariaDB supports the latter,
-- MySQL (8.0+, per README requirements) does not.  This is idempotent on both.
DROP FUNCTION IF EXISTS getCurrentMemberID;
CREATE FUNCTION getCurrentMemberID() RETURNS INTEGER NO SQL RETURN @current_member_id;

-- Viewable person fields (see the data_permissions section below): hidden
-- contact fields are blanked by the person and family views using the
-- session's @current_user_data_permissions. Defined here, next to the family
-- rename, so no point in this script leaves `family` missing or `person`
-- unmasked. Synced with include/installer.class.php and Person::getViewSQL().
--
-- The symbol mapping mirrors include/data_permissions.php. Keep it in a single
-- RETURN expression: the installer runs each SQL item as one statement, so a
-- BEGIN/END body would need delimiter handling. The unit tests compare this
-- mapping and the view call sites against the PHP constants.
DROP FUNCTION IF EXISTS currentUserHasDataPerm;
CREATE FUNCTION currentUserHasDataPerm(symbol VARCHAR(32)) RETURNS TINYINT NO SQL
RETURN (@current_member_id IS NOT NULL)
    OR (@current_user_id = -1)
    OR CASE symbol
         WHEN 'VIEWADDRESS'      THEN ((@current_user_data_permissions & 134217728) <> 0)
         WHEN 'VIEWEMAIL'        THEN ((@current_user_data_permissions & 536870912) <> 0)
         WHEN 'VIEWMOBILE'       THEN ((@current_user_data_permissions & 268435456) <> 0)
         WHEN 'VIEWREMARKS'      THEN ((@current_user_data_permissions & 33554432) <> 0)
         WHEN 'VIEWCUSTOMFIELDS' THEN ((@current_user_data_permissions & 67108864) <> 0)
         WHEN 'VIEWSENSITIVE'    THEN ((@current_user_data_permissions & 16777216) <> 0)
         ELSE 0
       END;

-- Rename the base family table; `family` becomes the masked read view.
-- Idempotent for databases that have already been cut over.
SET @family_is_table = (SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'family' AND TABLE_TYPE = 'BASE TABLE');
SET @family_base_exists = (SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '_family');
SET @stmt = IF(@family_is_table = 1 AND @family_base_exists = 0,
    'RENAME TABLE `family` TO `_family`',
    'SELECT 1');
PREPARE stmt FROM @stmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE OR REPLACE VIEW `family` AS
SELECT f.`id`, f.`family_name`, f.`status`,
       CASE WHEN currentUserHasDataPerm('VIEWADDRESS') THEN f.`address_street` ELSE '' END AS `address_street`,
       CASE WHEN currentUserHasDataPerm('VIEWADDRESS') THEN f.`address_suburb` ELSE '' END AS `address_suburb`,
       CASE WHEN currentUserHasDataPerm('VIEWADDRESS') THEN f.`address_state` ELSE '' END AS `address_state`,
       CASE WHEN currentUserHasDataPerm('VIEWADDRESS') THEN f.`address_postcode` ELSE '' END AS `address_postcode`,
       CASE WHEN currentUserHasDataPerm('VIEWADDRESS') THEN f.`home_tel` ELSE '' END AS `home_tel`,
       f.`created`, f.`creator`, f.`history`
FROM `_family` f;

CREATE OR REPLACE VIEW `person` AS
SELECT p.`id`, p.`first_name`, p.`last_name`, p.`gender`, p.`age_bracketid`,
       CASE WHEN currentUserHasDataPerm('VIEWEMAIL') THEN p.`email` ELSE '' END AS `email`,
       CASE WHEN currentUserHasDataPerm('VIEWMOBILE') THEN p.`mobile_tel` ELSE '' END AS `mobile_tel`,
       CASE WHEN currentUserHasDataPerm('VIEWMOBILE') THEN p.`work_tel` ELSE '' END AS `work_tel`,
       CASE WHEN currentUserHasDataPerm('VIEWREMARKS') THEN p.`remarks` ELSE '' END AS `remarks`,
       p.`status`, p.`status_last_changed`, p.`history`, p.`creator`, p.`created`,
       p.`congregationid`, p.`familyid`, p.`member_password`, p.`resethash`,
       p.`resetexpires`, p.`feed_uuid`
FROM `_person` p
WHERE
	/* members-area session: staff restrictions do not apply */
	(getCurrentMemberID() IS NOT NULL)
	OR (
	getCurrentUserID() IS NOT NULL
	AND (
		/* the person in question IS the current user */
		(p.`id` = getCurrentUserID())
		OR /* we've been set to public mode */
		(getCurrentUserID() = -1)
		OR /* current user has no group/cong restrictions */
		((0 = (SELECT COUNT(*) FROM account_congregation_restriction cr WHERE cr.personid = getCurrentUserID()))
		   AND (0 = (SELECT COUNT(*) FROM account_group_restriction gr WHERE gr.personid = getCurrentUserID())))
		OR /* person is within a permitted cong */
		(p.`congregationid` IN (SELECT cr.congregationid FROM account_congregation_restriction cr WHERE cr.personid = getCurrentUserID()))
		OR /* person is within a permitted group */
		(p.`id` IN (SELECT m.personid FROM person_group_membership m JOIN account_group_restriction gr ON m.groupid = gr.groupid WHERE gr.personid = getCurrentUserID()))
	)
	);

CREATE OR REPLACE VIEW person_group AS
SELECT * from _person_group g
WHERE
  (getCurrentMemberID() IS NOT NULL AND g.owner IS NULL)
  OR (
  getCurrentUserID() IS NOT NULL
  AND
  ((g.owner IS NULL) OR (g.owner = getCurrentUserID()))
  AND
  (NOT EXISTS (SELECT * FROM account_group_restriction gr WHERE gr.personid = getCurrentUserID())
  OR g.id IN (SELECT groupid FROM account_group_restriction gr WHERE gr.personid = getCurrentUserID()))
  );

CREATE OR REPLACE VIEW member AS
SELECT mp.id, mp.first_name, mp.last_name, mp.gender, mp.age_bracketid, mp.congregationid,
mp.email, mp.mobile_tel, mp.work_tel, mp.familyid,
mf.family_name, mf.address_street, mf.address_suburb, mf.address_state, mf.address_postcode, mf.home_tel
FROM _person mp
JOIN person_status mps ON mps.id = mp.status
JOIN _family mf ON mf.id = mp.familyid
JOIN person_group_membership pgm1 ON pgm1.personid = mp.id
JOIN _person_group pg ON pg.id = pgm1.groupid AND pg.share_member_details = 1
JOIN person_group_membership pgm2 ON pgm2.groupid = pg.id
JOIN _person up ON up.id = pgm2.personid
JOIN person_status ups ON ups.id = up.status
WHERE up.id = getCurrentMemberID()
   AND (NOT mps.is_archived)    /* dont show archived persons */
   AND mf.status <> "archived"  /* dont show archived families */
   AND (NOT ups.is_archived)	/* dont let persons who are themselves archived see anything */
UNION
SELECT mp.id, mp.first_name, mp.last_name, mp.gender, mp.age_bracketid, mp.congregationid,
mp.email, mp.mobile_tel, mp.work_tel, mp.familyid,
mf.family_name, mf.address_street, mf.address_suburb, mf.address_state, mf.address_postcode, mf.home_tel
FROM _person mp
JOIN person_status mps ON mps.id = mp.status
JOIN _family mf ON mf.id = mp.familyid
JOIN _person self ON self.familyid = mp.familyid
JOIN person_status selfs ON selfs.id = self.status
WHERE
	self.id = getCurrentMemberID()
	AND ((NOT mps.is_archived) OR (mp.id = self.id))
	AND ((NOT selfs.is_archived) OR (mp.id = self.id))
	/* archived persons can only see themselves, not any family members */
;
--
-- SMS database tables
-- sms:          one row per send operation (body text, sender, timing)
-- smsdelivery:  one row per recipient (person, remote ref, delivery status);
--               table _smsdelivery, read through the restricted smsdelivery view

CREATE TABLE IF NOT EXISTS `sms` (
	`id` INT NOT NULL AUTO_INCREMENT,
	`body` TEXT NOT NULL,
	`sender` INT NULL DEFAULT NULL COMMENT 'Person ID of the sender, or NULL if a script',
	`wire_sender` VARCHAR(20) NULL DEFAULT NULL COMMENT 'Sender identity (alphanumeric ID or phone number) actually used on the wire',
	`created` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	`scheduled_send_at` DATETIME NULL DEFAULT NULL COMMENT 'Scheduled delivery time, or NULL for immediate',
	PRIMARY KEY (`id`),
	INDEX `sender` (`sender`),
	INDEX `created` (`created`)
) ENGINE=InnoDB;  -- No CHARSET: inherit the database default (see docs/docs/developer/reference/db-charset.md)


-- Align sms.body collation with _abstract_note.details so the INSERT ... SELECT
-- JOINs in the note migration don't fail with collation mismatch errors.
-- Idempotent: when collations already match, the ALTER is a no-op. Restates
-- NOT NULL, which MODIFY COLUMN would otherwise drop.
SET @target_collation = (
	SELECT collation_name FROM information_schema.columns
	WHERE table_schema = DATABASE() AND table_name = '_abstract_note' AND column_name = 'details'
);
SET @sql = CONCAT('ALTER TABLE sms MODIFY COLUMN body TEXT COLLATE ', @target_collation, ' NOT NULL');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- The table is _smsdelivery; smsdelivery (below) is a restricted view of it.
CREATE TABLE IF NOT EXISTS `_smsdelivery` (
	`id` INT NOT NULL AUTO_INCREMENT,
	`sms_id` INT NOT NULL,
	`personid` INT NULL DEFAULT NULL COMMENT 'Person ID of the recipient, or NULL if sent to a raw mobile number',
	`remote_id` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Upstream SMS provider message ID for this recipient',
	`raw_response` TEXT NULL DEFAULT NULL COMMENT 'Raw upstream provider response for this recipient (send response or status query)',
	`body` TEXT NULL DEFAULT NULL COMMENT 'Expanded message body for this recipient, or NULL if the batch message was used',
	`provider` ENUM('5centsmsv5','5centsmsv4','cellcastv1','mobilemessagev1') NULL DEFAULT NULL COMMENT 'Upstream SMS API (company + version) that sent this delivery, so raw_response can be interpreted; NULL if unknown',
	`delivered_at` DATETIME NULL DEFAULT NULL COMMENT 'When delivery was confirmed: the carrier timestamp when the provider reports one, else when a status check first saw it delivered',
	`status` ENUM('queued','sent','delivered','failed','in-progress','scheduled','cancelled','sending','test-message','unknown') NOT NULL DEFAULT 'sending' COMMENT 'Delivery status',
	`segments_used` INT NULL DEFAULT NULL COMMENT 'SMS segments charged, as reported by the provider; NULL if not reported',
	`per_segment_cost` DECIMAL(10,5) NULL DEFAULT NULL COMMENT 'Dollars per segment at send time (provider-reported, else SMS_SEGMENT_COST or provider default)',
	`cost_bearer` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1 COMMENT 'TRUE if this row bears the segments/cost its (possibly shared) message was charged; 0 on the secondary persons of a shared number',
	PRIMARY KEY (`id`),
	INDEX `sms_id` (`sms_id`),
	INDEX `personid` (`personid`),
	INDEX `remote_id` (`remote_id`),
	CONSTRAINT `smsdelivery_ibfk_1` FOREIGN KEY (`sms_id`) REFERENCES `sms` (`id`) ON DELETE CASCADE,
	CONSTRAINT `smsdelivery_ibfk_2` FOREIGN KEY (`personid`) REFERENCES `_person` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Restate _smsdelivery.delivered_at's comment for installs that created the
-- table from an earlier revision of this file. Idempotent: re-applying the same
-- definition is a no-op. MODIFY COLUMN must restate the whole definition.
ALTER TABLE `_smsdelivery` MODIFY COLUMN `delivered_at` DATETIME NULL DEFAULT NULL COMMENT 'When delivery was confirmed: the carrier timestamp when the provider reports one, else when a status check first saw it delivered';

-- smsdelivery.provider holds the upstream API *and version* (SmsProvider::getKey()),
-- so raw_response can always be interpreted; see
-- docs/docs/developer/reference/sms/database.mdx "Provider column".
--
-- Shared mobile numbers: when several recipients share one number,
-- DeduplicatingSmsProvider sends one gateway message for the group and
-- DbLogging writes one smsdelivery row per person, all sharing the group's
-- remote_id. Only the group's primary row bears the charge (segments_used as
-- reported); the other rows record segments_used = 0 and cost_bearer = 0, so
-- the Messages page's cost sums count the message once, and status polls guard
-- their cost updates on cost_bearer.


-- Join table associating Notes and SMSes created together
CREATE TABLE IF NOT EXISTS `sms_note` (
	`note_personid` INT NOT NULL COMMENT 'Person ID of the note owner',
	`note_id` INT NOT NULL COMMENT 'Note ID from _abstract_note / person_note',
	`smsdelivery_id` INT NOT NULL COMMENT 'smsdelivery ID of the associated SMS delivery',
	PRIMARY KEY (`note_personid`, `note_id`, `smsdelivery_id`),
	INDEX `smsdelivery_id` (`smsdelivery_id`),
	CONSTRAINT `sms_note_ibfk_1` FOREIGN KEY (`note_personid`, `note_id`) REFERENCES `person_note` (`personid`, `id`) ON DELETE CASCADE,
	CONSTRAINT `sms_note_ibfk_2` FOREIGN KEY (`smsdelivery_id`) REFERENCES `_smsdelivery` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- smsdelivery: the deliveries the current user may see, as `person` does for
-- _person: those to persons visible through the (restricted) person view, plus
-- those to raw numbers. Pages read this; writers and system-level code
-- (logging, status caching, history sync, CLI) use _smsdelivery. Recreate it
-- after adding columns to _smsdelivery (SELECT * is expanded when created).
CREATE OR REPLACE VIEW `smsdelivery` AS
	SELECT sd.* FROM `_smsdelivery` sd
	WHERE sd.personid IS NULL OR sd.personid IN (SELECT p.id FROM person p);

-- Table for persisting sender number registrations (e.g. Cellcast).
-- DbLoggingSmsProvider reads/writes here so registration survives sessions.
-- personid: the person the number is verified for. A number belongs to the
-- first person verified for it, so a user who sets their own mobile to
-- someone else's verified number can't send as them (NULL: not yet claimed).
CREATE TABLE IF NOT EXISTS sms_registered_sender (
    phone VARCHAR(20) PRIMARY KEY,
    personid INT NULL DEFAULT NULL COMMENT 'Person the number is verified for (first person wins)',
    registered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT sms_registered_sender_person FOREIGN KEY (personid) REFERENCES _person (id) ON DELETE CASCADE
) ENGINE=InnoDB;



-- Now to update Settings.
-- First delete all blank settings of the deprecated 5centSMSv4 provider. They are still settable in conf.php, or in the config page if used, but from the config page we only want v5 settings visible.
DELETE FROM setting WHERE (symbol LIKE 'SMS_HTTP_%' or symbol='SMS_RECIPIENT_ARRAY_PARAMETER') AND (value = '' OR value IS NULL);
-- Delete only values that equal the code default, so the effective behaviour is
-- unchanged. '+61' is a value admins entered (the 2.39 note said "eg +61") and
-- is not the default: deleting it would strip the '+' from migrated template
-- gateways' international numbers.
DELETE FROM setting WHERE symbol='SMS_INTERNATIONAL_PREFIX' and value='61';
DELETE FROM setting WHERE symbol='SMS_LOCAL_PREFIX' and value='0';

-- Reorder all SMS settings into logical groups matching the send-page workflow:
--   Sender → Message body → Behaviour → Debugging → Provider (set once, last)
--
-- We UPDATE existing settings to their new ranks first, then INSERT IGNORE
-- any settings that are new in 2.40.  Both operations are idempotent.
SET @sms_base = (SELECT MIN(`rank`) FROM setting WHERE symbol LIKE 'SMS_%');

-- Clear old headings; the first setting below gets the heading.
UPDATE setting SET heading='' WHERE symbol LIKE 'SMS_%';

-- =========================================================================
-- Group 1: Sender — who the message appears to come from
-- (Matches the "From" dropdown at the top of the SMS send page.)
-- =========================================================================

-- =========================================================================
-- Group 2: Message body — limits on what the user can type
-- =========================================================================
UPDATE setting SET `rank` = @sms_base + 15 WHERE symbol = 'SMS_MAX_LENGTH';
UPDATE setting SET `rank` = @sms_base + 20 WHERE symbol = 'SMS_UNICODE_PERMITTED';

-- =========================================================================
-- Group 4: Debugging / troubleshooting / low-balance alerts
-- =========================================================================
UPDATE setting SET `rank` = @sms_base + 45 WHERE symbol = 'SMS_SEND_LOGFILE';

-- =========================================================================
-- Group 5: Provider — gateway configuration (set once, rarely changed)
-- =========================================================================
UPDATE setting SET `rank` = @sms_base + 80 WHERE symbol = 'SMS_HTTP_URL';
UPDATE setting SET `rank` = @sms_base + 85 WHERE symbol = 'SMS_HTTP_HEADER_TEMPLATE';
UPDATE setting SET `rank` = @sms_base + 90 WHERE symbol = 'SMS_HTTP_POST_TEMPLATE';
UPDATE setting SET `rank` = @sms_base + 95 WHERE symbol = 'SMS_HTTP_RESPONSE_ERROR_REGEX';
UPDATE setting SET `rank` = @sms_base + 100 WHERE symbol = 'SMS_HTTP_RESPONSE_OK_REGEX';
UPDATE setting SET `rank` = @sms_base + 105 WHERE symbol = 'SMS_RECIPIENT_ARRAY_PARAMETER';
UPDATE setting SET `rank` = @sms_base + 110 WHERE symbol = 'SMS_LOCAL_PREFIX';
UPDATE setting SET `rank` = @sms_base + 115 WHERE symbol = 'SMS_INTERNATIONAL_PREFIX';

-- Insert any settings that are new in 2.40 (idempotent via INSERT IGNORE).
-- Existing settings already got their rank updated above.
INSERT IGNORE INTO `setting` (`symbol`, `type`, `value`, `note`, `rank`)
VALUES
	('SMS_SENDER', 'text', '', 'Hardcoded sender ID or mobile number that SMSes will appear to come from', @sms_base),
	('SMS_SENDER_OPTIONS', 'text', '_USER_MOBILE_,_SENDER_IDS_', 'Sender options for the SMS dropdown. Tokens: _USER_MOBILE_ (user''s mobile), _SENDER_IDS_ (upstream-registered IDs). Other entries are literal IDs. Blank = default _USER_MOBILE_,_SENDER_IDS_.', @sms_base + 5),
	('SMS_SENDER_DEFAULT', 'text', '', 'Default sender selected in the SMS sender dropdown. Use _USER_MOBILE_ to indicate the current user''s mobile.', @sms_base + 10),
	('SMS_TESTMODE', 'bool', false, 'In Test Mode no SMSes are actually sent', @sms_base + 35),
	('SMS_VERBOSE', 'bool', false, 'Log HTTP requests to upstream SMS provider', @sms_base + 40),
	('SMS_SEGMENT_COST', 'text', '', 'Your price per SMS segment in dollars, e.g. 0.029. Used for the compose cost estimate, and recorded with each new send when the provider does not report its own price (Cellcast does). Blank = provider default.', @sms_base + 44),
	('SMS_BALANCE_LOW_THRESHOLD', 'int', '0', 'Notify a staff member when SMS account balance drops below this amount (0 = disabled)', @sms_base + 50),
	('SMS_BALANCE_LOW_NOTIFICANT_EMAIL', 'text', '', 'Email address to notify when SMS balance is low', @sms_base + 55),
	('SMS_PROVIDER', 'select{"":"Auto-detect (based on settings)","5centsmsv5":"5CentSMS v5","cellcast":"Cellcast","mobilemessage":"MobileMessage"}', '', 'Which SMS provider to use for sending', @sms_base + 60),
	('SMS_5CENTSMS_APIKEY_ID', 'text', '', 'FiveCent SMS v5 API key ID', @sms_base + 65),
	('SMS_5CENTSMS_APIKEY', 'text', '', 'FiveCent SMS v5 API key secret', @sms_base + 70),
	('SMS_CELLCAST_APIKEY', 'text', '', 'Cellcast API bearer token', @sms_base + 75),
	('SMS_MOBILEMESSAGE_APIKEY_USERNAME', 'text', '', 'MobileMessage API username', @sms_base + 80),
	('SMS_MOBILEMESSAGE_APIKEY_PASSWORD', 'text', '', 'MobileMessage API password', @sms_base + 85),
	('SMS_SEND_COOLOFF', 'int', '0', 'Undo window: seconds an immediate SMS is held, cancellable, before dispatch. 0 (default) disables it. 5CentSMS v5, Cellcast: any value. MobileMessage: at least 90 (API sends 60s-ahead schedules immediately). Others, 2FA, reminders: immediate.', @sms_base + 33),
	('SMS_SHORTEN_URLS', 'bool', false, 'Whether the ''Shorten URLs'' checkbox is checked by default when sending an SMS', @sms_base + 28),
	('SMS_UNICODE_PERMITTED', 'select{"when_free":"When it costs nothing extra","true":"Permitted (may cost extra)","false":"Not permitted"}', 'when_free', 'Whether to permit emojis and other unicode in SMSes', @sms_base + 20);

UPDATE setting SET note = 'Character limit for SMS messages sent from the web UI (scripts such as roster reminders are not limited). SMS is billed per 160 chars per person, so this is best set to 160 (1x), 306 (2x), 459 (3x), 612 (4x), 765 (5x) etc' WHERE symbol = 'SMS_MAX_LENGTH';

INSERT IGNORE INTO setting
	(`rank`, heading, symbol, note, type, value)
VALUES
	(@sms_base + 150, 'URL Shortening', 'URLSHORTENER_API_KEY', 'API key for the jethro.au URL shortener, used in SMSes', 'text', '');
-- Heading on the first setting in the new order.
UPDATE setting SET heading = 'SMS Gateway' WHERE symbol = 'SMS_SENDER';

-- SMS is now a 'feature' explicitly turned on or off, rather than its enabled'ness being inferred by the SMS_HTTP_URL setting.
-- Turn it on by default, as we can't reliably tell the value of SMS_HTTP_URL / SMS_5CENTSMS_URL here, and 'on' means nothing if those aren't set anyway.
UPDATE `setting` SET 
  `type`  = REPLACE(`type`, '}', ',"SMS":"SMS"}'),
  `value` = CONCAT(`value`, ',SMS')
WHERE `symbol` = 'ENABLED_FEATURES'
AND `type` NOT LIKE '%"SMS"%';

-- In the past a major reason to 'Save as Note' was purely record-keeping. But now that SMSes are always saved (the 'Messages' tab), the only reason to "Save to Note" is if you actually intend to follow up on the SMS somehow. Thus the default note subject changes from generic 'SMS Sent' to 'SMS follow-up'.
-- Empty values are included: older Jethro substituted 'SMS Sent' for an empty subject, so '' meant the default too.
-- 2026-upgrade-to-2.40-sms.sql runs after this and reads the pre-upgrade subject
-- from the setting's value when it does not match, so keep the two in step.
UPDATE setting
SET `value` = 'SMS follow-up'
WHERE symbol = 'SMS_SAVE_TO_NOTE_SUBJECT'
  AND `value` IN ('SMS Sent', '');

-- Since 1 July 2026 the ACMA shows an unregistered alphanumeric sender ID to
-- recipients as 'Unverified', so 'Jethro' is no longer a sensible 2FA sender.
-- Blank now means: the literal SMS_SENDER, else the first ACMA-approved sender
-- ID the provider reports (see User_System::resolve2FASender()). Leave a value
-- an admin has chosen since (e.g. their own registered ID) alone.
UPDATE setting
SET `value` = ''
WHERE symbol = '2FA_SENDER_ID'
  AND `value` = 'Jethro';
UPDATE setting
SET note = 'ACMA-registered sender ID for 2FA messages; blank = literal SMS_SENDER, else the first ACMA-approved sender ID at your provider'
WHERE symbol = '2FA_SENDER_ID';

-- The new PERM_VIEWSMS permission (bitmask 524288) grants permission to see past
-- messages sent from any sender. Before this upgrade, SMSes were only recorded as
-- notes, visible to holders of 'Notes - view all' (PERM_VIEWNOTE, bitmask 48).
-- To avoid exposing those messages more widely, existing installations grant
-- PERM_VIEWSMS only where PERM_SENDSMS (2) and PERM_VIEWNOTE (48) are both held.
-- (New installations get PERM_VIEWSMS in DEFAULT_PERMISSIONS unconditionally; see
-- include/installer.class.php.)
--
-- Bit 524288 was previously unused but already set in the stock DEFAULT_PERMISSIONS
-- (7995391), so many existing accounts carry it. The bit is therefore set *or
-- cleared* to match the rule, rather than only OR'd in.
--
-- It runs once. Re-running after an admin has granted PERM_VIEWSMS by hand
-- would clear those grants again, so a marker setting records that it has run.
-- MySQL has no constants; session variables mirror include/permission_levels.php.
SET @PERM_SENDSMS = 2;
SET @PERM_VIEWNOTE = 48;
SET @PERM_VIEWSMS = 524288;
SET @PERM_SYSADMIN = 2147483647;
SET @perm_grant_pending = (SELECT COUNT(*) = 0 FROM setting WHERE symbol = 'SMS_VIEWSMS_GRANTED');

UPDATE setting
SET value = IF((CAST(value AS UNSIGNED) & @PERM_SENDSMS) = @PERM_SENDSMS AND (CAST(value AS UNSIGNED) & @PERM_VIEWNOTE) = @PERM_VIEWNOTE,
               CAST(value AS UNSIGNED) | @PERM_VIEWSMS,
               CAST(value AS UNSIGNED) & ~@PERM_VIEWSMS)
WHERE symbol = 'DEFAULT_PERMISSIONS'
  AND @perm_grant_pending;

UPDATE staff_member
SET permissions = IF((permissions & @PERM_SENDSMS) = @PERM_SENDSMS AND (permissions & @PERM_VIEWNOTE) = @PERM_VIEWNOTE,
                     permissions | @PERM_VIEWSMS,
                     permissions & ~@PERM_VIEWSMS)
WHERE permissions <> @PERM_SYSADMIN
  AND @perm_grant_pending;

INSERT IGNORE INTO setting (symbol, type, value, note, `rank`)
VALUES ('SMS_VIEWSMS_GRANTED', 'hidden', '1',
        'Internal: the one-off SMS-view-all grant for existing staff has run', 0);



-- =========================================================================
-- Remove legacy SMS_SEND_LOGFILE setting — logToFile() has been deleted;
-- the SMS send history is now in the sms + smsdelivery DB tables and the
-- Person → Messages section. To regenerate the legacy log format, use:
--   HTTP_HOST=example.org php ./scripts/sms.php export-smslog
-- Idempotent: DELETE WHERE returns 0 rows if already deleted.
DELETE FROM setting WHERE symbol = 'SMS_SEND_LOGFILE';


-- =========================================================================
-- Self-service staff password reset (see docs/docs/developer/reference/password-reset.mdx)
--
-- Per-account reset request and username-reminder state on staff_member, written
-- by raw SQL in User_System (kept out of the ORM like _person.resethash for member resets):
--   resethash         bcrypt hash of the single-use emailed token (never the token itself)
--   resetexpires      when the request stops being usable (24h after it was armed)
--   resetsmshash      bcrypt hash of the current SMS code (never the code itself)
--   resetsmssent      last reserved SMS send, used for the 60s cooldown and 10m code expiry
--   resetsmsattempts  total SMS codes tried against the current request (2 allowed)
--   usernamereminded  when a "forgot username" reminder last went out (1 per hour)
-- Every reset request requires an SMS code, so there is no separate requirement flag.
-- Column definitions must match db_objects/staff_member.class.php getInitSQL().
--
-- Idempotent on both MariaDB and MySQL: MySQL has no "ADD COLUMN IF NOT EXISTS"
-- (MariaDB-only), so build one ALTER from whichever of the columns
-- information_schema reports as missing. CONCAT_WS skips the NULL entries, and
-- an empty list is a no-op. Same approach as the collation ALTER above.
SET @staff_reset_missing = CONCAT_WS(', ',
	IF((SELECT COUNT(*) FROM information_schema.columns
		WHERE table_schema = DATABASE() AND table_name = 'staff_member' AND column_name = 'resethash') = 0,
		'ADD COLUMN `resethash` VARCHAR(255) DEFAULT NULL', NULL),
	IF((SELECT COUNT(*) FROM information_schema.columns
		WHERE table_schema = DATABASE() AND table_name = 'staff_member' AND column_name = 'resetexpires') = 0,
		'ADD COLUMN `resetexpires` DATETIME DEFAULT NULL', NULL),
	IF((SELECT COUNT(*) FROM information_schema.columns
		WHERE table_schema = DATABASE() AND table_name = 'staff_member' AND column_name = 'resetsmshash') = 0,
		'ADD COLUMN `resetsmshash` VARCHAR(255) DEFAULT NULL', NULL),
	IF((SELECT COUNT(*) FROM information_schema.columns
		WHERE table_schema = DATABASE() AND table_name = 'staff_member' AND column_name = 'resetsmssent') = 0,
		'ADD COLUMN `resetsmssent` DATETIME DEFAULT NULL', NULL),
	IF((SELECT COUNT(*) FROM information_schema.columns
		WHERE table_schema = DATABASE() AND table_name = 'staff_member' AND column_name = 'resetsmsattempts') = 0,
		'ADD COLUMN `resetsmsattempts` TINYINT UNSIGNED NOT NULL DEFAULT 0', NULL),
	IF((SELECT COUNT(*) FROM information_schema.columns
		WHERE table_schema = DATABASE() AND table_name = 'staff_member' AND column_name = 'usernamereminded') = 0,
		'ADD COLUMN `usernamereminded` DATETIME DEFAULT NULL', NULL)
);
SET @staff_reset_sql = IF(@staff_reset_missing = '', 'DO 0', CONCAT('ALTER TABLE staff_member ', @staff_reset_missing));
PREPARE staff_reset_stmt FROM @staff_reset_sql;
EXECUTE staff_reset_stmt;
DEALLOCATE PREPARE staff_reset_stmt;

-- Sensitive custom fields: hidden from users without DATA_PERM_VIEWSENSITIVE.
-- Idempotent; MySQL 8 lacks ADD COLUMN IF NOT EXISTS.
SET @missing = (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'custom_field' AND COLUMN_NAME = 'sensitive');
SET @stmt = IF(@missing,
    'ALTER TABLE `custom_field` ADD COLUMN `sensitive` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0',
    'SELECT 1');
PREPARE stmt FROM @stmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =========================================================================
-- Viewable person fields: a data-visibility mask (staff_member.data_permissions),
-- separate from the function permissions. Bits mirror include/data_permissions.php:
--   address 134217728, email 536870912, mobile 268435456,
--   contact remarks 33554432, custom fields 67108864, sensitive custom fields 16777216.
-- The person and family views are defined near the top of this script.

-- Custom field values are read through a permission-filtered view so a
-- sensitive field's values are hidden even if a caller forgets the PHP check.
-- Rename the base table once, then expose only visible fields through the view.
SET @custom_field_value_is_table = (SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'custom_field_value'
      AND TABLE_TYPE = 'BASE TABLE');
SET @custom_field_value_base_exists = (SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '_custom_field_value');
SET @stmt = IF(@custom_field_value_is_table = 1 AND @custom_field_value_base_exists = 0,
    'RENAME TABLE `custom_field_value` TO `_custom_field_value`',
    'SELECT 1');
PREPARE stmt FROM @stmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- RENAME TABLE keeps the pre-rename constraint and index names
-- (custom_field_valuepersonid, ...). A fresh install gets the names
-- Custom_Field_Value::getForeignKeys() declares (_custom_field_value<column>),
-- so rename them: scripts that add/drop foreign keys by name must behave the
-- same on both kinds of site. Guarded so a re-run (or a fresh install, which
-- already has the new names) is a no-op.
SET @stmt = IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = '_custom_field_value'
      AND CONSTRAINT_NAME = 'custom_field_valuepersonid' AND CONSTRAINT_TYPE = 'FOREIGN KEY') = 1,
    'ALTER TABLE `_custom_field_value` DROP FOREIGN KEY `custom_field_valuepersonid`, DROP INDEX `custom_field_valuepersonid`, ADD INDEX `_custom_field_valuepersonid` (`personid`), ADD CONSTRAINT `_custom_field_valuepersonid` FOREIGN KEY (`personid`) REFERENCES `_person` (`id`) ON DELETE CASCADE',
    'SELECT 1');
PREPARE stmt FROM @stmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @stmt = IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = '_custom_field_value'
      AND CONSTRAINT_NAME = 'custom_field_valuefieldid' AND CONSTRAINT_TYPE = 'FOREIGN KEY') = 1,
    'ALTER TABLE `_custom_field_value` DROP FOREIGN KEY `custom_field_valuefieldid`, DROP INDEX `custom_field_valuefieldid`, ADD INDEX `_custom_field_valuefieldid` (`fieldid`), ADD CONSTRAINT `_custom_field_valuefieldid` FOREIGN KEY (`fieldid`) REFERENCES `custom_field` (`id`) ON DELETE CASCADE',
    'SELECT 1');
PREPARE stmt FROM @stmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @stmt = IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = '_custom_field_value'
      AND CONSTRAINT_NAME = 'custom_field_valuevalue_optionid' AND CONSTRAINT_TYPE = 'FOREIGN KEY') = 1,
    'ALTER TABLE `_custom_field_value` DROP FOREIGN KEY `custom_field_valuevalue_optionid`, DROP INDEX `custom_field_valuevalue_optionid`, ADD INDEX `_custom_field_valuevalue_optionid` (`value_optionid`), ADD CONSTRAINT `_custom_field_valuevalue_optionid` FOREIGN KEY (`value_optionid`) REFERENCES `custom_field_option` (`id`) ON DELETE CASCADE',
    'SELECT 1');
PREPARE stmt FROM @stmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE OR REPLACE VIEW `custom_field_value` AS
SELECT v.id, v.personid, v.fieldid, v.value_text, v.value_date, v.value_optionid
FROM `_custom_field_value` v
JOIN `custom_field` f ON f.id = v.fieldid
WHERE currentUserHasDataPerm('VIEWCUSTOMFIELDS')
  AND (f.sensitive = 0 OR currentUserHasDataPerm('VIEWSENSITIVE'));

-- The installer (and Staff_Member::_getFields()'s DEFAULT_DATA_PERMISSIONS
-- fallback) creates this column with DEFAULT 1040187392. Keep the same default
-- here so a staff row inserted outside Staff_Member gets viewable fields on an
-- upgraded site too.
SET @missing = (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_member' AND COLUMN_NAME = 'data_permissions');
SET @stmt = IF(@missing,
    'ALTER TABLE `staff_member` ADD COLUMN `data_permissions` varchar(255) NOT NULL DEFAULT ''1040187392'' AFTER `active`',
    'SELECT 1');
PREPARE stmt FROM @stmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Databases that already ran an earlier 2.40 migration have the column with
-- DEFAULT '0' and, because ADD COLUMN appends, after `permissions`. The guarded
-- ADD COLUMN above will not touch them, so converge on the installer's
-- definition and ordinal position here. Modifying to the definition it already
-- has is a no-op, so this stays idempotent.
ALTER TABLE `staff_member` MODIFY COLUMN `data_permissions` varchar(255) NOT NULL DEFAULT '1040187392' AFTER `active`;

-- The installer (include/installer.class.php) inserts DEFAULT_PERMISSIONS,
-- DEFAULT_DATA_PERMISSIONS and DATA_PERMISSIONS_MIGRATED in one sequence with
-- @rank+5 between each. Derive the same ranks so an upgraded database matches a
-- fresh install exactly; the UPDATEs converge databases that ran the original
-- migration (DEFAULT_PERMISSIONS +1, and marker rank 1001).
SET @default_data_permissions_rank = COALESCE(
    (SELECT `rank` FROM setting WHERE symbol = 'DEFAULT_PERMISSIONS' LIMIT 1), 16) + 5;
INSERT IGNORE INTO setting (`rank`, heading, symbol, note, type, value)
VALUES (@default_data_permissions_rank, '', 'DEFAULT_DATA_PERMISSIONS', 'Viewable person fields to grant to new user accounts by default', 'int', '1040187392');
UPDATE setting SET `rank` = @default_data_permissions_rank
WHERE symbol = 'DEFAULT_DATA_PERMISSIONS' AND `rank` <> @default_data_permissions_rank;

-- Existing staff keep seeing everything they saw before: grant the default
-- viewable fields (all but sensitive custom fields, which are new and opt-in).
-- OR'd in, and run once: a marker stops a re-run from restoring access an
-- admin has since revoked.
SET @data_permissions_grant_pending = (SELECT COUNT(*) = 0 FROM setting WHERE symbol = 'DATA_PERMISSIONS_MIGRATED');
UPDATE staff_member
SET data_permissions = CAST(data_permissions AS UNSIGNED) | 1040187392
WHERE @data_permissions_grant_pending;
SET @data_permissions_migrated_rank = @default_data_permissions_rank + 5;
INSERT IGNORE INTO setting (`rank`, heading, symbol, note, type, value)
VALUES (@data_permissions_migrated_rank, '', 'DATA_PERMISSIONS_MIGRATED', 'Internal: the one-off viewable-person-fields grant for existing staff has run', 'hidden', '1');
UPDATE setting SET `rank` = @data_permissions_migrated_rank
WHERE symbol = 'DATA_PERMISSIONS_MIGRATED' AND `rank` <> @data_permissions_migrated_rank;

-- Expose the sendmail transport alternative on the System Configuration page.
-- Synced with include/installer.class.php and include/config_manager.class.php
-- (isFileOnlySetting). Idempotent.
INSERT IGNORE INTO setting (`rank`, heading, symbol, note, type, value)
SELECT `rank` + 1, '', 'SENDMAIL_COMMAND',
    'Send mail via this sendmail-compatible command instead of SMTP_SERVER. Include -bs to speak SMTP over the pipe.',
    'text', ''
FROM setting
WHERE symbol = 'SMTP_SERVER';
