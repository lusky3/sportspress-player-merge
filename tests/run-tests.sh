#!/bin/bash

# SportsPress Player Merge - Comprehensive Test Automation
# Containerized testing from ground zero

set -e

echo "🚀 Starting Comprehensive Test Suite..."

# Navigate to test directory
cd "$(dirname "$0")"

# Check if Docker is available
if ! command -v docker &> /dev/null; then
    echo "❌ Docker is required for testing"
    exit 1
fi

if ! command -v docker-compose &> /dev/null; then
    echo "❌ Docker Compose is required for testing"
    exit 1
fi

# Clean up any existing containers
echo "🧹 Cleaning up previous test environment..."
docker-compose down -v 2>/dev/null || true

# Build and run comprehensive tests
echo "🏗️ Building test environment..."
docker-compose build

echo "📦 Starting WordPress and database..."
docker-compose up -d wordpress db

# Wait for WordPress to be ready
echo "⏳ Waiting for WordPress to initialize..."
sleep 30

echo "🧪 Running comprehensive test suite..."
docker-compose run --rm test-runner

# Capture exit code
TEST_EXIT_CODE=$?

# Cleanup
echo "🧹 Cleaning up test environment..."
docker-compose down -v

if [ $TEST_EXIT_CODE -eq 0 ]; then
    echo "✅ All tests passed! Plugin ready for release."
else
    echo "❌ Some tests failed. Review output before release."
fi

exit $TEST_EXIT_CODE