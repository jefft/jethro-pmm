-- Set flag causing the home page to warn sysadmin users that the database charset needs upgrading
INSERT IGNORE INTO setting
(symbol, type, value, note)
VALUES ('NEEDS_UTF8MB4_UPGRADE', 'hidden', "1", "Whether the database charset upgrade to utf8mb4 needs to be run");
--
-- SMS database tables
-- sms:          one row per send operation (body text, sender, timing)
-- smsdelivery:  one row per recipient (person, remote ref, delivery status)

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
-- JOINs in the migration script don't fail with collation mismatch errors.
-- Idempotent: when collations already match, the ALTER is a no-op.
SET @target_collation = (
	SELECT collation_name FROM information_schema.columns
	WHERE table_schema = DATABASE() AND table_name = '_abstract_note' AND column_name = 'details'
);
SET @sql = CONCAT('ALTER TABLE sms MODIFY COLUMN body TEXT COLLATE ', @target_collation);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `smsdelivery` (
	`id` INT NOT NULL AUTO_INCREMENT,
	`sms_id` INT NOT NULL,
	`personid` INT NULL DEFAULT NULL COMMENT 'Person ID of the recipient, or NULL if sent to a raw mobile number',
	`remote_id` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Upstream SMS provider message ID for this recipient',
	`raw_response` TEXT NULL DEFAULT NULL COMMENT 'Raw upstream provider response for this recipient (send response or status query)',
	`body` TEXT NULL DEFAULT NULL COMMENT 'Expanded message body for this recipient, or NULL if the batch message was used',
	`provider` ENUM('5centsmsv5','cellcast','smsbroadcast','5csmsv4') NULL DEFAULT NULL COMMENT 'SMS provider that sent this delivery',
	`delivered_at` DATETIME NULL DEFAULT NULL COMMENT 'When the carrier confirmed delivery (from upstream status check)',
	`status` ENUM('queued','sent','delivered','failed','in-progress','scheduled','cancelled','sending','test-message','unknown') NOT NULL DEFAULT 'sending' COMMENT 'Delivery status',
	`segments_used` INT NULL DEFAULT NULL COMMENT 'SMS segments charged, as reported by the provider; NULL if not reported',
	`per_segment_cost` DECIMAL(10,5) NULL DEFAULT NULL COMMENT 'Dollars per segment at send time (provider-reported, else SMS_SEGMENT_COST or provider default)',
	PRIMARY KEY (`id`),
	INDEX `sms_id` (`sms_id`),
	INDEX `personid` (`personid`),
	INDEX `remote_id` (`remote_id`),
	CONSTRAINT `smsdelivery_ibfk_1` FOREIGN KEY (`sms_id`) REFERENCES `sms` (`id`) ON DELETE CASCADE,
	CONSTRAINT `smsdelivery_ibfk_2` FOREIGN KEY (`personid`) REFERENCES `_person` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Rename 5csmsv5 → 5centsmsv5 in the ENUM and existing rows.  Idempotent:
-- the INSERT IGNORE trick only runs the statement when it wouldn't error.
SET @old = (SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'smsdelivery'
    AND COLUMN_NAME = 'provider' AND COLUMN_TYPE LIKE '%5csmsv5%'
    AND COLUMN_TYPE NOT LIKE '%5centsmsv5%');
SET @stmt = IF(@old = 1,
    'ALTER TABLE `smsdelivery` MODIFY COLUMN `provider` ENUM(''5centsmsv5'',''5csmsv5'',''cellcast'',''smsbroadcast'',''5csmsv4'') NULL DEFAULT NULL',
    'SELECT 1');
PREPARE stmt FROM @stmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
UPDATE IGNORE `smsdelivery` SET `provider` = '5centsmsv5' WHERE `provider` = '5csmsv5';

-- Cost tracking: add segments_used / per_segment_cost to smsdelivery tables
-- created by earlier 2.40 dev builds.  Idempotent; works on MySQL 8, which
-- lacks ADD COLUMN IF NOT EXISTS.
SET @missing = (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'smsdelivery' AND COLUMN_NAME = 'segments_used');
SET @stmt = IF(@missing,
    'ALTER TABLE `smsdelivery`
        ADD COLUMN `segments_used` INT NULL DEFAULT NULL COMMENT ''SMS segments charged, as reported by the provider; NULL if not reported'',
        ADD COLUMN `per_segment_cost` DECIMAL(10,5) NULL DEFAULT NULL COMMENT ''Dollars per segment at send time (provider-reported, else SMS_SEGMENT_COST or provider default)''',
    'SELECT 1');
PREPARE stmt FROM @stmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Join table associating Notes and SMSes created together
CREATE TABLE IF NOT EXISTS `sms_note` (
	`note_personid` INT NOT NULL COMMENT 'Person ID of the note owner',
	`note_id` INT NOT NULL COMMENT 'Note ID from _abstract_note / person_note',
	`smsdelivery_id` INT NOT NULL COMMENT 'smsdelivery ID of the associated SMS delivery',
	PRIMARY KEY (`note_personid`, `note_id`, `smsdelivery_id`),
	INDEX `smsdelivery_id` (`smsdelivery_id`),
	CONSTRAINT `sms_note_ibfk_1` FOREIGN KEY (`note_personid`, `note_id`) REFERENCES `person_note` (`personid`, `id`) ON DELETE CASCADE,
	CONSTRAINT `sms_note_ibfk_2` FOREIGN KEY (`smsdelivery_id`) REFERENCES `smsdelivery` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Table for persisting sender number registrations (e.g. Cellcast).
-- DbLoggingSmsProvider reads/writes here so registration survives sessions.
CREATE TABLE IF NOT EXISTS sms_registered_sender (
    phone VARCHAR(20) PRIMARY KEY,
    registered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;



-- Now to update Settings.
-- First delete all blank settings of the deprecated 5centSMSv4 provider. They are still settable in conf.php, or in the config page if used, but from the config page we only want v5 settings visible.
DELETE FROM setting WHERE (symbol LIKE 'SMS_HTTP_%' or symbol='SMS_RECIPIENT_ARRAY_PARAMETER') AND (value = '' OR value IS NULL);
DELETE FROM setting WHERE symbol='SMS_INTERNATIONAL_PREFIX' and value='+61';
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
-- Group 3: Behaviour — post-send actions
-- =========================================================================

-- =========================================================================
-- Group 3: Behaviour — post-send actions
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
	('SMS_SENDER_OPTIONS', 'text', '_SENDER_IDS_,_USER_MOBILE_', 'Sender options for the SMS dropdown. Tokens: _USER_MOBILE_ (user''s mobile), _SENDER_IDS_ (upstream-registered IDs). Other entries are literal IDs. Blank = default _SENDER_IDS_,_USER_MOBILE_.', @sms_base + 5),
	('SMS_SENDER_DEFAULT', 'text', '', 'Default sender selected in the SMS sender dropdown. Use _USER_MOBILE_ to indicate the current user''s mobile.', @sms_base + 10),
	('SMS_TESTMODE', 'bool', false, 'In Test Mode no SMSes are actually sent', @sms_base + 35),
	('SMS_VERBOSE', 'bool', false, 'Log HTTP requests to upstream SMS provider', @sms_base + 40),
	('SMS_SEGMENT_COST', 'text', '', 'Your price per SMS segment in dollars, e.g. 0.029. Used for the compose cost estimate, and recorded with each new send when the provider does not report its own price (Cellcast does). Blank = provider default.', @sms_base + 44),
	('SMS_BALANCE_LOW_THRESHOLD', 'int', '0', 'Notify a staff member when SMS account balance drops below this amount (0 = disabled)', @sms_base + 50),
	('SMS_BALANCE_LOW_NOTIFICANT', 'person', '', 'Person ID of the staff member to notify when SMS balance is low', @sms_base + 55),
	('SMS_PROVIDER', 'select{"":"Auto-detect (based on settings)","5centsmsv5":"5CentSMS v5","cellcast":"Cellcast","smsbroadcast":"SMS Broadcast"}', '', 'Which SMS provider to use for sending', @sms_base + 60),
	('SMS_5CENTSMS_APIKEY_ID', 'text', '', 'FiveCent SMS v5 API key ID', @sms_base + 65),
	('SMS_5CENTSMS_APIKEY', 'text', '', 'FiveCent SMS v5 API key secret', @sms_base + 70),
	('SMS_CELLCAST_APIKEY', 'text', '', 'Cellcast API bearer token', @sms_base + 75),
	('SMS_SEND_COOLOFF', 'int', '30', 'Undo window: seconds an immediate SMS is held, cancellable, before dispatch. 0 to disable. Only works with providers that support scheduled send + cancel (5CentSMS v5, Cellcast); ignored for 5centsmsv4.', @sms_base + 33),
	('SMS_SHORTEN_URLS', 'bool', false, 'Whether the ''Shorten URLs'' checkbox is checked by default when sending an SMS', @sms_base + 28),
	('SMS_UNICODE_PERMITTED', 'select{"when_free":"When it costs nothing extra","true":"Permitted (may cost extra)","false":"Not permitted"}', 'when_free', 'Whether to permit emojis and other unicode in SMSes', @sms_base + 20);

-- Refresh SMS_SEND_COOLOFF note on installs that already have the row (INSERT IGNORE skips them).
UPDATE setting SET note = 'Undo window: seconds an immediate SMS is held, cancellable, before dispatch. 0 to disable. Only works with providers that support scheduled send + cancel (5CentSMS v5, Cellcast); ignored for 5centsmsv4.' WHERE symbol = 'SMS_SEND_COOLOFF';

-- SMS_SHORTEN_URLS is now only the "Shorten URLs" checkbox default; refresh its note.
UPDATE setting SET note = 'Whether the ''Shorten URLs'' checkbox is checked by default when sending an SMS' WHERE symbol = 'SMS_SHORTEN_URLS';

-- SMS_SEGMENT_COST now only prices new sends and the compose estimate; refresh its note.
UPDATE setting SET note = 'Your price per SMS segment in dollars, e.g. 0.029. Used for the compose cost estimate, and recorded with each new send when the provider does not report its own price (Cellcast does). Blank = provider default.' WHERE symbol = 'SMS_SEGMENT_COST';

INSERT IGNORE INTO setting
	(`rank`, heading, symbol, note, type, value)
VALUES
	(@sms_base + 150, 'URL Shortening', 'URLSHORTENER_API_KEY', 'API key for the jethro.au URL shortener, used in SMSes', 'text', '');
-- Heading on the first setting in the new order.
UPDATE setting SET heading = 'SMS Gateway' WHERE symbol = 'SMS_SENDER';

-- Revert the 2FA_SENDER_ID → SMS_2FA_SENDER rename if a prior upgrade run
-- applied it.  The original name 2FA_SENDER_ID is preferred.
-- Idempotent: does nothing if the symbol is already 2FA_SENDER_ID.
INSERT IGNORE INTO setting (symbol, type, value, note, heading, `rank`)
    SELECT '2FA_SENDER_ID', type, value, note, heading, `rank`
    FROM setting WHERE symbol = 'SMS_2FA_SENDER';
DELETE FROM setting WHERE symbol = 'SMS_2FA_SENDER';

-- SMS is now a 'feature' explicitly turned on or off, rather than its enabled'ness being inferred by the SMS_HTTP_URL setting.
-- Turn it on by default, as we can't reliably tell the value of SMS_HTTP_URL / SMS_5CENTSMS_URL here, and 'on' means nothing if those aren't set anyway.
UPDATE `setting` SET 
  `type`  = REPLACE(`type`, '}', ',"SMS":"SMS"}'),
  `value` = CONCAT(`value`, ',SMS')
WHERE `symbol` = 'ENABLED_FEATURES'
AND `type` NOT LIKE '%"SMS"%';

-- In the past a major reason to 'Save as Note' was purely record-keeping. But now that SMSes are always saved (the 'Messages' tab), the only reason to "Save to Note" is if you actually intend to follow up on the SMS somehow. Thus the default note subject changes from generic 'SMS Sent' to 'SMS follow-up'.
-- Empty values are included: older Jethro substituted 'SMS Sent' for an empty subject, so '' meant the default too.
UPDATE setting
SET `value` = 'SMS follow-up'
WHERE symbol = 'SMS_SAVE_TO_NOTE_SUBJECT'
  AND `value` IN ('SMS Sent', '');

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
-- Idempotent: re-running yields the same result.
-- MySQL has no constants; session variables mirror include/permission_levels.php.
SET @PERM_SENDSMS = 2;
SET @PERM_VIEWNOTE = 48;
SET @PERM_VIEWSMS = 524288;
SET @PERM_SYSADMIN = 2147483647;

UPDATE setting
SET value = IF((CAST(value AS UNSIGNED) & @PERM_SENDSMS) = @PERM_SENDSMS AND (CAST(value AS UNSIGNED) & @PERM_VIEWNOTE) = @PERM_VIEWNOTE,
               CAST(value AS UNSIGNED) | @PERM_VIEWSMS,
               CAST(value AS UNSIGNED) & ~@PERM_VIEWSMS)
WHERE symbol = 'DEFAULT_PERMISSIONS';

UPDATE staff_member
SET permissions = IF((permissions & @PERM_SENDSMS) = @PERM_SENDSMS AND (permissions & @PERM_VIEWNOTE) = @PERM_VIEWNOTE,
                     permissions | @PERM_VIEWSMS,
                     permissions & ~@PERM_VIEWSMS)
WHERE permissions <> @PERM_SYSADMIN;



-- =========================================================================
-- Remove legacy SMS_SEND_LOGFILE setting — logToFile() has been deleted;
-- the SMS send history is now in the sms + smsdelivery DB tables and the
-- Person → Messages section. To regenerate the legacy log format, use:
--   HTTP_HOST=example.org php ./scripts/sms.php export-smslog
-- Idempotent: DELETE WHERE returns 0 rows if already deleted.
DELETE FROM setting WHERE symbol = 'SMS_SEND_LOGFILE';



-- 2FA click-to-verify: shared store bridging mobile (SMS link click)
-- and desktop (2FA page SSE wait).  When a 2FA code is sent, a row is
-- inserted keyed by verify_token.  The desktop SSE endpoint polls for
-- verified=1; the mobile link-click handler sets it.
CREATE TABLE IF NOT EXISTS `2fa_pending` (
	`id` INT NOT NULL AUTO_INCREMENT,
	`verify_token` VARCHAR(64) NOT NULL,
	`php_session_id` VARCHAR(128) NOT NULL,
	`expiry` DATETIME NOT NULL,
	`verified` TINYINT(1) NOT NULL DEFAULT 0,
	`created` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (`id`),
	UNIQUE KEY `verify_token` (`verify_token`),
	KEY `php_session_id` (`php_session_id`),
	KEY `expiry` (`expiry`)
) ENGINE=InnoDB;

-- Feature flag: include a clickable link in 2FA SMS messages.
-- When enabled, the SMS includes a link (e.g. ?call=2fa_verify&t=TOKEN)
-- that auto-verifies the 2FA code without typing.  Defaults to off so
-- admins opt in after ensuring BASE_URL resolves publicly.
INSERT IGNORE INTO `setting` (`symbol`, `type`, `value`, `note`, `rank`)
VALUES ('2FA_SMS_LINK', 'bool', 'false',
	'Include a clickable verification link in 2FA SMS messages',
	(SELECT COALESCE(MAX(`rank`), 0) + 1 FROM setting s2 WHERE s2.symbol LIKE '2FA_%'));

-- Give SMS_SHORTEN_URLS its own heading so the admin status panel
-- (?call=admin_statuspanel_sms_shorten_urls) is auto-discovered on the
-- system configuration page.  Idempotent.
UPDATE setting SET heading = 'SMS URL Shortening' WHERE symbol = 'URLSHORTENER_API_KEY' AND heading = '';
