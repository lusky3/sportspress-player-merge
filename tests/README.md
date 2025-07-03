# SportsPress Player Merge - Comprehensive Testing

## Overview

Complete containerized test suite that builds WordPress from scratch and validates all plugin functionality.

## Quick Start

```bash
# Run complete test suite (recommended)
./run-tests.sh

# Or using npm
npm run test:docker
```

## Test Environment

- **Containerized**: Docker-based WordPress + MySQL
- **Ground Zero**: Fresh WordPress installation
- **Automated Setup**: Creates all test data
- **Headless**: Runs in CI/CD environments

## Test Coverage

### Core Functionality
- ✅ Same player merge prevention
- ✅ Basic 1:1 player merge
- ✅ Multi-player merge (1:N)
- ✅ Merge preview generation
- ✅ Revert last merge
- ✅ Backup system revert
- ✅ Backup deletion

### Data Integrity
- ✅ Statistics preservation
- ✅ Reference updates
- ✅ Team assignments
- ✅ Player list updates
- ✅ Event data linking

### Plugin Lifecycle
- ✅ Plugin installation
- ✅ Plugin activation
- ✅ Plugin updates
- ✅ Clean uninstallation
- ✅ Data cleanup verification

## Test Data Creation

Automatically creates:
- 2+ Teams (Team A, Team B)
- 4+ Players (including duplicates)
- 1+ League (Test League)
- 1+ Season (2024)
- 1+ Event (Test Match)
- 1+ Player List (Team A Roster)
- Player statistics and assignments

## Requirements

- Docker & Docker Compose
- No local WordPress needed
- No manual setup required

## GitHub Actions

Automatically runs on:
- Push to main/develop branches
- Pull requests to main
- Manual workflow dispatch

## Local Development

```bash
# Setup only (for manual testing)
npm run setup

# Run tests against existing environment
npm run test:local

# Cleanup
npm run teardown
```

## Exit Codes

- `0`: All tests passed
- `1`: One or more tests failed

Perfect for CI/CD integration and release validation.