# LogWatch collector

Summarises one UTC day of a Forge server's logs and state as a single JSON document (a few KB). Phase 1 only prints JSON; posting it to the hub comes later.

Plain PHP 8.4+ with no dependencies. It must run as root, because it reads auth.log, the journal, fail2ban.log and other users' crontabs and keys.

## What it reads

| Section | Source |
|---|---|
| `auth` | `/var/log/auth.log*`: SSH logins (with tailnet or public scope), failed attempts, sudo, account changes |
| `fail2ban` | `/var/log/fail2ban.log*`: bans per jail |
| `firewall` | `/var/log/ufw.log*`: blocked connections and probed ports |
| `system` | `journalctl -p warning` (UFW lines skipped), systemd failures and restart loops, OOM kills, unattended-upgrades, `df` |
| `nginx` | the shared `access.log` (catch-all and redirect blocks; scanner baseline), any per-site access logs that are turned on, and per-site `<id>-error.log` |
| `apps` | `storage/logs/*.log` for each site (Laravel and Craft) |
| `state` | today's snapshot compared with the last run: listening ports, users, sudoers, SSH keys, sshd config, cron and timers, untracked or modified files in webroots (via git), CPU per process, pending updates, TLS expiry |

Sites are discovered from `/etc/nginx/sites-enabled` and grouped by Forge site ID. Each site is named by its first custom domain.

## What never leaves the server

- **IPs:** replaced by a 12-character HMAC token plus scope (tailnet, public, private, loopback). Country and ASN are added if GeoLite2 and MaxMind's reader are installed.
- **Request paths:** query strings are dropped.
- **Free text:** IPs are tokenised, emails become `[email]`, strings of 32 or more characters that look like secrets become `[redacted]`, control characters are stripped, and the text is truncated.
- **App logs:** only the level, exception class and a scrubbed first line of the message are sent. Stack traces and context arrays never are.
- **SSH keys:** only fingerprints and comments are sent.

## Local state (`/etc/logwatch/state`, root only)

- `snapshot.json`: the last run's snapshot, used for the diffs.
- `seen.json`: 14 days of path and message signatures, used to flag things never seen before.
- `ipmap/DATE.json`: token-to-IP map, kept 7 days, for looking into a flagged token by hand.

## Usage

```
sudo bin/logwatch-collect                          # yesterday (UTC)
sudo bin/logwatch-collect --date=2026-11-07 --pretty
sudo bin/logwatch-collect --no-state               # trial run: don't write state
sudo bin/logwatch-collect --post=https://HUB/api/v1/summaries   # send to the hub
bin/logwatch-collect --help
```

With `--post`, the summary is sent with the token from `/etc/logwatch/token` (root, mode 600; issued by `php artisan logwatch:server-add` on the hub). It retries network errors, 5xx and 429 three times. If it still fails, the summary waits in `state/outbox/` and is sent before the next day's. The exit code is non-zero when today's send fails, so Forge's scheduler shows it.

The secret goes in `/etc/logwatch/secret` (root, mode 600). It must be the same on every server. See the project plan.

Optional `/etc/logwatch/config.json`:

```json
{ "server": "jfm", "burst_per_minute": 120, "tls_warn_days": 14, "geoip_dir": "/var/lib/GeoIP" }
```

## Tests

```
php tests/run.php
```

The tests build a fake server tree in `tests/tmp` from `tests/fixtures/root`, then run the collector twice: once as a baseline, and once after planting a new port, SSH key, cron file, user and PHP file. They check the summaries, the diffs, and that no fixture IP, email, secret or stack trace reaches the output. The fixtures use only documentation IP ranges.
