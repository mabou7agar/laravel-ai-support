#!/bin/bash

# Laravel AI Engine - Push and Tag Script
# Automatically pushes changes and updates the osarh tag

set -e  # Exit on error

# Colors for output
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

echo "🚀 Laravel AI Engine - Push and Tag osarh"
echo ""

# Check if we're in a git repository
if [ ! -d .git ]; then
    echo -e "${RED}❌ Error: Not in a git repository${NC}"
    exit 1
fi

# Get current branch
CURRENT_BRANCH=$(git branch --show-current)
echo -e "${BLUE}📍 Current branch: ${YELLOW}${CURRENT_BRANCH}${NC}"

# Tag version
TAG_VERSION="osarh"

# Check for uncommitted changes
if [[ -n $(git status -s) ]]; then
    echo -e "${YELLOW}📝 Uncommitted changes detected - committing...${NC}"
    git add .
    git commit -m "feat: Laravel AI Engine osarh - RAG with Option Cards" || true
    echo -e "${GREEN}✅ Changes committed${NC}"
else
    echo -e "${GREEN}✅ No uncommitted changes${NC}"
fi

# Delete existing tag locally (if exists)
if git tag -l | grep -q "^${TAG_VERSION}$"; then
    echo -e "${YELLOW}🗑️  Removing existing local tag...${NC}"
    git tag -d "${TAG_VERSION}"
fi

# Create new tag
echo -e "${BLUE}✨ Creating tag ${YELLOW}${TAG_VERSION}${NC}"
git tag -a "${TAG_VERSION}" -m "Laravel AI Engine osarh

Features:
- RAG (Retrieval-Augmented Generation) with vector search
- Clickable option cards with beautiful gradient design
- Source citations with relevance scores
- Action buttons for interactive responses
- Enhanced chat UI with Alpine.js
- Embeddable widget with full feature parity
- Comprehensive documentation
- Clean codebase with removed dead code"

# Push to remote
echo ""
echo -e "${BLUE}⬆️  Pushing to remote...${NC}"
git push origin "${CURRENT_BRANCH}" || echo -e "${YELLOW}⚠️  Branch push failed (might be up to date)${NC}"

# Delete remote tag if exists
git push origin ":refs/tags/${TAG_VERSION}" 2>/dev/null || true

# Push new tag
git push origin "${TAG_VERSION}"

echo ""
echo -e "${GREEN}✅ Successfully pushed and tagged ${YELLOW}${TAG_VERSION}${NC}"
echo ""
echo -e "${BLUE}📦 Package updated:${NC}"
echo -e "   Repository: ${YELLOW}m-tech-stack/laravel-ai-engine${NC}"
echo -e "   Tag: ${YELLOW}${TAG_VERSION}${NC}"
echo ""
echo -e "${BLUE}💡 To update in your Laravel project:${NC}"
echo -e "   ${YELLOW}composer update m-tech-stack/laravel-ai-engine${NC}"
echo ""
echo -e "${GREEN}🎉 Done!${NC}"
