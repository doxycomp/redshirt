#!/usr/bin/env bash
#
# Redshirt heartbeat sender for a Raspberry Pi (or any Linux box).
#
# Sends device name, CPU temperature and the number of nearby WLANs to the
# Redshirt API. Designed to be run from cron.
#
# Setup:
#   1. cp raspi-heartbeat.sh /usr/local/bin/redshirt-heartbeat
#   2. chmod +x /usr/local/bin/redshirt-heartbeat
#   3. Edit ENDPOINT_URL and TOKEN below (or export them in the environment).
#   4. Add a cron entry, e.g. every 5 minutes:
#        crontab -e
#        */5 * * * * /usr/local/bin/redshirt-heartbeat >/dev/null 2>&1
#
set -euo pipefail

# --- Config (override via environment if you prefer) ------------------------
ENDPOINT_URL="${REDSHIRT_URL:-https://redshirt.nichtlieb.de/endpoint.php}"
TOKEN="${REDSHIRT_TOKEN:-dein_geheimer_api_token}"

# --- Collect metrics --------------------------------------------------------
DEVICE="$(hostname)"

# CPU temperature in °C -> a clean number, or the JSON literal null.
# Validate before use: an empty/odd value would produce invalid JSON
# ("temperature_c": ,). Raspberry Pi OS exposes millidegrees in thermal_zone0.
TEMP="null"
TEMP_RAW=""
if command -v vcgencmd >/dev/null 2>&1; then
    TEMP_RAW="$(vcgencmd measure_temp 2>/dev/null | grep -oE '[0-9]+(\.[0-9]+)?' | head -n1 || true)"
elif [ -r /sys/class/thermal/thermal_zone0/temp ]; then
    TEMP_RAW="$(awk '{ printf "%.1f", $1 / 1000 }' /sys/class/thermal/thermal_zone0/temp 2>/dev/null || true)"
fi
[[ "$TEMP_RAW" =~ ^[0-9]+(\.[0-9]+)?$ ]] && TEMP="$TEMP_RAW"

# Number of distinct WLANs in range -> always exactly one integer.
# NB: `grep -c` prints "0" and exits 1 on zero matches; the old `|| echo 0`
# then printed a SECOND "0", so WLAN_COUNT became "0\n0" and broke the JSON.
WLAN_COUNT=0
if command -v nmcli >/dev/null 2>&1; then
    WLAN_COUNT="$(nmcli -t -f SSID dev wifi list 2>/dev/null | grep -c . || true)"
elif command -v iwlist >/dev/null 2>&1; then
    WLAN_COUNT="$(iwlist scan 2>/dev/null | grep -c 'ESSID:' || true)"
fi
case "$WLAN_COUNT" in ''|*[!0-9]*) WLAN_COUNT=0 ;; esac

MESSAGE="up | ${TEMP}°C | ${WLAN_COUNT} WLANs"

# --- Build JSON payload -----------------------------------------------------
# TEMP is a bare number (or the literal null), so it is not quoted.
PAYLOAD=$(cat <<JSON
{"device": "${DEVICE}", "message": "${MESSAGE}", "temperature_c": ${TEMP}, "wlan_count": ${WLAN_COUNT}}
JSON
)

# --- Send -------------------------------------------------------------------
curl -fsS -X POST "$ENDPOINT_URL" \
    -H "Authorization: Bearer ${TOKEN}" \
    -H "Content-Type: application/json" \
    -d "$PAYLOAD"
