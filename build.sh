#!/bin/sh
# Builds the installable package: dist/plg_system_supertext-<version>.zip
set -eu
cd "$(dirname "$0")"
version=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' plugin/supertext.xml | head -1)
mkdir -p dist
rm -f "dist/plg_system_supertext-$version.zip"
(cd plugin && zip -qr "../dist/plg_system_supertext-$version.zip" . -x '.*')
echo "dist/plg_system_supertext-$version.zip"
