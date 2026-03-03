#!/usr/bin/env bash
set -euo pipefail

# =============================================================================
# release.sh — Monorepo release script
# =============================================================================
#
# Usage:
#   ./scripts/release.sh patch|minor|major ["optional release title"]
#
# What it does:
#   1. Validates branch (main) and clean working tree
#   2. Computes next semver tag from latest v* tag
#   3. Updates CHANGELOG.md (moves [Unreleased] items -> new [X.Y.Z] section)
#   4. Commits the changelog update
#   5. Creates an annotated tag and pushes main + tag
#   6. Optionally creates a GitHub Release via gh (best-effort)
#
# Monorepo split compatibility:
#   The split workflow (.github/workflows/split.yml) triggers on tag push.
#   Pushing vX.Y.Z here propagates the same tag to all split repos
#   (runtime-pack, symfony-bridge, etc.) automatically. This script
#   intentionally creates only global tags — never package-specific ones.
#
# Requirements: git, bash, awk, date
# Optional:     gh (GitHub CLI) for GitHub Release creation
# Portable:     macOS (BSD awk) + Linux (GNU awk) — no sed -i needed
# =============================================================================

BUMP="${1:-}"
TITLE="${2:-}"
REPO_URL="https://github.com/LaProgrammerie/octo-php"
CHANGELOG="CHANGELOG.md"

# ---------------------------------------------------------------------------
# Validation
# ---------------------------------------------------------------------------

if [[ ! "$BUMP" =~ ^(patch|minor|major)$ ]]; then
  echo "Usage: $0 patch|minor|major [title]" >&2
  exit 1
fi

BRANCH="$(git rev-parse --abbrev-ref HEAD)"
if [[ "$BRANCH" != "main" ]]; then
  echo "Error: must be on main (current: $BRANCH)" >&2
  exit 1
fi

if [[ -n "$(git status --porcelain)" ]]; then
  echo "Error: working tree is dirty. Commit or stash first." >&2
  exit 1
fi

if [[ ! -f "$CHANGELOG" ]]; then
  echo "Error: $CHANGELOG not found at repo root." >&2
  exit 1
fi

# Pre-flight: if gh is installed, verify auth before doing anything destructive.
# This avoids the "tag pushed but GitHub Release failed" scenario.
if command -v gh >/dev/null 2>&1; then
  if ! gh auth status >/dev/null 2>&1; then
    echo "Error: gh is installed but not authenticated." >&2
    echo "Run 'gh auth login' first, or uninstall gh to skip GitHub Release creation." >&2
    exit 1
  fi
fi

# ---------------------------------------------------------------------------
# Compute next version
# ---------------------------------------------------------------------------

git fetch --tags origin

LAST_TAG="$(git tag -l 'v*' --sort=-v:refname | head -n1 || true)"
if [[ -z "$LAST_TAG" ]]; then
  LAST_TAG="v0.0.0"
fi

VER="${LAST_TAG#v}"
IFS='.' read -r MAJ MIN PAT <<< "$VER"
MAJ="${MAJ:-0}"; MIN="${MIN:-0}"; PAT="${PAT:-0}"

case "$BUMP" in
  patch) PAT=$((PAT + 1)) ;;
  minor) MIN=$((MIN + 1)); PAT=0 ;;
  major) MAJ=$((MAJ + 1)); MIN=0; PAT=0 ;;
esac

NEXT_VER="${MAJ}.${MIN}.${PAT}"
NEXT_TAG="v${NEXT_VER}"

if git rev-parse "$NEXT_TAG" >/dev/null 2>&1; then
  echo "Error: tag already exists: $NEXT_TAG" >&2
  echo "If the GitHub Release is missing, create it manually:" >&2
  echo "  gh release create $NEXT_TAG --generate-notes --title \"$NEXT_TAG\"" >&2
  exit 1
fi

TODAY="$(date +%Y-%m-%d)"

echo "Last tag:    $LAST_TAG"
echo "Next tag:    $NEXT_TAG"
echo "Date:        $TODAY"
echo

# ---------------------------------------------------------------------------
# Generate release notes from git log
# ---------------------------------------------------------------------------

if [[ "$LAST_TAG" == "v0.0.0" ]]; then
  RANGE="HEAD"
else
  RANGE="${LAST_TAG}..HEAD"
fi

# Generate git log entries for this release
GIT_LOG_FILE="$(mktemp "${TMPDIR:-/tmp}/release-gitlog.XXXXXX.txt")"
if [[ "$LAST_TAG" == "v0.0.0" ]]; then
  git log --no-merges --pretty=format:'- %s (%h)' HEAD > "$GIT_LOG_FILE"
else
  git log --no-merges --pretty=format:'- %s (%h)' "$RANGE" > "$GIT_LOG_FILE"
fi

NOTES_FILE="$(mktemp "${TMPDIR:-/tmp}/release-notes.XXXXXX.md")"
{
  if [[ -n "$TITLE" ]]; then
    echo "# ${NEXT_TAG} — $TITLE"
  else
    echo "# ${NEXT_TAG}"
  fi
  echo
  echo "## Changes"
  echo
  cat "$GIT_LOG_FILE"
  echo
} > "$NOTES_FILE"

# ---------------------------------------------------------------------------
# Update CHANGELOG.md
# ---------------------------------------------------------------------------
# Strategy: awk processes line by line. No multiline -v variables (BSD compat).
# The empty [Unreleased] template is printed inline from the awk script.
# We write to a temp file then mv (portable, no sed -i differences).
#
# If [Unreleased] contains only empty sub-headings (no real items), the git
# log is injected as fallback under "### Changed".
# The git log file is read by awk as a second input (ARGV[2]) before the
# changelog, so it works on both BSD and GNU awk.
# ---------------------------------------------------------------------------

TMP_CL="$(mktemp "${TMPDIR:-/tmp}/changelog.XXXXXX.md")"

awk -v next_ver="$NEXT_VER" \
    -v next_tag="$NEXT_TAG" \
    -v today="$TODAY" \
    -v title="$TITLE" \
    -v repo_url="$REPO_URL" \
    -v last_tag="$LAST_TAG" \
    -v gitlog_file="$GIT_LOG_FILE" \
'
BEGIN {
  state = "passthrough"  # passthrough | unreleased | links
  unreleased_buf = ""

  # Read git log fallback from file (portable, no multiline -v)
  gitlog = ""
  while ((getline line < gitlog_file) > 0) {
    gitlog = gitlog line "\n"
  }
  close(gitlog_file)
}

# ── [Unreleased] heading ──────────────────────────────────────────────
state == "passthrough" && /^## \[Unreleased\]/ {
  # Emit fresh empty Unreleased section
  print "## [Unreleased]"
  print ""
  print "### Added"
  print ""
  print "### Changed"
  print ""
  print "### Deprecated"
  print ""
  print "### Removed"
  print ""
  print "### Fixed"
  print ""
  print "### Security"
  print ""
  state = "unreleased"
  next
}

# ── Inside unreleased: accumulate until next ## [ heading ─────────────
state == "unreleased" && /^## \[/ {
  # Emit new version section
  header = "## [" next_ver "] - " today
  if (title != "") header = header " \342\200\224 " title
  print header
  print ""

  # Check if unreleased content has real items (not just empty sub-headings)
  check = unreleased_buf
  gsub(/### (Added|Changed|Deprecated|Removed|Fixed|Security)/, "", check)
  gsub(/[[:space:]]/, "", check)

  if (check != "") {
    # Has real content — use it as-is
    gsub(/^\n+/, "", unreleased_buf)
    gsub(/\n+$/, "", unreleased_buf)
    print unreleased_buf
    print ""
  } else if (gitlog != "") {
    # Empty unreleased — inject git log as fallback
    print "### Changed"
    print ""
    printf "%s", gitlog
    print ""
  }

  # Print the old version heading we just matched
  print $0
  state = "passthrough"
  next
}

state == "unreleased" {
  unreleased_buf = unreleased_buf $0 "\n"
  next
}

# ── Link rewriting ────────────────────────────────────────────────────
state == "passthrough" && /^\[Unreleased\]:/ {
  print "[Unreleased]: " repo_url "/compare/" next_tag "...HEAD"
  state = "links"
  next
}

# Insert new version link right after [Unreleased] link line
state == "links" && /^\[/ {
  if (last_tag == "v0.0.0") {
    print "[" next_ver "]: " repo_url "/releases/tag/" next_tag
  } else {
    print "[" next_ver "]: " repo_url "/compare/" last_tag "..." next_tag
  }
  state = "passthrough"
  print $0
  next
}

# If line after [Unreleased] link is not a link (only one version ever)
state == "links" {
  if (last_tag == "v0.0.0") {
    print "[" next_ver "]: " repo_url "/releases/tag/" next_tag
  } else {
    print "[" next_ver "]: " repo_url "/compare/" last_tag "..." next_tag
  }
  state = "passthrough"
  print $0
  next
}

# ── Default passthrough ───────────────────────────────────────────────
{ print }

END {
  # Edge case: [Unreleased] was the last section (no prior version heading)
  if (state == "unreleased") {
    header = "## [" next_ver "] - " today
    if (title != "") header = header " \342\200\224 " title
    print header
    print ""

    check = unreleased_buf
    gsub(/### (Added|Changed|Deprecated|Removed|Fixed|Security)/, "", check)
    gsub(/[[:space:]]/, "", check)

    if (check != "") {
      gsub(/^\n+/, "", unreleased_buf)
      gsub(/\n+$/, "", unreleased_buf)
      print unreleased_buf
      print ""
    } else if (gitlog != "") {
      print "### Changed"
      print ""
      printf "%s", gitlog
      print ""
    }
  }
}
' "$CHANGELOG" > "$TMP_CL"

mv "$TMP_CL" "$CHANGELOG"

echo "Updated $CHANGELOG"

# ---------------------------------------------------------------------------
# Commit, tag, push
# ---------------------------------------------------------------------------

git add "$CHANGELOG"

if [[ -n "$TITLE" ]]; then
  git commit -m "chore: release ${NEXT_TAG} — $TITLE"
else
  git commit -m "chore: release ${NEXT_TAG}"
fi

git tag -a "$NEXT_TAG" -m "${NEXT_TAG}${TITLE:+ — $TITLE}"

git push origin main
git push origin "$NEXT_TAG"

echo ""
echo "Pushed $NEXT_TAG"
echo "Split workflow will propagate this tag to all sub-repos automatically."

# ---------------------------------------------------------------------------
# Optional: GitHub Release (best-effort)
# ---------------------------------------------------------------------------

if command -v gh >/dev/null 2>&1; then
  if [[ -n "$TITLE" ]]; then
    GH_TITLE="${NEXT_TAG} — $TITLE"
  else
    GH_TITLE="$NEXT_TAG"
  fi
  gh release create "$NEXT_TAG" \
    --notes-file "$NOTES_FILE" \
    --title "$GH_TITLE" || {
    echo "gh release create failed (auth/permissions?). Tag is already pushed." >&2
    exit 0
  }
  echo "GitHub Release created"
else
  echo "gh not found: skipping GitHub Release creation."
fi

# Cleanup
rm -f "$NOTES_FILE" "$GIT_LOG_FILE"
