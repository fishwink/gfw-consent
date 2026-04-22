#!/bin/bash
set -e

# Read version from the plugin header
VERSION=$(grep -E "^\s*\*\s*Version:" gfw-consent.php | awk '{print $NF}')

if [ -z "$VERSION" ]; then
  echo "Error: couldn't find version in gfw-consent.php"
  exit 1
fi

# Build from the parent directory
cd ..
rm -f "gfw-consent-${VERSION}.zip"
zip -r "gfw-consent-${VERSION}.zip" gfw-consent \
  -x "gfw-consent/.git/*" \
  -x "gfw-consent/.github/*" \
  -x "gfw-consent/.claude/*" \
  -x "gfw-consent/.gitignore" \
  -x "gfw-consent/build.sh" \
  -x "*.DS_Store" \
  -x "__MACOSX*"

echo "✓ Built gfw-consent-${VERSION}.zip"
ls -lh "gfw-consent-${VERSION}.zip"