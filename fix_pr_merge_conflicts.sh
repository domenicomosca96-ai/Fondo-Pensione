#!/usr/bin/env bash
set -euo pipefail

if [[ $# -ne 1 ]]; then
  echo "Usage: $0 <pr-branch-name>"
  echo "Example: $0 codex/create-backend-code-for-vantaggio-pensione-k2ldcr"
  exit 1
fi

PR_BRANCH="$1"

echo "[1/5] Fetch latest remote refs..."
git fetch origin

echo "[2/5] Checkout PR branch: $PR_BRANCH"
git checkout "$PR_BRANCH"

echo "[3/5] Pull latest PR branch..."
git pull origin "$PR_BRANCH"

echo "[4/5] Merge main into PR branch favoring PR changes (-X ours)..."
git merge origin/main -X ours --no-edit

echo "[5/5] Push updated PR branch..."
git push origin "$PR_BRANCH"

echo "Done. Open GitHub PR again: merge conflicts should be gone."
