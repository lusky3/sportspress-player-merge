#!/usr/bin/env bash
#
# Run every standalone test in this directory.
# Exits non-zero if any test fails.

set -u

cd "$(dirname "$0")" || exit 1

failed=0

for test_file in test-*.php; do
	echo "=== ${test_file}"
	if php "${test_file}"; then
		:
	else
		failed=$((failed + 1))
		echo "*** ${test_file} FAILED"
	fi
	echo
done

if [ "${failed}" -gt 0 ]; then
	echo "${failed} test file(s) failed"
	exit 1
fi

echo "All test files passed"
