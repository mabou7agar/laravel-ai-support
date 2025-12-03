#!/bin/bash

# Release v2.2.9 - Improved Empty Array Handling

set -e

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cd "$SCRIPT_DIR/.."

echo "🚀 Releasing Laravel AI Engine v2.2.9"
echo ""

git add -A

git commit -m "improve: Better handling of empty arrays in query analysis

🔧 Improvement: Explicit Empty Array Handling

Enhancement:
────────────
Use explicit empty() checks instead of null coalescing
for both search_queries and collections.

Why Better:
───────────
✅ Handles both null AND empty arrays
✅ More explicit and readable
✅ Consistent pattern for both fields
✅ Prevents edge cases with empty arrays

Before:
'collections' => \$analysis['collections'] ?? \$availableCollections
// Only handles null, not []

After:
\$collections = \$analysis['collections'] ?? null;
if (empty(\$collections)) {
    \$collections = \$availableCollections;
}
// Handles both null and []

Status: Improved ✅"

echo "✅ Committed changes"

# Delete local and remote tags if they exist
git tag -d v2.2.9 2>/dev/null || true
git push origin :refs/tags/v2.2.9 2>/dev/null || true

git tag -a v2.2.9 -m "Release v2.2.9 - Improved Empty Array Handling

🔧 Laravel AI Engine v2.2.9

Improvement:
────────────
✅ Better handling of empty arrays in query analysis
✅ Explicit empty() checks for robustness
✅ Handles both null and empty arrays
✅ Consistent pattern across fields

Technical Change:
─────────────────
Replaced null coalescing with explicit empty() checks
for search_queries and collections fields.

Benefits:
─────────
✅ More robust edge case handling
✅ Clearer code intent
✅ Better maintainability

Breaking Changes: None
Upgrade: Recommended"

echo "✅ Created tag v2.2.9"

git push origin v2.2.9
echo "✅ Pushed tag"

git push origin laravel-9-support
echo "✅ Pushed branch"

echo ""
echo "🎉 Successfully released v2.2.9!"
