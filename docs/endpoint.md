# Redshirt Heartbeat API (`endpoint.php`)

Authenticated JSON endpoint for device heartbeats — e.g. a Raspberry Pi that
pings via cron. Every accepted request is written to the `heartbeat_log` table
with `source = 'api'`.

## Request

```
POST /endpoint.php
Authorization: Bearer <BEARER_TOKEN>
Content-Type: application/json
```

`BEARER_TOKEN` comes from the `.env` on the server (one level above the web
root). Requests without a valid token get `401`.

### Body fields

| Field    | Type   | Required | Notes                                             |
|----------|--------|----------|---------------------------------------------------|
| `device` | string | **yes**  | Device name, stored as `device_id` (max 128).     |
| `message`| string | no       | Short human-readable status (max 255).            |
| `hostname`| string| no       | Optional reported hostname (max 255).             |
| _any other fields_ | — | no  | Preserved verbatim in the `payload` JSON column.  |

Free-form metrics such as `temperature_c` or `wlan_count` need no schema change
— the full JSON body is stored in `payload`.

### Example body

```json
{
  "device": "raspi-01",
  "message": "up | 47.8°C | 12 WLANs",
  "temperature_c": 47.8,
  "wlan_count": 12
}
```

## Responses

| Status | Meaning                                              |
|--------|------------------------------------------------------|
| `201`  | Logged. Body: `{"status":"ok","id":<row id>}`        |
| `400`  | Invalid JSON or missing `device`                     |
| `401`  | Missing/invalid Bearer token                         |
| `405`  | Method other than POST                               |
| `413`  | Body missing or larger than 64 KB                    |
| `500`  | Server misconfigured or internal error               |

## Call examples

### cURL (Linux / macOS / Git Bash)

```bash
curl -X POST https://redshirt.nichtlieb.de/endpoint.php \
     -H "Authorization: Bearer DEIN_GEHEIMER_TOKEN" \
     -H "Content-Type: application/json" \
     -d '{"device": "test-curl", "message": "manueller Test", "temperature_c": 42.0, "wlan_count": 7}'
```

### PowerShell (Windows)

```powershell
$Url   = "https://redshirt.nichtlieb.de/endpoint.php"
$Token = "DEIN_GEHEIMER_TOKEN"

$Headers = @{
    "Authorization" = "Bearer $Token"
    "Content-Type"  = "application/json"
}

$Body = @{
    device        = $env:COMPUTERNAME
    message       = "Test-Ping via PowerShell"
    temperature_c = $null
    wlan_count    = (netsh wlan show networks mode=bssid | Select-String '^SSID' | Measure-Object).Count
} | ConvertTo-Json

Invoke-RestMethod -Uri $Url -Method Post -Headers $Headers -Body $Body
```

### Python (cross-platform)

```python
import platform
import requests

ENDPOINT_URL = "https://redshirt.nichtlieb.de/endpoint.php"
TOKEN = "DEIN_GEHEIMER_TOKEN"

data = {
    "device": platform.node(),
    "message": f"Ping von {platform.system()} {platform.release()}",
    "temperature_c": None,
    "wlan_count": None,
}

resp = requests.post(
    ENDPOINT_URL,
    json=data,
    headers={"Authorization": f"Bearer {TOKEN}"},
    timeout=10,
)
print(resp.status_code, resp.text)
```

### Bash cronjob (Raspberry Pi)

A ready-to-use script lives at [`examples/raspi-heartbeat.sh`](../examples/raspi-heartbeat.sh).
It reads the CPU temperature and counts nearby WLANs, then POSTs the heartbeat.

```bash
cp examples/raspi-heartbeat.sh /usr/local/bin/redshirt-heartbeat
chmod +x /usr/local/bin/redshirt-heartbeat
# edit ENDPOINT_URL / TOKEN inside the script (or export REDSHIRT_URL / REDSHIRT_TOKEN)

crontab -e
# send a heartbeat every 5 minutes:
*/5 * * * * /usr/local/bin/redshirt-heartbeat >/dev/null 2>&1
```

## Note: Apache and the `Authorization` header

Some Apache/PHP setups do not pass the `Authorization` header to PHP. The
endpoint already falls back to `getallheaders()`, but if `401` persists despite
a correct token, add this to a `.htaccess` in the web root:

```apache
SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1
```
