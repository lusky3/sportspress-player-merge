# Security Policy

## Supported Versions

| Version | Supported          |
| ------- | ------------------ |
| Latest  | :white_check_mark: |

## Reporting a Vulnerability

If you discover a security vulnerability, please report it responsibly.

**Do not open a public GitHub issue for security vulnerabilities.**

Instead, please use [GitHub's private vulnerability reporting](https://docs.github.com/en/code-security/security-advisories/guidance-on-reporting-and-writing-information-about-vulnerabilities/privately-reporting-a-security-vulnerability) feature. You can find the "Report a vulnerability" button under the Security tab of this repository.

### What to include

- A description of the vulnerability
- Steps to reproduce the issue
- The potential impact
- Any suggested fixes (optional)

### What to expect

- Acknowledgment within 48 hours
- A fix timeline within 7 days for critical issues
- Credit in the release notes (unless you prefer to remain anonymous)

## Security Measures

This plugin follows WordPress security best practices:

- All AJAX handlers verify nonces with `wp_verify_nonce()`
- All endpoints check user capabilities with `current_user_can()`
- All user input is sanitized and escaped before output
- Database queries use prepared statements via `$wpdb->prepare()`
- Direct file access is prevented with `if ( ! defined( 'ABSPATH' ) ) exit;`
