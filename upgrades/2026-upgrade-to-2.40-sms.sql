-- =============================================================================
-- Migrate legacy SMS notes to sms + smsdelivery (+ sms_note)
--
-- Old system: each SMS sent created a person_note + _abstract_note record per
--   recipient, with subject='SMS Sent', details=SMS body, creator=sender.
-- New system: sms (one per send) + smsdelivery (one per recipient).
--
-- Collation alignment: sms.body is aligned to _abstract_note.details by
-- 2026-upgrade-to-2.40.sql before this script runs.
-- =============================================================================

-- =============================================================================

-- Content-integrity tracking: hash every distinct (creator, details) pair
-- from _abstract_note before migration. After migration, we verify the same
-- hashes appear in sms. If all match, no SMS content was lost.
DROP TEMPORARY TABLE IF EXISTS _upgrade_sms_hash;
CREATE TEMPORARY TABLE _upgrade_sms_hash (
    content_hash CHAR(64) NOT NULL PRIMARY KEY
) ENGINE=MEMORY;

INSERT INTO _upgrade_sms_hash (content_hash)
SELECT SHA2(CONCAT(creator, CHAR(0), details), 256)
FROM (SELECT DISTINCT creator, details FROM _abstract_note WHERE subject = 'SMS Sent') t;

-- =============================================================================

-- Step 1a: Insert sms for bulk sends.
-- One row per (sender, body) group where all notes created within 1 hour.
-- Skips groups that already have a matching sms row (idempotent).
INSERT INTO sms (body, sender, created)
SELECT an.details, an.creator, MIN(an.created)
FROM _abstract_note an
JOIN person_note pn ON an.id = pn.id
LEFT JOIN sms s ON s.body = an.details
                AND s.sender = an.creator
WHERE an.subject = 'SMS Sent'
  AND s.id IS NULL
GROUP BY an.creator, an.details
HAVING COUNT(*) > 1
  AND TIMESTAMPDIFF(HOUR, MIN(an.created), MAX(an.created)) = 0;

-- Step 1b: Insert sms for single-recipient sends.
-- Every remaining SMS Sent note (not in a bulk group above) gets its own
-- sms row. This covers: genuinely single-recipient sends, notes whose
-- (creator, details) group spans >1 hour, and notes edited to have unique
-- details.
INSERT INTO sms (body, sender, created)
SELECT an.details, an.creator, an.created
FROM _abstract_note an
JOIN person_note pn ON an.id = pn.id
LEFT JOIN sms s ON s.body = an.details
                AND s.sender = an.creator
LEFT JOIN (
    SELECT an2.creator, an2.details
    FROM _abstract_note an2
    JOIN person_note pn2 ON an2.id = pn2.id
    WHERE an2.subject = 'SMS Sent'
    GROUP BY an2.creator, an2.details
    HAVING COUNT(*) > 1
      AND TIMESTAMPDIFF(HOUR, MIN(an2.created), MAX(an2.created)) = 0
) grp ON an.creator = grp.creator AND an.details = grp.details
WHERE an.subject = 'SMS Sent'
  AND s.id IS NULL
  AND grp.creator IS NULL;

-- Step 2: Insert smsdelivery for every SMS Sent note.
-- Joins each note to its sms via (body, sender) plus a 1-hour time
-- window around the sms created time. For bulk groups, all notes
-- are within 1 hour of the earliest (MIN created). For single notes,
-- sms.created = note.created exactly.
-- GROUP BY deduplicates when the old system created multiple
-- _abstract_note rows for the same (person, SMS send). Without it,
-- the LEFT JOIN smsdelivery guard evaluates against the pre-INSERT
-- snapshot and fails to prevent duplicates within the same statement.
INSERT INTO smsdelivery (sms_id, personid, status, provider)
SELECT s.id, pn.personid, 'sent', '5csmsv4'
FROM _abstract_note an
JOIN person_note pn ON an.id = pn.id
JOIN sms s ON s.body = an.details
           AND s.sender = an.creator
           AND an.created >= s.created
           AND an.created <= s.created + INTERVAL 1 HOUR
LEFT JOIN smsdelivery sd ON sd.sms_id = s.id
                         AND sd.personid = pn.personid
WHERE an.subject = 'SMS Sent'
  AND sd.id IS NULL
GROUP BY s.id, pn.personid;

-- Step 3: Delete non-interacted notes from bulk sends.
-- These notes were purely the old system's record that an SMS was sent.
-- The sms + smsdelivery rows now serve that purpose.
-- Idempotent: deleting already-deleted rows is a no-op.
SET foreign_key_checks = 0;
-- person_note.id → _abstract_note.id FK lacks ON DELETE CASCADE, so we
-- disable FK checks to allow multi-table DELETE.
DELETE an, pn
FROM _abstract_note an
JOIN person_note pn ON an.id = pn.id
JOIN (
    SELECT an2.creator, an2.details
    FROM _abstract_note an2
    JOIN person_note pn2 ON an2.id = pn2.id
    WHERE an2.subject = 'SMS Sent'
    GROUP BY an2.creator, an2.details
    HAVING COUNT(*) > 1
      AND TIMESTAMPDIFF(HOUR, MIN(an2.created), MAX(an2.created)) = 0
) grp ON an.creator = grp.creator AND an.details = grp.details
WHERE an.subject = 'SMS Sent'
  AND an.status = 'no_action'
  AND an.assignee_last_changed IS NULL
  AND an.editor IS NULL
  AND an.edited IS NULL
  AND an.creator = an.assignee;
SET foreign_key_checks = 1;

-- Step 4: Link remaining notes (single-recipient or interacted) to their
-- send via sms_note.
-- Same time-window join as Step 2 above. Idempotent via LEFT JOIN guard.
INSERT INTO sms_note (note_personid, note_id, smsdelivery_id)
SELECT pn.personid, an.id, sd.id
FROM _abstract_note an
JOIN person_note pn ON an.id = pn.id
JOIN sms s ON s.body = an.details
           AND s.sender = an.creator
           AND an.created >= s.created
           AND an.created <= s.created + INTERVAL 1 HOUR
JOIN smsdelivery sd ON sd.sms_id = s.id AND sd.personid = pn.personid
LEFT JOIN sms_note sn ON sn.note_id = an.id
                      AND sn.note_personid = pn.personid
                      AND sn.smsdelivery_id = sd.id
WHERE an.subject = 'SMS Sent'
  AND sn.note_id IS NULL;

-- =============================================================================
-- Content-integrity verification: every (creator, details) pair from the
-- pre-migration _abstract_note should exist as a (sender, body) pair in
-- sms after migration.
-- Delete matching hashes; any remaining = content was lost.
-- =============================================================================

DELETE FROM _upgrade_sms_hash
WHERE content_hash IN (
    SELECT SHA2(CONCAT(sender, CHAR(0), body), 256)
    FROM (SELECT DISTINCT sender, body FROM sms) t
);

-- If this returns >0, SMS content was lost during migration.
-- The upgrade script will fail here with a clear message.
SELECT CASE WHEN COUNT(*) > 0
    THEN CONCAT('ERROR: ', COUNT(*), ' SMS content hashes were lost during migration!')
    ELSE 'OK: all SMS content preserved'
END AS integrity_check
FROM _upgrade_sms_hash;

DROP TEMPORARY TABLE _upgrade_sms_hash;
