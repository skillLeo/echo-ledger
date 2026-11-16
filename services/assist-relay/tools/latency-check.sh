#!/bin/sh
# Live Assist relay: which VPS region is closest to this network?
#
# Measures the TCP connect time and the TLS handshake to each candidate region (median of 5 tries), then prints a
# table sorted by TCP time and the top 3. Takes about a minute. It only opens HTTPS connections (a HEAD request) to
# the providers' public speed-test, looking-glass and API hosts listed below, and sends nothing anywhere else.
#
#   sh latency-check.sh          # 5 tries per region
#   sh latency-check.sh 9        # more tries
#
# Needs curl (built into macOS and most Linux systems). On Windows, run latency-check.ps1 instead.

set -u

TARGETS='Hetzner|Falkenstein, DE (fsn1)|fsn1-speed.hetzner.com
Hetzner|Nuremberg, DE (nbg1)|nbg1-speed.hetzner.com
Hetzner|Helsinki, FI (hel1)|hel1-speed.hetzner.com
Hetzner|Singapore (sin)|sin-speed.hetzner.com
DigitalOcean|Frankfurt, DE (fra1)|fra1.digitaloceanspaces.com
DigitalOcean|Amsterdam, NL (ams3)|ams3.digitaloceanspaces.com
DigitalOcean|London, UK (lon1)|lon1.digitaloceanspaces.com
DigitalOcean|Bangalore, IN (blr1)|blr1.digitaloceanspaces.com
DigitalOcean|Singapore (sgp1)|sgp1.digitaloceanspaces.com
Vultr|Frankfurt, DE|fra-de-ping.vultr.com
Vultr|Amsterdam, NL|ams-nl-ping.vultr.com
Vultr|London, UK|lon-gb-ping.vultr.com
Vultr|Mumbai, IN|bom-in-ping.vultr.com
Vultr|Bangalore, IN|blr-in-ping.vultr.com
Vultr|Delhi NCR, IN|del-in-ping.vultr.com
Vultr|Singapore|sgp-ping.vultr.com
Vultr|Tel Aviv, IL|tlv-il-ping.vultr.com
Akamai (Linode)|Frankfurt, DE (de-fra)|de-fra-1.linodeobjects.com
Akamai (Linode)|Mumbai, IN (in-bom)|in-bom-1.linodeobjects.com
Akamai (Linode)|Chennai, IN (in-maa)|in-maa-1.linodeobjects.com
Akamai (Linode)|Singapore (sg-sin)|sg-sin-1.linodeobjects.com
AWS|Frankfurt, DE (eu-central-1)|ec2.eu-central-1.amazonaws.com
AWS|UAE (me-central-1)|ec2.me-central-1.amazonaws.com
AWS|Bahrain (me-south-1)|ec2.me-south-1.amazonaws.com
AWS|Mumbai, IN (ap-south-1)|ec2.ap-south-1.amazonaws.com'

PARALLEL=4

median() {
  sort -n | awk '{ v[NR] = $1 } END {
    if (NR == 0) { print "-"; exit }
    if (NR % 2) printf "%.1f\n", v[(NR + 1) / 2]; else printf "%.1f\n", (v[NR / 2] + v[NR / 2 + 1]) / 2
  }'
}

# One region: "Provider|Region|host" and the number of tries. Prints "sort key|tcp|tls|provider|region|host|note".
measure() {
  line=$1
  tries=$2
  provider=${line%%|*}
  rest=${line#*|}
  region=${rest%%|*}
  host=${rest#*|}
  tcp=''
  tls=''
  note=''
  i=0
  while [ "$i" -lt "$tries" ]; do
    i=$((i + 1))
    out=$(curl -4 -s -o /dev/null -I --connect-timeout 4 -m 8 \
      -w '%{time_namelookup} %{time_connect} %{time_appconnect}' "https://$host/" 2>/dev/null)
    code=$?
    if [ "$code" -eq 6 ]; then
      note='does not resolve'
      break
    fi
    sample=$(printf '%s\n' "$out" | awk '$2 > 0 { printf "%.1f", ($2 - $1) * 1000; if ($3 > 0) printf " %.1f", ($3 - $2) * 1000; print "" }')
    [ -n "$sample" ] || continue
    tcp="$tcp ${sample%% *}"
    case $sample in
      *' '*) tls="$tls ${sample#* }" ;;
    esac
  done
  tcp_median=$(printf '%s\n' $tcp | grep . | median)
  tls_median=$(printf '%s\n' $tls | grep . | median)
  if [ "$tcp_median" = "-" ]; then
    [ -n "$note" ] || note='no answer'
    key=999999
  else
    key=$tcp_median
    [ "$tls_median" != "-" ] || note='no TLS answer'
  fi
  printf '%s|%s|%s|%s|%s|%s|%s\n' "$key" "$tcp_median" "$tls_median" "$provider" "$region" "$host" "$note"
}

if [ "${1:-}" = "--measure" ]; then
  measure "$2" "$3"
  exit 0
fi

tries=${1:-5}
case $tries in
  '' | *[!0-9]*) echo "Usage: sh latency-check.sh [tries]"; exit 2 ;;
esac
if ! command -v curl >/dev/null 2>&1; then
  echo "This check needs curl."
  exit 1
fi

results=$(mktemp "${TMPDIR:-/tmp}/latency-check.XXXXXX") || exit 1
trap 'rm -f "$results"' EXIT INT TERM

echo "Live Assist relay: latency from this network to each VPS region (median of $tries tries)"
echo "Started $(date '+%Y-%m-%d %H:%M %Z'). This takes about a minute."
echo

printf '%s\n' "$TARGETS" | xargs -P "$PARALLEL" -I {} sh "$0" --measure "{}" "$tries" > "$results"

sort -t '|' -k1,1n "$results" | awk -F '|' '
  BEGIN { printf "%-3s  %-16s %-29s %7s %7s  %s\n", "#", "Provider", "Region", "TCP ms", "TLS ms", "Host" }
  {
    rank = ($2 == "-") ? "-" : ++n
    printf "%-3s  %-16s %-29s %7s %7s  %s%s\n", rank, $4, $5, $2, $3, $6, ($7 == "" ? "" : "  (" $7 ")")
    if ($2 != "-" && n <= 3) top[n] = $4 " " $5 " (" $2 " ms)"
  }
  END {
    print ""
    print "Top 3:"
    for (i = 1; i <= 3; i++) if (i in top) printf "  %d. %s\n", i, top[i]
    print ""
    print "TCP ms is about one round trip to the region; TLS ms is the handshake on top of it."
    print "Lower is better. Each suggestion pays about one round trip on top of the model time."
  }'
