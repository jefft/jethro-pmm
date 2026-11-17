#!/bin/bash -eu
# Verify the web latin1-to-utf8mb4 upgrade produced correct results.
#
# Covers four fixer paths against the shared corrupt-latin1 fixture:
#   1. BLOB detour   — raw UTF-8 in latin1 columns (Déññïs Dëmø, 😀 in _person)
#   2. Plain CONVERT — genuinely-cp1252 byte 0x97 in action_plan.name (id=1)
#   3. Mixed table   — _person has raw UTF-8 (id=1) and seeded cp1252 bytes
#                      (id=2 first_name is mixed within the column, remarks is
#                      cp1252-only); both must transcode without double-encoding
#   4. Row format    — ROW_FORMAT=COMPACT tables (2fa_trust) auto-upgraded to DYNAMIC
#
# The client charset is pinned so the assertions don't depend on the host locale.
set -o pipefail

DB=jethro_functest_upgrade_latin1
M=(mariadb --default-character-set=utf8mb4)

# ── Post-upgrade: all tables must be utf8mb4 ──
bad_tables=$(mariadb "$DB" -sNBe "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='$DB' AND TABLE_COLLATION NOT LIKE 'utf8mb4%';")
if [ -n "$bad_tables" ]; then
	echo "$bad_tables"
	echo
	echo "Table charsets and collations:"
	mariadb "$DB" -tBe "SELECT TABLE_NAME, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA='$DB' AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME;"
	echo "FAIL: tables not utf8mb4"
	exit 1
fi

# ── BLOB detour: person 1 should show the demo name (Déññïs Dëmø) ──
"${M[@]}" "$DB" -sNBe "SELECT CONCAT(first_name, ' ', last_name) FROM _person WHERE id=1;" | grep -q "Déññïs Dëmø" \
	|| { echo "FAIL: BLOB detour — expected Déññïs Dëmø for _person id=1"; exit 1; }

# ── BLOB detour: 4-byte emoji in _person id=5 last_name preserved ──
mariadb "$DB" -sNBe "SELECT HEX(last_name) FROM _person WHERE id=5;" | grep -q "F09F9880" \
	|| { echo "FAIL: BLOB detour — 4-byte emoji (F09F9880) lost from _person id=5"; exit 1; }

# ── Plain CONVERT: cp1252 0x97 em-dash in action_plan.name (id=1) becomes UTF-8 E2 80 94 ──
mariadb "$DB" -sNBe "SELECT HEX(name) FROM action_plan WHERE id=1;" | grep -q "E28094" \
	|| { echo "FAIL: plain CONVERT — cp1252 0x97 not re-encoded to E28094 in action_plan.name id=1"; \
	     mariadb "$DB" -e "SELECT id, name, HEX(name) FROM action_plan WHERE id=1;"; \
	     exit 1; }

# ── Mixed table: per-row repair (first_name) and whole-column repair (remarks) ──
# Both seeded values are 0x41 0x97 0x62 ("A—B"), which must become 41 E2 80 94 62.
"${M[@]}" "$DB" -sNBe "SELECT HEX(first_name), HEX(remarks) FROM _person WHERE id=2;" | grep -qx $'41E2809462\t41E2809462' \
	|| { echo "FAIL: mixed table — cp1252 0x97 not transcoded correctly in _person id=2"; \
	     "${M[@]}" "$DB" -e "SELECT id, first_name, HEX(first_name), remarks, HEX(remarks) FROM _person WHERE id=2;"; \
	     exit 1; }

# ── Row format: COMPACT tables auto-upgraded to DYNAMIC ──
mariadb "$DB" -sNBe "SELECT ROW_FORMAT FROM information_schema.TABLES WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME='2fa_trust';" | grep -qx "Dynamic" \
	|| { echo "FAIL: 2fa_trust ROW_FORMAT not upgraded to DYNAMIC"; exit 1; }

echo "SUCCESS: web upgrade validated (BLOB detour, plain CONVERT, mixed table, row format)"
