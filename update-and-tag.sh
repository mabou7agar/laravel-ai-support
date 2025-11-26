#!/bin/bash

# Laravel AI Engine - Update and Tag Script
# This script commits changes and updates the dev tag for local development

set -e  # Exit on error

# Colors for output
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

echo "🏷️  Laravel AI Engine - Update and Tag"
echo ""

# Check if we're in a git repository
if [ ! -d .git ]; then
    echo -e "${RED}❌ Error: Not in a git repository${NC}"
    exit 1
fi

# Get current branch
CURRENT_BRANCH=$(git branch --show-current)
echo -e "${BLUE}📍 Current branch: ${YELLOW}${CURRENT_BRANCH}${NC}"

# Check for uncommitted changes
if [[ -n $(git status -s) ]]; then
    echo -e "${YELLOW}📝 Uncommitted changes detected${NC}"
    echo ""
    git status -s
    echo ""
    
    read -p "Do you want to commit these changes? (y/n) " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        read -p "Enter commit message: " COMMIT_MSG
        git add .
        git commit -m "$COMMIT_MSG"
        echo -e "${GREEN}✅ Changes committed${NC}"
    else
        echo -e "${YELLOW}⚠️  Skipping commit${NC}"
    fi
else
    echo -e "${GREEN}✅ No uncommitted changes${NC}"
fi

# Update dev tag
DEV_TAG="dev-${CURRENT_BRANCH}"
echo ""
echo -e "${BLUE}🏷️  Updating tag: ${YELLOW}${DEV_TAG}${NC}"

# Delete existing tag locally and remotely (if exists)
if git tag -l | grep -q "^${DEV_TAG}$"; then
    echo -e "${YELLOW}🗑️  Removing existing local tag...${NC}"
    git tag -d "${DEV_TAG}"
fi

# Create new tag
echo -e "${BLUE}✨ Creating new tag...${NC}"
git tag -f "${DEV_TAG}"

# Ask if user wants to push
echo ""
read -p "Do you want to push changes and tag to remote? (y/n) " -n 1 -r
echo
if [[ $REPLY =~ ^[Yy]$ ]]; then
    echo -e "${BLUE}⬆️  Pushing to remote...${NC}"
    git push origin "${CURRENT_BRANCH}" || true
    git push origin "${DEV_TAG}" --force
    echo -e "${GREEN}✅ Pushed to remote${NC}"
else
    echo -e "${YELLOW}⚠️  Skipping remote push${NC}"
fi

echo ""
echo -e "${GREEN}🎉 Done! Tag ${YELLOW}${DEV_TAG}${GREEN} has been updated${NC}"
echo ""
echo -e "${BLUE}💡 Next steps:${NC}"
echo -e "   1. Go to your Laravel project root"
echo -e "   2. Run: ${YELLOW}./update-ai-engine.sh${NC}"
echo -e "   3. Or run: ${YELLOW}composer update m-tech-stack/laravel-ai-engine${NC}"
