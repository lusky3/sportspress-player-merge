#!/bin/bash

# SportsPress Player Merge - Comprehensive Test Automation
# Using wp-cli for efficient WordPress setup

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
docker-compose build --no-cache

echo "📦 Starting WordPress and database..."
docker-compose up -d wordpress db

# Wait for services to be healthy
echo "⏳ Waiting for services to be ready..."
for i in {1..30}; do
    if docker-compose exec -T wordpress curl -f http://localhost > /dev/null 2>&1; then
        echo "✅ WordPress is ready"
        break
    fi
    echo "⏳ Waiting for WordPress... ($i/30)"
    sleep 2
done

echo "🧪 Running comprehensive test suite..."
docker-compose run --rm test-runner

# Capture exit code
TEST_EXIT_CODE=$?

# Show container logs if tests failed
if [ $TEST_EXIT_CODE -ne 0 ]; then
    echo "📋 WordPress container logs:"
    docker-compose logs wordpress | tail -20
    echo "📋 Database container logs:"
    docker-compose logs db | tail -10
fi

# Cleanup
echo "🧹 Cleaning up test environment..."
docker-compose down -v

if [ $TEST_EXIT_CODE -eq 0 ]; then
    echo "✅ All tests passed! Plugin ready for release."
else
    echo "❌ Some tests failed. Review output before release."
fi

exit $TEST_EXIT_CODE