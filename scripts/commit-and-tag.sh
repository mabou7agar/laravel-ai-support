#!/bin/bash

# Commit and Tag Script with Override Support
# Usage: ./scripts/commit-and-tag.sh <version> <commit-message> <tag-message>
# Example: ./scripts/commit-and-tag.sh v2.2.9 "improve: Better handling" "Release v2.2.9"

set -e  # Exit on error

VERSION=$1
COMMIT_MSG=$2
TAG_MSG=$3

if [ -z "$VERSION" ]; then
    echo "❌ Error: Version is required"
    echo "Usage: ./scripts/commit-and-tag.sh <version> <commit-message> <tag-message>"
    exit 1
fi

if [ -z "$COMMIT_MSG" ]; then
    echo "❌ Error: Commit message is required"
    echo "Usage: ./scripts/commit-and-tag.sh <version> <commit-message> <tag-message>"
    exit 1
fi

if [ -z "$TAG_MSG" ]; then
    echo "❌ Error: Tag message is required"
    echo "Usage: ./scripts/commit-and-tag.sh <version> <commit-message> <tag-message>"
    exit 1
fi

echo "🚀 Starting commit and tag process..."
echo "📦 Version: $VERSION"
echo ""

# Get the script directory and navigate to package root
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cd "$SCRIPT_DIR/.."

echo "📁 Working directory: $(pwd)"
echo ""

# Stage all changes
echo "📝 Staging changes..."
git add -A

# Commit changes
echo "💾 Committing changes..."
git commit -m "$COMMIT_MSG"

# Delete local tag if exists
echo "🗑️  Removing local tag $VERSION if exists..."
git tag -d "$VERSION" 2>/dev/null || echo "   (Tag didn't exist locally)"

# Delete remote tag if exists
echo "🗑️  Removing remote tag $VERSION if exists..."
git push origin ":refs/tags/$VERSION" 2>/dev/null || echo "   (Tag didn't exist remotely)"

# Create new tag
echo "🏷️  Creating new tag $VERSION..."
git tag -a "$VERSION" -m "$TAG_MSG"

# Push tag
echo "⬆️  Pushing tag to origin..."
git push origin "$VERSION"

# Push branch
echo "⬆️  Pushing branch to origin..."
git push origin laravel-9-support

echo ""
echo "✅ Successfully committed and tagged $VERSION!"
echo "🎉 All done!"
