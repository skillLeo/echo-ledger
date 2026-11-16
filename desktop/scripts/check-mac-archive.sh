#!/bin/sh
# Checks an updater package (EchoLedger.app.tar.gz) holds EchoLedger.app and nothing else, then unpacks it into a folder
# (scripts/release-mac.sh). Nothing in the archive may hide from the check of the unpacked app or unpack differently in
# the updater:
#   - every entry is EchoLedger.app or under EchoLedger.app/: no other top-level entry, no absolute path;
#   - no `..` or `.` path component, no empty one, and no path twice (a second copy would replace the first);
#   - no hard link (the bundler never writes one), and every symbolic link points inside EchoLedger.app: by its text,
#     and followed on disk to the end of any chain of links (cd -P), so a link to a link cannot get out;
#   - no name with a line break (it would read as two entries here).
#
# Usage: sh check-mac-archive.sh <EchoLedger.app.tar.gz> <empty folder to unpack into>
set -eu

archive=${1:?usage: check-mac-archive.sh <EchoLedger.app.tar.gz> <empty folder>}
dest=${2:?usage: check-mac-archive.sh <EchoLedger.app.tar.gz> <empty folder>}

fail() {
  echo "check-mac-archive: $*" >&2
  exit 1
}

[ -f "$archive" ] || fail "no file $archive"
[ -d "$dest" ] && [ -z "$(ls -A "$dest")" ] || fail "$dest must be an empty folder"

names=$(tar -tzf "$archive") || fail "$archive is not a gzip tar archive"
[ -n "$names" ] || fail "$archive is empty"
bad=$(printf '%s\n' "$names" | awk '
  { n = $0; sub(/\/$/, "", n) }
  n != "EchoLedger.app" && index(n, "EchoLedger.app/") != 1 { print "outside EchoLedger.app: " $0; next }
  n ~ /(^|\/)\.\.?(\/|$)/ || n ~ /\/\// { print "a path with ., .. or an empty part: " $0; next }
  seen[n]++ { print "twice: " $0 }
')
[ -z "$bad" ] || fail "$(printf '%s' "$bad" | head -n 1)"

# Hard links: bsdtar lists them as "name link to target", GNU tar with an "h" type.
if tar -tzvf "$archive" | grep -q -e ' link to ' -e '^h'; then
  fail "a hard link in $archive"
fi

tar -xzf "$archive" -C "$dest"
[ -d "$dest/EchoLedger.app" ] || fail "EchoLedger.app is not at the archive's top"
[ -z "$(find "$dest" -name '*
*')" ] || fail "a name with a line break in $archive"

# Followed on disk to its end (links to links included), each link stays inside the unpacked app.
root=$(cd -P "$dest/EchoLedger.app" && pwd -P)
physical() {
  path=$1
  hops=0
  while [ -L "$path" ]; do
    hops=$((hops + 1))
    [ "$hops" -le 40 ] || return 1
    next=$(readlink "$path")
    case "$next" in
      /*) path=$next ;;
      *) path=$(dirname "$path")/$next ;;
    esac
  done
  [ -e "$path" ] || return 1
  if [ -d "$path" ]; then
    (cd -P "$path" && pwd -P)
  else
    printf '%s/%s\n' "$(cd -P "$(dirname "$path")" && pwd -P)" "$(basename "$path")"
  fi
}

# Every symbolic link, resolved from its own folder, stays inside EchoLedger.app.
links="$dest/.links"
(cd "$dest" && find EchoLedger.app -type l) > "$links"
while IFS= read -r link; do
  target=$(readlink "$dest/$link")
  case "$target" in
    /*) fail "$link points outside EchoLedger.app: $target" ;;
  esac
  resolved=$(printf '%s/%s\n' "$(dirname "$link")" "$target" | awk -F/ '{
    n = 0
    for (i = 1; i <= NF; i++) {
      if ($i == "" || $i == ".") continue
      if ($i == "..") { if (n == 0) { print "/"; exit } n--; continue }
      part[++n] = $i
    }
    out = ""
    for (i = 1; i <= n; i++) out = out (i > 1 ? "/" : "") part[i]
    print out
  }')
  case "$resolved" in
    EchoLedger.app | EchoLedger.app/*) ;;
    *) fail "$link points outside EchoLedger.app: $target" ;;
  esac
  on_disk=$(physical "$dest/$link") || fail "$link leads nowhere: $target"
  case "$on_disk" in
    "$root" | "$root"/*) ;;
    *) fail "$link leads outside EchoLedger.app on disk: $target" ;;
  esac
done < "$links"
rm -f "$links"
