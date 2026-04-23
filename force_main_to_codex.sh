#!/usr/bin/env bash
set -euo pipefail

if [[ $# -ne 1 ]]; then
  echo "Usage: $0 <codex-branch-name>"
  echo "Example: $0 codex/create-backend-code-for-vantaggio-pensione-k2ldcr"
  exit 1
fi

CODEX_BRANCH="$1"

echo "[1/6] Fetch origin..."
git fetch origin

echo "[2/6] Checkout main..."
git checkout main

echo "[3/6] Pull latest main..."
git pull origin main

echo "[4/6] Fetch codex branch..."
git fetch origin "$CODEX_BRANCH"

echo "[5/6] Hard reset main to origin/$CODEX_BRANCH ..."
git reset --hard "origin/$CODEX_BRANCH"

echo "[6/6] Force push main..."
git push origin main --force-with-lease

echo "Done. main now matches origin/$CODEX_BRANCH"
echo "Verify:"
echo "  - https://<your-render-domain>/healthz"
echo "  - https://<your-render-domain>/"
