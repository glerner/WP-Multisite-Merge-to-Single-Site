# Lando + CFCE runbook

How `lando rebuild` / `lando start` brings up the WordPress multisite *and*
the CFCE app, and how to diagnose connection failures.

## Architecture (what's actually running)

- App `lc` is defined by `~/sites/wordpress/.lando.yml`.
- Services: `appserver` (WordPress multisite, Apache/PHP), `database`
  (MySQL 8), `mailhog`, `pma`, `cfce` (node:22).
- The `cfce` service mounts `~/sites/wpwm-color-palette-generator` as `/app`
  and runs `npx vercel dev --listen 3000` inside the container. Nothing
  CFCE-related needs to run on the host — `lando start`/`rebuild` does it all.
- A single **global** Traefik proxy
  (`landoproxyhyperion5000gandalfedition_proxy_1`, `app: _global_`) serves
  every Lando app on the machine. It routes by `Host` header.
- TLS is terminated at the proxy with Lando's CA cert; traffic between the
  proxy and containers is plain HTTP. `ssl: true` on the `cfce` service is
  what generates the HTTPS router (a `-secured` router that forwards to
  port 3000).

## URLs

| URL | Serves |
|---|---|
| `https://cfce.lndo.site` | CFCE (proxy → cfce:3000) |
| `http://cfce.lndo.site:8000` | same, HTTP entrypoint |
| `https://<subsite>.lc.lndo.site` | WordPress subsites |
| `http://<subsite>.lc.lndo.site:8000` | same, HTTP → 302 redirect to HTTPS |

`*.lndo.site` resolves to `127.0.0.1` via wildcard DNS — no `/etc/hosts` needed.

## Normal startup procedure

```bash
cd ~/sites/wordpress
lando rebuild        # or: lando start
```

Expect in the URL scan:

- `✔ https://…` `[200]` — good
- `✔ http://…:8000` `[302]` — good (redirect to HTTPS)
- `✖ Request failed with status code 410` on `lawsofattraction` and `elcaro`
  — **expected**: WordPress returns 410 Gone for deleted subsites
- Transient `ECONNREFUSED`/`socket hang up` retries — services still
  booting; the scanner retries each URL up to 25 times

Then verify:

```bash
lando list                     # all services running: true
lando logs -s cfce | tail      # want "Ready! Available at http://localhost:3000"
curl -skI https://cfce.lndo.site   # want 200
curl -skI https://lc.lndo.site     # want 200
```

## Status-code decoder

| Symptom | Meaning | Look at |
|---|---|---|
| `200` | Working | — |
| `302` on `:8000` | HTTP→HTTPS redirect; normal | — |
| `410` | WordPress answering for a **deleted** subsite | Not a fault; remove hostname from `proxy:` list if unwanted |
| `502` / `socket hang up` | Router exists, backend dead or still booting | `lando logs -s <service>` |
| `404`, 19-byte `text/plain` body | Traefik itself — **no router matched** | Container down, or no route generated (e.g. missing `ssl: true` for HTTPS) |
| `ECONNREFUSED` during scan | Stale URL (proxy bound a different port) or proxy still starting | See "port drift" below |

Key distinction: **502 = route exists, backend dead. 404 = route gone**
(container down or never registered). A container can show `running: true`
in `lando list` while its command has crashed — Lando's keepalive keeps the
container up. Always check `lando logs`, not just `lando list`.

## Failure modes seen and their fixes

### `vercel dev` exits: "No existing credentials found"

Vercel CLI auth lives in `~/.local/share/com.vercel.cli/auth.json` — inside
the container's filesystem, so **`lando rebuild` wipes it** (a reboot or
`lando restart` does not). Permanent fix is already in
`~/sites/wordpress/.lando.yml`, under `services:` → `cfce:` → `overrides:` →
`volumes:` — the host's auth dir is bind-mounted into the container for both
users the command may run as (`node`, and `root` when `ssl: true` is set):

```yaml
services:
  cfce:
    type: node:22
    port: 3000
    ssl: true
    overrides:
      volumes:
        - ../wpwm-color-palette-generator:/app
        - ${HOME}/.local/share/com.vercel.cli:/home/node/.local/share/com.vercel.cli
        - ${HOME}/.local/share/com.vercel.cli:/root/.local/share/com.vercel.cli
    command: npx vercel dev --listen 3000
```

(That path is Vercel's *Linux* location; on macOS it's
`~/Library/Application Support/com.vercel.cli`.)

If credentials are missing on the host: `lando ssh -s cfce`, then
`npx vercel login` (device flow — it prints a URL+code to open in a
browser), or `npx vercel login` on the host if the CLI is installed there.

### `https://cfce.lndo.site` → 404 while HTTP works

The HTTPS router wasn't generated — needs `ssl: true` on the service plus a
rebuild so Lando regenerates the Traefik labels.

### Port drift (`:8000`/`:443` → `:8080`/`:444`)

The proxy binds the first free port from its fallback list. If the pinned
ports are occupied at startup (lingering old proxy, another process), it
drifts — and the URL scan then wastes ~25 retries each on stale URLs, which
is where the multi-minute waits come from. Ports are pinned in the global
config `~/.lando/config.yml`:

```yaml
proxyHttpPort: 8000
proxyHttpsPort: 443
```

To see who owns a port: `ss -tlnp | grep -E ':(443|8000) '`. Once free,
`lando poweroff && lando start` returns to the pinned ports.

### Everything 404s after a rebuild

The proxy was recreated and containers hadn't re-registered yet, or
appserver is actually down. Wait a minute, re-curl; if still 404,
`lando list` + `docker restart lc_appserver_1`.

### `cfce.lndo.site:3000` loads but it's not Lando

A `vercel dev` running **on the host** also listens on `localhost:3000`, and
`cfce.lndo.site` resolves to `127.0.0.1` — so `:3000` hits the host process,
not the container. Same code, confusing origin. Stop the host instance
(Ctrl-C in its terminal) and use `https://cfce.lndo.site`.

### App loads but data calls fail

Not a Lando problem — check Supabase. A paused project makes CFCE show
"Database temporarily unavailable" and sends the admin alert email
("CFCE: database unreachable", rate-limited to once per 15 min). Resume the
project in the Supabase dashboard; no Lando change needed.

## Quick commands

```bash
lando list                        # container status
lando logs -s cfce | tail         # cfce startup output
lando ssh -s cfce                 # shell in cfce container (user: node)
docker restart lc_cfce_1          # restart just cfce without a rebuild
docker restart landoproxyhyperion5000gandalfedition_proxy_1   # bounce proxy
lando poweroff && lando start     # full reset, keeps containers' volumes
lando rebuild -s cfce -y          # recreate only the cfce service
```

## Notes

- `lando rebuild` recreates containers (wiped filesystem) **and** recreates
  the shared proxy, so it briefly disturbs every app's URLs — not just the
  service you named.
- With `ssl: true`, the cfce command runs as **root** (not `node`) — that's
  why the auth mount covers both `$HOME`s. Side effect to watch: any files
  the container writes into `/app` (e.g. a fresh `npm install`) may be
  root-owned on the host. If host-side npm or your editor hits permission
  errors in `~/sites/wpwm-color-palette-generator`, run
  `sudo chown -R $USER:$USER ~/sites/wpwm-color-palette-generator`.
- The `mv wp-cli.phar /usr/local/bin/wp` step in `build_as_root` (in `~/sites/wordpress/.lando.yml`)
  can prompt interactively during rebuilds; changing it to `mv -f` avoids that.
- `lando rebuild -s cfce` still restarts all services and the proxy.
- The URL scanner's retries make startup look alarming; judge by the final
  per-URL results and a manual `curl -skI`, not the interim `✖` lines.
