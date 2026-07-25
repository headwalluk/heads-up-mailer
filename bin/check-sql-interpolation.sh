#!/usr/bin/env bash
#
# Guard against unprepared interpolation of data into SQL.
#
# Why this exists
# ---------------
# phpcs.xml globally excludes WordPress.DB.PreparedSQL.InterpolatedNotPrepared,
# because the codebase interpolates table names — `{$table}`, `{$subs_table}` —
# into every custom-table query, which is the standard WordPress pattern and
# unavoidable ($wpdb->prepare() cannot placeholder an identifier). Re-enabling
# the sniff produces ~33 unsuppressable hits: the inline `phpcs:ignore`
# comments sit one line above multi-line statements, so they don't cover the
# string-literal line where the sniff fires.
#
# The cost of that exclusion is that a genuine `WHERE id = {$user_input}` would
# pass phpcs silently. This script restores the missing protection narrowly: it
# asserts that every variable interpolated inside a SQL string is a table name
# or a generated placeholder list, and fails on anything else.
#
# Usage:  bin/check-sql-interpolation.sh
# Exit:   0 = clean, 1 = a non-allowlisted variable is interpolated into SQL
#
# Run before committing, alongside phpcs.

set -euo pipefail

PLUGIN_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${PLUGIN_ROOT}"

# Expressions that are legitimate to interpolate into SQL:
#   *table*        — fully-qualified table names, always $wpdb->prefix . TABLE_*
#   placeholders*  — '%d, %d, …' strings built from count()/loop, never from data
#   subscribers | recipients | sends | drafts | groups | events
#                  — table-name locals that don't carry a _table suffix
#   wpdb->foo      — a core table-name property ($wpdb->users, $wpdb->posts).
#                    Matched as the full `wpdb->prop` form rather than by
#                    allowing the bare variable `wpdb`, so this stays narrow.
ALLOWED_PATTERN='^(.*_table|table|placeholders|placeholders_join|subscribers|subscriber_groups|recipients|sends|drafts|groups|events|wpdb->[a-zA-Z_][a-zA-Z0-9_]*)$'

SQL_KEYWORDS='SELECT|INSERT|UPDATE|DELETE|REPLACE|FROM|WHERE|SET|VALUES|ORDER BY|GROUP BY|LIMIT|OFFSET|JOIN|INTO|AND|ON'

VIOLATIONS=0

# Search PHP sources for lines that look like SQL and contain an interpolation.
while IFS= read -r MATCH_LINE; do
	MATCH_FILE="${MATCH_LINE%%:*}"
	REST="${MATCH_LINE#*:}"
	MATCH_LINENO="${REST%%:*}"

	# Pull each interpolated expression out of the line. Captures the
	# optional `->prop` tail so `$wpdb->users` is distinguishable from a
	# bare `$wpdb`.
	while IFS= read -r VARIABLE_NAME; do
		[ -z "${VARIABLE_NAME}" ] && continue

		if ! printf '%s' "${VARIABLE_NAME}" | grep -qE "${ALLOWED_PATTERN}"; then
			printf 'SQL INTERPOLATION: %s:%s interpolates $%s into a SQL string\n' \
				"${MATCH_FILE}" "${MATCH_LINENO}" "${VARIABLE_NAME}"
			VIOLATIONS=$((VIOLATIONS + 1))
		fi
	done < <(printf '%s' "${REST#*:}" \
		| grep -oE '\{\$[a-zA-Z_][a-zA-Z0-9_]*(->[a-zA-Z_][a-zA-Z0-9_]*)?' \
		| sed 's/^{\$//')

done < <(grep -rnE "(${SQL_KEYWORDS})[^\"']*\{\\\$" \
	--include='*.php' \
	includes/ integrations/ admin-templates/ public-templates/ 2>/dev/null || true)

if [ "${VIOLATIONS}" -gt 0 ]; then
	printf '\nFAILED: %d non-allowlisted SQL interpolation(s).\n' "${VIOLATIONS}"
	printf 'Pass the value through $wpdb->prepare() with a %%d / %%s placeholder\n'
	printf 'instead of interpolating it, or add the variable to ALLOWED_PATTERN\n'
	printf 'in this script if it is genuinely a table identifier.\n'
	exit 1
fi

printf 'OK: every SQL interpolation is a table name or generated placeholder list.\n'
exit 0
