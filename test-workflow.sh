#!/bin/bash

# Test workflow setup script
echo "Testing GitHub workflow configuration..."

# Check if we're in a git repository
if ! git rev-parse --git-dir > /dev/null 2>&1; then
    echo "Error: Not in a git repository"
    exit 1
fi

# Get current branch
CURRENT_BRANCH=$(git branch --show-current)
echo "Current branch: $CURRENT_BRANCH"

# Create test branch if it doesn't exist
TEST_BRANCH="test-workflow"
if ! git show-ref --verify --quiet refs/heads/$TEST_BRANCH; then
    echo "Creating test branch: $TEST_BRANCH"
    git checkout -b $TEST_BRANCH
else
    echo "Test branch already exists: $TEST_BRANCH"
    git checkout $TEST_BRANCH
fi

# Make a small change to trigger workflow
echo "# Workflow test - $(date)" >> workflow-test.md

# Stage and commit
git add .
git commit -m "Test workflow on branch $TEST_BRANCH"

echo "Ready to push to test branch. Run:"
echo "git push origin $TEST_BRANCH"
echo ""
echo "This will trigger the GitHub workflow for testing."