#!/usr/bin/env bash
#
# scripts/release-notes.sh — prepare the curated release notes for a version.
#
# Usage:
#   scripts/release-notes.sh <x.y.z>          Create a draft if it is missing.
#   scripts/release-notes.sh <x.y.z> --check  Fail if the notes file is missing.
#
# `scripts/release.sh` calls this before bumping or building. When the notes
# file is missing, a draft built from the commits since the previous tag is
# written and the release stops (exit 10) so the draft can be reviewed,
# completed and committed before anything is tagged or published.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

CHECK=0
VERSION=""
for arg in "$@"; do
    case "$arg" in
        --check) CHECK=1 ;;
        -h|--help|help)
            sed -n '2,11p' "$0" | sed 's/^# \{0,1\}//'
            exit 0
            ;;
        -*)
            printf 'Unknown option: %s\n' "$arg" >&2
            exit 1
            ;;
        *)
            if [[ -n "$VERSION" ]]; then
                printf 'Only one version is allowed.\n' >&2
                exit 1
            fi
            VERSION="$arg"
            ;;
    esac
done

if [[ ! "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    printf 'Usage: scripts/release-notes.sh <x.y.z> [--check]\n' >&2
    exit 1
fi

NOTES_DIR="$ROOT_DIR/scripts/release-notes"
NOTES_FILE="$NOTES_DIR/$VERSION.md"
NOTES_RELATIVE="scripts/release-notes/$VERSION.md"

if [[ -s "$NOTES_FILE" ]]; then
    printf 'Curated release notes found: %s\n' "$NOTES_RELATIVE"
    exit 0
fi

if [[ "$CHECK" == "1" ]]; then
    printf 'Error: missing curated release notes: %s\n' "$NOTES_RELATIVE" >&2
    printf 'Create them with: scripts/release-notes.sh %s\n' "$VERSION" >&2
    exit 1
fi

REPO=""
ORIGIN_URL="$(git remote get-url origin 2>/dev/null || true)"
case "$ORIGIN_URL" in
    git@github.com:*) REPO="${ORIGIN_URL#git@github.com:}" ;;
    https://github.com/*) REPO="${ORIGIN_URL#https://github.com/}" ;;
esac
REPO="${REPO%.git}"
if [[ -z "$REPO" ]]; then
    REPO="plakio/wp-mcp"
fi

PREVIOUS_TAG="$(git tag --list 'v*' --sort=-v:refname | awk -v skip="v$VERSION" '$0 != skip { print; exit }')"

mkdir -p "$NOTES_DIR"
if [[ -n "$PREVIOUS_TAG" ]]; then
    CHANGES="$(git log --no-merges --pretty=format:'- %s' "${PREVIOUS_TAG}..HEAD" \
        | grep -v -E '^- (Release [0-9]+\.[0-9]+\.[0-9]+|Add curated release notes for )' \
        || true)"
else
    CHANGES="$(git log --no-merges --pretty=format:'- %s' -n 30 \
        | grep -v -E '^- (Release [0-9]+\.[0-9]+\.[0-9]+|Add curated release notes for )' \
        || true)"
fi
{
    printf 'WP-MCP %s\n\n' "$VERSION"
    printf '<!-- Draft generado automáticamente. Revisar y completar antes de publicar. -->\n\n'
    printf '## Cambios\n\n'
    printf '%s\n' "${CHANGES:-- Pendiente.}"
    printf '\n## Verificación\n\n- Pendiente.\n\n'
    if [[ -n "$PREVIOUS_TAG" ]]; then
        printf '**Full Changelog**: https://github.com/%s/compare/%s...v%s\n' "$REPO" "$PREVIOUS_TAG" "$VERSION"
    else
        printf '**Full Changelog**: https://github.com/%s/releases/tag/v%s\n' "$REPO" "$VERSION"
    fi
} > "$NOTES_FILE"

printf 'Draft release notes created: %s\n' "$NOTES_RELATIVE"
printf 'Review and complete the file, commit it, then run the release again.\n'
exit 10
