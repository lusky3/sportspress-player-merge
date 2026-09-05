#!/usr/bin/env bash
#
# Run every standalone test in this directory under a coverage driver and
# produce a combined Clover report.
#
# Mirrors run-all.sh's per-file output and aggregate exit code exactly — this is
# that script plus instrumentation, not a replacement for it. run-all.sh stays
# the fast, dependency-free path for a plain local test run; use this one when
# you want numbers.
#
# Requires `composer install` (dev dependencies) and either PCOV or Xdebug.

set -u

repo_root="$(cd "$(dirname "$0")/.." && pwd)"
raw_dir="${repo_root}/.coverage-raw"
report_dir="${repo_root}/coverage"

if [ ! -f "${repo_root}/vendor/autoload.php" ]; then
	echo "Coverage tooling is not installed. Run 'composer install' first." >&2
	exit 1
fi

rm -rf "${raw_dir}" "${report_dir}"
mkdir -p "${raw_dir}" "${report_dir}"

cd "$(dirname "$0")" || exit 1

# pcov.directory defaults to "auto", which resolves to the working directory —
# tests/ here, which would instrument the harness and none of the plugin. Pin it
# to the repository root instead, and keep the driver out of vendor/ and the
# harness so it only tracks files the report can actually use.
php_opts=(
	-d "pcov.directory=${repo_root}"
	-d "pcov.exclude=~(?:/vendor/|/tests/)~"
)

failed=0

for test_file in test-*.php; do
	echo "=== ${test_file}"
	if php "${php_opts[@]}" "${repo_root}/bin/coverage/run-one.php" "${test_file}" "${raw_dir}/${test_file}.cov"; then
		:
	else
		failed=$((failed + 1))
		echo "*** ${test_file} FAILED"
	fi
	echo
done

php "${repo_root}/bin/coverage/merge.php" "${raw_dir}" "${report_dir}" || exit 1

if [ "${failed}" -gt 0 ]; then
	echo "${failed} test file(s) failed"
	exit 1
fi

echo "All test files passed"
