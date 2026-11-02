#!/bin/bash -eu
# Verify jr-trh.14: an upgraded database and a fresh install must end up with
# the same data-permission definitions, including the exact setting ranks and
# the order of staff_member columns, and a database that ran the original 2.40
# migration must converge on a re-run.
#
# Run inside devbox (needs mariadb, mariadb_recreate_db, mariadb_rename and
# jethro_install_db). Exits non-zero on the first difference. Uses the scratch
# databases jethro_check_dp_up, jethro_check_dp_old and jethro_check_dp_fresh,
# which it recreates.

set -o pipefail
cd "$(dirname "$(readlink -f "$0")")/../.."

if ! command -v jethro_install_db >/dev/null; then
	echo "FAIL: jethro_install_db is not on PATH; run inside devbox" >&2
	exit 2
fi

UP=jethro_check_dp_up
OLD=jethro_check_dp_old
FRESH=jethro_check_dp_fresh
tmpdir=$(mktemp -d)
trap 'rm -rf "$tmpdir"' EXIT

failures=0

sql() { # sql <db> <query>
	mariadb --batch --skip-column-names "$1" --execute="$2"
}

check() { # check <label> <expected> <actual>
	if [[ "$3" == "$2" ]]; then
		printf 'ok   %s\n' "$1"
	else
		printf 'FAIL %s: expected %s, got %s\n' "$1" "$2" "${3:-<empty>}" >&2
		failures=$((failures + 1))
	fi
}

rank() { # rank <db> <symbol>
	sql "$1" "SELECT IFNULL((SELECT \`rank\` FROM setting WHERE symbol = '$2'), 'MISSING')"
}

col_default() {
	sql "$1" "SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS
	          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_member'
	            AND COLUMN_NAME = 'data_permissions'"
}

col_order() {
	sql "$1" "SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION)
	          FROM information_schema.COLUMNS
	          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_member'"
}

fk_names() {
	sql "$1" "SELECT GROUP_CONCAT(CONSTRAINT_NAME ORDER BY CONSTRAINT_NAME)
	          FROM information_schema.TABLE_CONSTRAINTS
	          WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = '_custom_field_value'
	            AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
}

index_names() {
	sql "$1" "SELECT GROUP_CONCAT(DISTINCT INDEX_NAME ORDER BY INDEX_NAME)
	          FROM information_schema.STATISTICS
	          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '_custom_field_value'"
}

load_239() { # load_239 <db>
	mariadb_recreate_db "$1" >/dev/null
	zcat tests/functional/testdata/demodata-2.39.0.sql.gz | mariadb_rename --database "$1" | mariadb "$1"
}

apply_current() { # apply_current <db>
	local db=$1 f
	for f in upgrades/2026-upgrade-to-2.40.sql upgrades/2026-upgrade-to-2.40-ej.sql upgrades/2026-upgrade-to-2.40-sms.sql; do
		mariadb "$db" < "$f" > /dev/null
	done
}

# The columns, indexes, constraints, settings and staff rows this bug touches.
# Deliberately scoped: a whole-database fingerprint also covers an unrelated
# pre-existing SMS-settings reorder (SMS_MAX_LENGTH/SMS_UNICODE_PERMITTED use
# MIN(rank) over SMS_% while writing a rank-0 marker), which is reported on the
# epic rather than fixed here.
fingerprint() {
	mariadb "$1" -sNB <<-'SQL'
	SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, ORDINAL_POSITION
	  FROM information_schema.COLUMNS
	 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('staff_member', '_custom_field_value')
	 ORDER BY 1, 2;
	SELECT TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME, NON_UNIQUE
	  FROM information_schema.STATISTICS
	 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '_custom_field_value'
	 ORDER BY 1, 2, 3;
	SELECT TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME
	  FROM information_schema.KEY_COLUMN_USAGE
	 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '_custom_field_value'
	 ORDER BY 1, 2, 3;
	SELECT symbol, `rank`, `value` FROM setting
	 WHERE symbol IN ('DEFAULT_PERMISSIONS', 'DEFAULT_DATA_PERMISSIONS', 'DATA_PERMISSIONS_MIGRATED')
	 ORDER BY symbol;
	SELECT id, permissions, data_permissions FROM staff_member ORDER BY id;
	SQL
}

# Regress the old-upgraded clone to the state the original 2.40 migration left:
# appended column with DEFAULT '0', old FK/index names, marker ranks 1001 and
# DEFAULT_PERMISSIONS+1.
regress_old() {
	mariadb "$OLD" <<-'SQL'
	ALTER TABLE `staff_member` MODIFY `data_permissions` varchar(255) NOT NULL DEFAULT '0' AFTER `permissions`;
	SET @dp = (SELECT `rank` FROM setting WHERE symbol = 'DEFAULT_PERMISSIONS');
	UPDATE setting SET `rank` = @dp + 1 WHERE symbol = 'DEFAULT_DATA_PERMISSIONS';
	UPDATE setting SET `rank` = 1001 WHERE symbol = 'DATA_PERMISSIONS_MIGRATED';
	ALTER TABLE `_custom_field_value`
	  DROP FOREIGN KEY `_custom_field_valuepersonid`, DROP INDEX `_custom_field_valuepersonid`,
	  ADD INDEX `custom_field_valuepersonid` (`personid`),
	  ADD CONSTRAINT `custom_field_valuepersonid` FOREIGN KEY (`personid`) REFERENCES `_person` (`id`) ON DELETE CASCADE;
	ALTER TABLE `_custom_field_value`
	  DROP FOREIGN KEY `_custom_field_valuefieldid`, DROP INDEX `_custom_field_valuefieldid`,
	  ADD INDEX `custom_field_valuefieldid` (`fieldid`),
	  ADD CONSTRAINT `custom_field_valuefieldid` FOREIGN KEY (`fieldid`) REFERENCES `custom_field` (`id`) ON DELETE CASCADE;
	ALTER TABLE `_custom_field_value`
	  DROP FOREIGN KEY `_custom_field_valuevalue_optionid`, DROP INDEX `_custom_field_valuevalue_optionid`,
	  ADD INDEX `custom_field_valuevalue_optionid` (`value_optionid`),
	  ADD CONSTRAINT `custom_field_valuevalue_optionid` FOREIGN KEY (`value_optionid`) REFERENCES `custom_field_option` (`id`) ON DELETE CASCADE;
	SQL
}

compare_with_fresh() { # compare_with_fresh <db> <label>
	local db=$1 label=$2
	check "$label: data_permissions default == fresh" "$(col_default "$FRESH")" "$(col_default "$db")"
	check "$label: staff_member column order == fresh" "$(col_order "$FRESH")" "$(col_order "$db")"
	check "$label: FK names == fresh" "$(fk_names "$FRESH")" "$(fk_names "$db")"
	check "$label: index names == fresh" "$(index_names "$FRESH")" "$(index_names "$db")"
	check "$label: DEFAULT_DATA_PERMISSIONS rank == fresh" \
		"$(rank "$FRESH" DEFAULT_DATA_PERMISSIONS)" "$(rank "$db" DEFAULT_DATA_PERMISSIONS)"
	check "$label: DATA_PERMISSIONS_MIGRATED rank == fresh" \
		"$(rank "$FRESH" DATA_PERMISSIONS_MIGRATED)" "$(rank "$db" DATA_PERMISSIONS_MIGRATED)"
}

echo "== Fresh install"
mariadb_recreate_db "$FRESH" >/dev/null
jethro_install_db "$FRESH" >/dev/null

echo "== Upgrade path: 2.39 demo data + the current 2.40 SQL files"
load_239 "$UP"
apply_current "$UP"

echo "== Old-upgraded clone: regressed to the original 2.40 output, then re-upgraded"
load_239 "$OLD"
apply_current "$OLD"
regress_old
check "old clone: default is the original '0'" "'0'" "$(col_default "$OLD")"
check "old clone: marker rank is the original 1001" "1001" "$(rank "$OLD" DATA_PERMISSIONS_MIGRATED)"
check "old clone: FK names are the pre-rename names" \
	"custom_field_valuefieldid,custom_field_valuepersonid,custom_field_valuevalue_optionid" "$(fk_names "$OLD")"
apply_current "$OLD"

echo "== Comparing with the fresh install"
ddp_expected=$(( $(rank "$FRESH" DEFAULT_PERMISSIONS) + 5 ))
mig_expected=$(( ddp_expected + 5 ))
check "fresh: DEFAULT_DATA_PERMISSIONS is DEFAULT_PERMISSIONS + 5" "$ddp_expected" "$(rank "$FRESH" DEFAULT_DATA_PERMISSIONS)"
check "fresh: DATA_PERMISSIONS_MIGRATED is DEFAULT_DATA_PERMISSIONS + 5" "$mig_expected" "$(rank "$FRESH" DATA_PERMISSIONS_MIGRATED)"
compare_with_fresh "$UP" "upgraded"
compare_with_fresh "$OLD" "old-upgraded after re-run"

echo "== Idempotence: applying the current files again"
for db in "$UP" "$OLD"; do
	fingerprint "$db" > "$tmpdir/$db.before"
	apply_current "$db"
	fingerprint "$db" > "$tmpdir/$db.after"
	if diff -u "$tmpdir/$db.before" "$tmpdir/$db.after" > "$tmpdir/$db.diff"; then
		printf 'ok   %s: second application changed nothing\n' "$db"
	else
		printf 'FAIL %s: second application changed the database\n' "$db" >&2
		head -40 "$tmpdir/$db.diff" >&2
		failures=$((failures + 1))
	fi
done

echo
if [[ $failures -gt 0 ]]; then
	echo "$failures check(s) FAILED" >&2
	exit 1
fi
echo "All checks passed."
