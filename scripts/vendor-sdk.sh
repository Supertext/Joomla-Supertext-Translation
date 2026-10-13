#!/bin/sh
# Copies the PHP Supertext SDK from its GitHub repository into plugin/src/Sdk, with its
# namespace changed from Supertext\Sdk to Supertext\Plugin\System\Supertext\Sdk, so Joomla's
# own autoloader for the plugin (namespace path="src" in supertext.xml) loads it. The plugin
# ships this copy, so sites install nothing besides the plugin, and another extension that
# ships the SDK itself can never clash with it.
#
#   scripts/vendor-sdk.sh [commit-or-branch]   (default: main)
#   SDK_DIR=/path/to/clone scripts/vendor-sdk.sh   (use a local clone as it is)
set -eu
REF="${1:-main}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

if [ -n "${SDK_DIR:-}" ]; then
    SRC="$SDK_DIR"
else
    git clone --quiet https://github.com/Supertext/Supertext-API-SDK-and-Guide "$TMP/sdk"
    git -C "$TMP/sdk" checkout --quiet "$REF"
    SRC="$TMP/sdk"
fi
COMMIT="$(git -C "$SRC" rev-parse HEAD)"

DEST="$ROOT/plugin/src/Sdk"
rm -rf "$DEST"
mkdir -p "$DEST"
cp -R "$SRC/sdk/php/src/." "$DEST/"
cp "$SRC/LICENSE" "$DEST/LICENSE"
find "$DEST" -name '*.php' -exec sed -i 's/Supertext\\Sdk\\/Supertext\\Plugin\\System\\Supertext\\Sdk\\/g; s/Supertext\\Sdk;/Supertext\\Plugin\\System\\Supertext\\Sdk;/g' {} +
printf '%s\n' "$COMMIT" > "$DEST/COMMIT"
cat > "$DEST/README.md" <<EOT
# Supertext SDK (bundled copy)

PHP part of the [Supertext SDK](https://github.com/Supertext/Supertext-API-SDK-and-Guide) (MIT licence, see LICENSE),
commit \`$COMMIT\`, namespace \`Supertext\\Plugin\\System\\Supertext\\Sdk\` instead of \`Supertext\\Sdk\`.

Don't edit these files: fix the SDK in its own repository, then run \`scripts/vendor-sdk.sh\`.
EOT
if grep -rqP '(?<!System\\)Supertext\\Sdk' "$DEST" --include='*.php'; then
    echo "vendor-sdk: unprefixed Supertext\\Sdk reference left in $DEST" >&2
    exit 1
fi
echo "plugin/src/Sdk: Supertext SDK (PHP) from commit $COMMIT"
