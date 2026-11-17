#!/bin/bash -eu
# Verify latin1-to-utf8mb4 upgrade path.
# Uses devbox scripts for DB setup, loads latin1 dump, runs the
# real upgrade script, and checks all tables become utf8mb4.
set -o pipefail

DB=jethro_functest_upgrade_latin1
ROOT="$(dirname "$0")/../../.."
DUMP="$DEVBOX_PROJECT_ROOT/tests/functional/testdata/demodata-latin1_containing_utf8-2.38.0.sql.gz"

mariadb_recreate_db --charset=latin1 --collation=latin1_swedish_ci "$DB"
zcat "$DUMP" | mariadb_rename --database "$DB" | mariadb_as_root --default-character-set=binary "$DB"

# Mixed-table cases, while action_plan stays pure cp1252 so the plain-CONVERT
# path remains covered:
#  - first_name already holds raw UTF-8 (id=1) and gets a genuine cp1252 byte
#    (id=2): the column mixes both encodings, exercising the per-row repair.
#  - remarks is ASCII-only and gets a cp1252 byte: exercising whole-column
#    transcoding inside a table that also contains raw UTF-8.
mariadb --default-character-set=binary "$DB" -e "UPDATE _person SET first_name = UNHEX('419762'), remarks = UNHEX('419762') WHERE id = 2;"
mariadb --default-character-set=binary "$DB" -sNBe "SELECT CONCAT(HEX(first_name), ' ', HEX(remarks)) FROM _person WHERE id=2;" | grep -qx "419762 419762" \
	|| { echo "FAIL: Could not seed mixed latin1/UTF-8 rows in _person"; exit 1; }

# Pre-upgrade validation 
mariadb "$DB" -sNBe "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='$DB' AND TABLE_COLLATION LIKE 'latin1_swedish_ci';" | grep -q . \
	|| { echo "FAIL: Expected latin1 tables pre-upgrade"; exit 1; }

mariadb --database "$DB" < "$DEVBOX_PROJECT_ROOT/upgrades/2026-upgrade-to-2.39.sql"  # Set the 
mariadb --database "$DB" < "$DEVBOX_PROJECT_ROOT/upgrades/2026-upgrade-to-2.40.sql"  # Set the 
mariadb "$DB" -sNBe "select value from setting where symbol='NEEDS_UTF8MB4_UPGRADE';" | grep -x 1 || { echo "FAIL: missing upgrade marker;"; exit 1; }
