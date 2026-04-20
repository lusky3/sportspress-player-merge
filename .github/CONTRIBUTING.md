# Contributing

Thank you for your interest in contributing! This document provides guidelines for contributing to this project.

## Development Setup

1. Fork the repository and clone your fork locally.
2. Clone the [sportspress-sandbox](https://github.com/lusky3/sportspress-sandbox) as a sibling directory for testing:

   ```bash
   git clone https://github.com/lusky3/sportspress-sandbox.git
   ```

3. Install dependencies (if applicable):

   ```bash
   composer install
   ```

4. Create a new branch for your changes: `git checkout -b my-feature-branch`

## Coding Standards

- Follow [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/)
- All PHP files must pass PHPCS
- All code must pass PHPStan (if configured)
- Use `__()` and `_e()` for all user-facing strings with the correct text domain

## Commit Messages

This project follows [Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/):

```text
<type>[optional scope]: <description>
```

Common types: `feat`, `fix`, `docs`, `style`, `refactor`, `test`, `ci`, `build`, `chore`.

## Pull Request Process

1. Create a feature branch from `main`
2. Make your changes following the coding standards above
3. Ensure all CI checks pass
4. Push your branch and open a pull request
5. Fill out the PR template checklist

## Testing

- Write unit tests for new functionality where possible
- Run existing tests before submitting a PR
- Test plugin activation/deactivation on a clean WordPress install

## AI Usage Policy

We accept contributions assisted by AI (Large Language Models). However:

- You must review and test all AI-generated code
- Ensure the code follows our standards and architectural patterns
- Declare AI usage in your PR description if significant portions were generated

## Security

If you discover a security vulnerability, please report it responsibly. Do not open a public issue. See [SECURITY.md](SECURITY.md) for details.
