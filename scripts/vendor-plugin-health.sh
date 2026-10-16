#!/bin/sh
# Copies the PHP part of Supertext Plugin Health (version reports to the Supertext Commando
# Center, the API key check result and the ping answer) from the Commando Center repository into
# plugin/src/PluginHealth, with its namespace changed from Supertext\PluginHealth to
# Supertext\Plugin\System\Supertext\PluginHealth, so Joomla's own autoloader for the plugin
# (namespace path="src" in supertext.xml) loads it. The plugin ships this copy, like the SDK copy
# in plugin/src/Sdk, so sites install nothing besides the plugin.
#
#   scripts/vendor-plugin-health.sh [commit-or-branch]   (default: main)
#   PLUGIN_HEALTH_DIR=/path/to/clone scripts/vendor-plugin-health.sh   (use a local clone as it is)
set -eu
REF="${1:-main}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

if [ -n "${PLUGIN_HEALTH_DIR:-}" ]; then
    SRC="$PLUGIN_HEALTH_DIR"
else
    git clone --quiet https://github.com/Supertext/Commando-Center-Supertext-Translation "$TMP/center"
    git -C "$TMP/center" checkout --quiet "$REF"
    SRC="$TMP/center"
fi
COMMIT="$(git -C "$SRC" rev-parse HEAD)"

DEST="$ROOT/plugin/src/PluginHealth"
rm -rf "$DEST"
mkdir -p "$DEST"
cp -R "$SRC/plugin-health/php/src/." "$DEST/"
cp "$SRC/plugin-health/php/LICENSE" "$DEST/LICENSE"
find "$DEST" -name '*.php' -exec sed -i 's/Supertext\\PluginHealth\\/Supertext\\Plugin\\System\\Supertext\\PluginHealth\\/g; s/Supertext\\PluginHealth;/Supertext\\Plugin\\System\\Supertext\\PluginHealth;/g' {} +
VERSION="$(sed -n "s/.*PLUGIN_HEALTH_VERSION = '\([^']*\)'.*/\1/p" "$DEST/PluginHealth.php")"
printf '%s\n' "$COMMIT" > "$DEST/COMMIT"
printf '%s\n' "$VERSION" > "$DEST/VERSION"
cat > "$DEST/README.md" <<EOT
# Supertext Plugin Health (bundled copy)

PHP part of [Supertext Plugin Health](https://github.com/Supertext/Commando-Center-Supertext-Translation/tree/main/plugin-health) (MIT licence, see LICENSE),
version $VERSION, commit \`$COMMIT\`, namespace \`Supertext\\Plugin\\System\\Supertext\\PluginHealth\` instead of \`Supertext\\PluginHealth\`.

Don't edit these files: fix it in Commando-Center-Supertext-Translation/plugin-health, then run \`scripts/vendor-plugin-health.sh\`.
EOT
if grep -rqP '(?<!System\\)Supertext\\PluginHealth' "$DEST" --include='*.php'; then
    echo "vendor-plugin-health: unprefixed Supertext\\PluginHealth reference left in $DEST" >&2
    exit 1
fi
echo "plugin/src/PluginHealth: Supertext Plugin Health (PHP) $VERSION from commit $COMMIT"
