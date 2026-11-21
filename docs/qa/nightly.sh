#!/bin/sh
# The nightly test run (docs/qa/NIGHTLY.md): every suite, a report in ~/EchoLedgerTeam/reports/<date>.md.
#   sh docs/qa/nightly.sh [--quick] [--only=policy,vitest] [--skip=cargo]
cd "$(dirname "$0")/../.." || exit 2
# Rust comes from Homebrew's rustup; git may run in a folder owned by another user.
PATH="/opt/homebrew/opt/rustup/bin:$HOME/.cargo/bin:/opt/homebrew/bin:$PATH"
export PATH GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=safe.directory GIT_CONFIG_VALUE_0='*'
exec node docs/qa/nightly.mjs "$@"
