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

# CPU temperature in °C. Raspberry Pi OS exposes it in millidegrees.
if command -v vcgencmd >/dev/null 2>&1; then
    TEMP="$(vcgencmd measure_temp | grep -oE '[0-9]+\.[0-9]+')"
elif [ -r /sys/class/thermal/thermal_zone0/temp ]; then
    TEMP="$(awk '{ printf "%.1f", $1 / 1000 }' /sys/class/thermal/thermal_zone0/temp)"
else
    TEMP="null"
fi

# Number of distinct WLANs in range (best effort; needs a wireless interface).
if command -v nmcli >/dev/null 2>&1; then
    WLAN_COUNT="$(nmcli -t -f SSID dev wifi list 2>/dev/null | grep -c . || echo 0)"
elif command -v iwlist >/dev/null 2>&1; then
    WLAN_COUNT="$(iwlist scan 2>/dev/null | grep -c 'ESSID:' || echo 0)"
else
    WLAN_COUNT=0
fi

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
