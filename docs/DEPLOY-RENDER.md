# Deploying Airking Inventory

A step-by-step guide, assuming no prior deployment experience. Follow it in
order — each phase depends on the one before.

Set aside about 90 minutes for the first run.

---

## What you are building

Three separate pieces, deployed independently:

```
  Browser
     |
     v
  [ Frontend ]  React static site        (Render Static Site / Vercel / Netlify)
     |
     | HTTPS  ->  https://your-api.onrender.com/api
     v
  [ API ]       Laravel in Docker        (Render Web Service)
     |
     | TLS
     v
  [ Database ]  PostgreSQL               (Aiven)
```

They do not live together. The frontend is compiled to static files served by a
CDN; the API is a container; the database is a managed service. Each gets its
own URL, and you tell each one where the others are using environment
variables.

**Build the pieces bottom-up: database, then API, then frontend.** Each layer
needs the one below it to exist first.

---

## Before you start

Accounts (all have free tiers that cover a capstone project):

- **GitHub** — you already have this
- **Aiven** — https://aiven.io — the PostgreSQL database
- **Render** — https://render.com — the API container
- **Brevo** — https://brevo.com — sending email (add this last)

Two things worth knowing about free tiers so nothing surprises you:

- Render free web services **sleep after ~15 minutes of no traffic**. The next
  request takes 30–60 seconds while the container wakes. Normal — not a bug.
- Aiven free PostgreSQL is small but far more than this project needs.

---

## Phase 0 — Push your code

Render deploys *from GitHub*. It cannot see your laptop. Nothing else in this
guide works until your commits are on GitHub.

```bash
cd /c/xampp/htdocs/Airking_Inventory
git push
```

Then open your repository on GitHub, click the **Actions** tab, and wait for the
CI run to finish. **It must be green before you continue.** A red CI means the
code is broken, and deploying broken code only makes it harder to tell what went
wrong later.

---

## Phase 1 — Create the database (Aiven)

1. Sign in to Aiven and click **Create service**.
2. Choose **PostgreSQL**.
3. Pick the **free plan** and a region close to you (for the Philippines,
   Singapore is usually nearest).
4. Name it something obvious, e.g. `airking-db`.
5. Click **Create service**.

The service takes a few minutes to become **Running**. Wait for that — you
cannot connect to it while it is still building.

Once it is running, open it and find **Connection information**. You need five
values:

| Aiven shows | You will use it as |
|---|---|
| Host | `DB_HOST` |
| Port | `DB_PORT` (**not 5432** — Aiven assigns its own) |
| Database | `DB_DATABASE` (usually `defaultdb`) |
| User | `DB_USERNAME` (usually `avnadmin`) |
| Password | `DB_PASSWORD` (click to reveal) |

Copy all five into a scratch file. You will paste them in Phase 3.

> Leave the database completely empty. Do not import anything. The API creates
> all 86 tables itself on first boot.

---

## Phase 2 — Generate your APP_KEY

Laravel encrypts session cookies and some database columns with this key.

```bash
cd /c/xampp/htdocs/Airking_Inventory/back-end
php artisan key:generate --show
```

It prints something like `base64:xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx=`.

**Copy the whole thing, including the `base64:` prefix.** Save it next to your
Aiven values.

> Generate this **once** and never change it. Changing it later makes every
> encrypted value and every logged-in session permanently unreadable.

---

## Phase 3 — Deploy the API (Render)

### 3.1 Create the service

1. Sign in to Render, click **New +** → **Web Service**.
2. Connect your GitHub account and pick the `group34` repository.
3. Choose the branch you pushed (`backupV4`).

Then set:

| Field | Value |
|---|---|
| Language / Runtime | **Docker** |
| Root Directory | **leave blank** |
| Dockerfile Path | `./Dockerfile` |
| Instance Type | Free |

> Root Directory must stay blank even though the Laravel app is in `back-end/`.
> The Dockerfile is at the repository root and reaches into `back-end/` itself.
> Setting it to `back-end` breaks the build.

### 3.2 Set environment variables

Scroll to **Environment Variables** and add each of these. This is the Render
equivalent of a `.env` file — **do not** try to upload a `.env`; Laravel reads
these directly.

**Application**

| Key | Value |
|---|---|
| `APP_NAME` | `Airking Inventory` |
| `APP_ENV` | `production` |
| `APP_KEY` | the `base64:...` from Phase 2 |
| `APP_DEBUG` | `false` |
| `APP_URL` | `https://YOUR-SERVICE.onrender.com` (fill in after the first deploy) |

**Database** — the five values from Phase 1

| Key | Value |
|---|---|
| `DB_CONNECTION` | `pgsql` |
| `DB_HOST` | Aiven host |
| `DB_PORT` | Aiven port |
| `DB_DATABASE` | `defaultdb` |
| `DB_USERNAME` | `avnadmin` |
| `DB_PASSWORD` | Aiven password |
| `DB_SSLMODE` | `require` |

**Mail (Brevo)** — see Phase 6 if you do not have these yet

| Key | Value |
|---|---|
| `MAIL_MAILER` | `smtp` |
| `MAIL_SCHEME` | `smtp` |
| `MAIL_HOST` | `smtp-relay.brevo.com` |
| `MAIL_PORT` | **`2525`** — not 587, see below |
| `MAIL_ENCRYPTION` | `tls` |
| `MAIL_USERNAME` | Brevo SMTP login (an email address) |
| `MAIL_PASSWORD` | Brevo SMTP key |
| `MAIL_FROM_ADDRESS` | your verified sender |
| `MAIL_FROM_NAME` | `Airking Inventory System` |

If you already run this project locally, these nine values are already in
`back-end/.env` — copy them across verbatim, **except `MAIL_PORT`**. Set them
here rather than later: every environment-variable change on Render triggers a
full rebuild, so adding mail now saves you a 5-10 minute redeploy.

> **`MAIL_PORT` is the one value that differs from your local `.env`.**
>
> Render blocks outbound traffic to SMTP ports **25, 465 and 587** on free web
> services ([changelog][render-smtp]). Port 25 stays blocked on every plan; 465
> and 587 work only on a paid instance. Nothing in the app or in Brevo reports
> this usefully — the send simply hangs and fails with:
>
> ```
> Connection could not be established with host "smtp-relay.brevo.com:587":
> stream_socket_client(): Unable to connect (Connection timed out)
> ```
>
> Brevo also listens on **2525**, which is not on Render's block list, and
> upgrades with STARTTLS exactly as 587 does. So `MAIL_PORT=2525` is the whole
> fix — every other `MAIL_*` value stays the same.
>
> Because admin login **fails closed** when mail cannot be sent (Phase 6), this
> misconfiguration locks every administrator out of the deployed system. Getting
> it right the first time is worth the extra minute.

[render-smtp]: https://render.com/changelog/free-web-services-will-no-longer-allow-outbound-traffic-to-smtp-ports

**First-boot only**

| Key | Value |
|---|---|
| `RUN_MIGRATIONS` | `true` |

**Logging and sessions**

| Key | Value |
|---|---|
| `LOG_CHANNEL` | `stderr` |
| `LOG_LEVEL` | `warning` |
| `SESSION_DRIVER` | `database` |
| `SESSION_SECURE_COOKIE` | `true` |
| `CACHE_STORE` | `database` |
| `QUEUE_CONNECTION` | `sync` |
| `BROADCAST_CONNECTION` | `log` |

Leave `FRONTEND_URL` out for now — you do not know it yet. You will add it in
Phase 5.

`back-end/.env.production.example` in the repo lists every key with an
explanation, if you want the full reference.

### 3.3 Deploy

Click **Create Web Service**. The first build takes 5–10 minutes because it
compiles the PHP extensions.

---

## Phase 4 — Check it worked

Watch the deploy log. On success you will see, in order:

```
Preparing database.
Creating migration table ... DONE
Loading stored database schemas.
  .../database/schema/pgsql-schema.sql ... DONE
Nothing to migrate.
listening on port 10000
```

**`Nothing to migrate.` is the success signal.** It means the schema file
created all 86 tables and marked the historical migrations as already applied.

Now verify from the outside. This project has no health endpoint, so poke the
login route with an empty body:

```bash
curl -i -X POST https://YOUR-SERVICE.onrender.com/api/login \
  -H "Content-Type: application/json" -d "{}"
```

**Expect `HTTP/2 422`** with a JSON validation error about the missing fields.
That is a success: it proves Apache, PHP and Laravel routing are all working.

A `500` instead means the app crashed — read the Logs tab. A `502` or a hang
usually means the container never started; check the deploy log for the
`FATAL: APP_KEY is not set` message.

Confirm the tables exist: in Aiven, open your service → **Query editor**:

```sql
SELECT count(*) FROM information_schema.tables
WHERE table_schema = 'public' AND table_type = 'BASE TABLE';
```

**This must return `86`.**

### Then, immediately:

1. Copy your real Render URL into `APP_URL`.
2. Change `RUN_MIGRATIONS` to `false`.
3. Save (Render redeploys automatically).

---

## Phase 5 — Deploy the frontend

1. Render → **New +** → **Static Site**, same repository and branch.

| Field | Value |
|---|---|
| Root Directory | `frontend` |
| Build Command | `npm ci && npm run build` |
| Publish Directory | `build` |

2. Add one environment variable:

| Key | Value |
|---|---|
| `REACT_APP_API_PUBLIC_URL` | `https://YOUR-API.onrender.com/api` |

> Use `REACT_APP_API_PUBLIC_URL`, **not** `REACT_APP_API_URL`. The app contains
> logic that rewrites the API address when it detects a LAN or localhost
> address; `REACT_APP_API_PUBLIC_URL` bypasses that and is the correct variable
> for a public deployment. The `/api` suffix is required.

3. **Add the SPA rewrite rule.** On the static site: **Redirects/Rewrites** →
   **Add Rule**:

   | Field | Value |
   |---|---|
   | Source | `/*` |
   | Destination | `/index.html` |
   | Action | **Rewrite** (not Redirect) |

   Without this, the site loads and clicking around works, but **refreshing any
   page returns a bare `Not Found`** — as does opening a link to anything other
   than `/`. React Router handles navigation in the browser without contacting
   the server, so the gap only appears when the browser actually requests
   `/dashboard` and Render finds no such file.

   The repository already carries an SPA fallback in two formats — `_redirects`
   (Netlify) and `vercel.json` (Vercel) — and **Render reads neither**. Leave
   both files alone; they are correct for those platforms. Render's rule lives
   only in the dashboard.

   Rewrite rules apply immediately, with no rebuild. Verify:

   ```bash
   curl -o /dev/null -w "%{http_code}\n" https://YOUR-FRONTEND.onrender.com/dashboard
   ```

   **Expect `200`.** A `404` means the rule did not save, or was created as a
   Redirect instead of a Rewrite.

4. Note the static site's URL.

5. **Go back to your API service** and add:

| Key | Value |
|---|---|
| `FRONTEND_URL` | `https://YOUR-FRONTEND.onrender.com` |

Save. The API redeploys.

This last step is what makes the browser allow the frontend to call the API. Skip
it and every request fails with a CORS error even though both sites are up.

---

## Phase 6 — Email (Brevo)

The nine `MAIL_*` variables were set in Phase 3.2. This phase is for verifying
them, or for creating the Brevo credentials if you did not already have them.

**If you already had working credentials, skip to "Verify" below.** Do not
regenerate the SMTP key — the old one stops working the moment you do, which
would break your local environment too.

**First-time setup**

1. Create a Brevo account.
2. **Senders, Domains & IPs** -> verify a sender address. Brevo silently
   discards mail from unverified senders, so this is not optional.
3. **SMTP & API** -> **SMTP** -> create an SMTP key.

`MAIL_USERNAME` is the SMTP *login* shown on that page (an email address ending
`@smtp-brevo.com`), and `MAIL_PASSWORD` is the SMTP *key*. Neither is your Brevo
account password.

**Verify**

Use "forgot password" on the deployed site and watch for the email.

If nothing arrives, open **Brevo -> Transactional -> Logs** before touching any
config. That log tells you whether the message reached Brevo at all, which
splits the problem cleanly in two: nothing logged means the API never connected
(wrong host, port, or credentials on Render); logged but not delivered means
Brevo rejected it (usually an unverified `MAIL_FROM_ADDRESS`).

The free tier allows 300 emails/day.

---

## Phase 7 — Create your first login

The database has roles, permissions and statuses, but **no user accounts** —
those were deliberately not copied from your development database.

In Render, open your API service → **Shell**, then:

```bash
php artisan db:seed --class=AdminUserSeeder --force
```

That creates one administrator, defined in
`database/seeders/AdminUserSeeder.php`:

| | |
|---|---|
| Email | `adminchu@airking.com` |
| Password | `adminchu123` |

**Change this password the moment you log in.** It is written in plain text in
the repository, so anyone who can see your code knows it. The seeder is safe to
re-run — it does nothing if an admin already exists.

---

## Phase 8 — Real-time updates (Reverb)

Optional. Skip it and notifications still arrive, on the 60-second poll.

Reverb is a long-running WebSocket server, so it cannot share the API's Apache
process. It does not need a new image: the container's entrypoint ends with
`exec "$@"`, so the **same Docker image** runs Reverb when given a different
start command.

1. **Invent two secrets.** Any 32+ random characters each — `php artisan
   key:generate --show` twice, minus the `base64:` prefix, works:

   - `REVERB_APP_KEY` — the frontend will carry this. Public by design.
   - `REVERB_APP_SECRET` — **server-side only.** Never put it in the frontend.

2. **Render → New + → Web Service**, same repository and branch.

   | Field | Value |
   |---|---|
   | Runtime | Docker |
   | Dockerfile Path | `./Dockerfile` |
   | Docker Command | `php artisan reverb:start --host=0.0.0.0 --port=$PORT` |
   | Instance Type | Free |

   The `--host`/`--port` flags are not optional. Render routes to whatever
   `$PORT` holds, and a server bound to `127.0.0.1` is unreachable from outside
   the container.

3. **Environment** on the Reverb service — the same `APP_KEY` and database
   values as the API, plus:

   | Key | Value |
   |---|---|
   | `REVERB_APP_ID` | `airking` |
   | `REVERB_APP_KEY` | the key from step 1 |
   | `REVERB_APP_SECRET` | the secret from step 1 |
   | `BROADCAST_CONNECTION` | `reverb` |
   | `RUN_MIGRATIONS` | `false` — the API owns migrations |

4. **Point the API at it.** On the **API** service, set:

   | Key | Value |
   |---|---|
   | `BROADCAST_CONNECTION` | `reverb` |
   | `REVERB_APP_ID` / `REVERB_APP_KEY` / `REVERB_APP_SECRET` | identical to step 3 |
   | `REVERB_HOST` | `your-reverb-service.onrender.com` (no scheme, no port) |
   | `REVERB_PORT` | `443` |
   | `REVERB_SCHEME` | `https` |

   Render terminates TLS, so the public port is 443 and the scheme is https
   whatever port Reverb binds inside the container.

5. **Rebuild the frontend with the socket settings.** These are read at *build*
   time, so setting them without a rebuild changes nothing. On the static site:

   | Key | Value |
   |---|---|
   | `REACT_APP_REVERB_APP_KEY` | the key from step 1 |
   | `REACT_APP_REVERB_HOST` | `your-reverb-service.onrender.com` |
   | `REACT_APP_REVERB_PORT` | `443` |
   | `REACT_APP_REVERB_SCHEME` | `https` |

   Then **Manual Deploy → Clear build cache & deploy**. Never set
   `REVERB_APP_SECRET` here — the frontend bundle is public.

6. **Verify.** Log in, open DevTools → Network → WS. Expect a `101 Switching
   Protocols` to your Reverb host, then a `pusher:connection_established` frame.
   A 4xx on `/broadcasting/auth` instead means the API's `REVERB_*` values do not
   match Reverb's.

> **Free-tier behaviour.** Since February 2026 a free service stays awake while
> it receives WebSocket messages, so an open browser keeps it alive. After 15
> idle minutes it spins down, and the next connection waits ~1 minute for the
> cold start — the app falls back to polling meanwhile. Free services also share
> **750 instance hours a month** across your account; two always-on free services
> exceed that, so watch the usage page or put Reverb on a paid instance.

---

## Phase 9 — The scheduler

Without this, `analytics:restock`, `archive:auto`, `warranties:expire`,
`archive:cleanup` and `ledger:drift-check` never run.

A Render Cron Job running `php artisan schedule:run` every minute is the normal
answer and costs about $1/month. If you would rather not pay, the API exposes a
trigger a free external cron can call.

1. **Generate a token.** 32+ random characters, `php artisan key:generate --show`
   minus the `base64:` prefix. Do **not** reuse `APP_KEY`.

2. On the **API** service, set `SCHEDULER_RUN_TOKEN` to it, and redeploy.

   Leaving it unset leaves the route returning 404. It fails closed on purpose:
   forgetting this cannot leave a world-callable trigger behind.

3. **Point a free cron at it** — [cron-job.org](https://cron-job.org),
   EasyCron, or similar:

   | Field | Value |
   |---|---|
   | URL | `https://YOUR-API.onrender.com/api/scheduler/tick` |
   | Method | `POST` |
   | Schedule | every minute |
   | Header | `X-Scheduler-Token: <your token>` |

   **Prefer the header.** The endpoint also accepts `?token=...`, for services
   that cannot set headers, but query strings are written to access logs, proxy
   logs and history in a way headers are not.

4. **Verify.**

   ```bash
   curl -s -o /dev/null -w "%{http_code}\n" -X POST \
     -H "X-Scheduler-Token: YOUR_TOKEN" \
     https://YOUR-API.onrender.com/api/scheduler/tick
   ```

   `200` means it ran. `404` means the token is wrong or unset — deliberately
   indistinguishable, so an attacker cannot tell a bad token from a missing
   route. `429` means more than 6 calls in a minute.

What it does **not** do is pick which commands run — it is one tick of Laravel's
scheduler, and `routes/console.php` still decides what is due. There is no way
to name a command through it; that would be remote command execution with extra
steps.

> **A free API service sleeps after 15 idle minutes.** A cron hitting it every
> minute keeps it permanently awake, which is convenient but consumes instance
> hours continuously. If you are near the 750-hour limit, every 5 or 15 minutes
> is enough for jobs that run daily.

---

## Phase 10 — Database backups

Works out of the box, local-only: the Backup page in the admin sidebar already
runs on the deployed database (`BackupService` dispatches by
`database.default`'s driver -- `pg_dump`/`psql`, both shipped via
`postgresql-client` in the Dockerfile, in production; `mysqldump`/`mysql`
locally). Nothing in this phase is required to use it.

What this phase adds: a backup written to the container disk survives until
the **next deploy**, then it is gone -- Render replaces that disk on every
deploy, the same fact that already applies to uploaded files. A schedule that
runs nightly and a retention policy that keeps 14 days are both real, but
neither outlives a deploy without this.

1. **Get an S3-compatible bucket.** Cloudflare R2 has a free tier (10 GB) and
   needs no card on file, which is the easier path for a capstone project; real
   AWS S3 works identically. Create a bucket, then an access key for it.

2. On the **API** service, set:

   | Key | Value |
   |---|---|
   | `BACKUP_REMOTE_DISK` | `s3` |
   | `AWS_ACCESS_KEY_ID` | from the bucket's access key |
   | `AWS_SECRET_ACCESS_KEY` | from the bucket's access key |
   | `AWS_BUCKET` | your bucket name |
   | `AWS_DEFAULT_REGION` | `auto` (R2) or your AWS region |
   | `AWS_ENDPOINT` | R2 only: `https://<account-id>.r2.cloudflarestorage.com` |
   | `AWS_USE_PATH_STYLE_ENDPOINT` | R2 only: `true` |

   Leave `AWS_ENDPOINT` and `AWS_USE_PATH_STYLE_ENDPOINT` unset for real AWS S3.

3. **Configure the schedule** in the admin Backup page, same form whether or
   not you did step 1-2: enabled, time, frequency, and the three retention
   tiers (daily/weekly/monthly). This writes to `system_settings`, not to an
   environment variable, so it is unaffected by redeploys.

4. Scheduled backups need **Phase 9** wired up (`backup:run` is on the
   scheduler already, but nothing calls `schedule:run` without it). A manual
   **Run Now** from the panel works regardless.

5. **Verify.** Run a backup, then check the row in the panel: `synced_to_remote`
   turns `true` once the copy lands in the bucket (a few seconds after the
   local backup finishes -- sync is best-effort and happens right after, not
   blocking the backup itself). The settings response's `remote.configured`
   field confirms the server sees `BACKUP_REMOTE_DISK` at all.

What remote sync actually buys you: restoring, or downloading, a backup whose
local file is gone (the usual case: backed up before a deploy, restoring
after) transparently pulls it back from the bucket first. Retention deletes the
remote copy too when a backup ages out -- "keep 14 days" means 14 days
everywhere, not forever in the bucket once local disk forgets about it.

A sync failure is logged (`backup.remote_sync failed`) but never turns a
successful local backup into a reported failure -- the local backup it
describes already succeeded. Check the API's logs if `synced_to_remote` stays
`false` after a backup that itself reports success; the usual cause is a wrong
bucket name or a credential typo.

---

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| Build fails immediately | Root Directory set to `back-end` | Clear it — the Dockerfile is at the repo root |
| `FATAL: APP_KEY is not set` | Missing or mistyped key | Must include the `base64:` prefix |
| `could not translate host name` | Wrong `DB_HOST` | Re-copy from Aiven |
| `SSL connection is required` | Missing SSL setting | Set `DB_SSLMODE=require` |
| Log shows migrations running one by one | Schema file not found | Confirm `back-end/database/schema/pgsql-schema.sql` is committed |
| Table count is not 86 | Schema load failed | Read the deploy log above `Nothing to migrate` |
| CORS error in browser console | `FRONTEND_URL` not set | Phase 5 step 4; must have no trailing slash |
| Frontend calls `127.0.0.1` | Wrong variable | Use `REACT_APP_API_PUBLIC_URL`, with `/api` |
| First request takes a minute | Free tier sleeping | Expected |
| Password reset sends nothing | Sender not verified | Verify it in Brevo |
| Refreshing any page shows a bare `Not Found`; `/` still works | No SPA rewrite on the static site | Phase 5 step 3. `_redirects` and `vercel.json` do not apply on Render |
| "We could not send your login code", admins locked out | Render blocks outbound port 587 on free instances | Set `MAIL_PORT=2525`; see Phase 3.2 |
| `login_otp_mail_failed ... Connection timed out` in the logs | Same as above | Same as above |
| `login_otp_mail_failed` never appears in Logs | Laravel logging to a file inside the container | Set `LOG_CHANNEL=stderr` (Phase 3.2) — Render only shows stdout/stderr |
| `data:transfer` fails with `SQLSTATE[08006] timeout expired` | Your network blocks the Aiven port | Not a credentials problem. Run `back-end/scripts/check-aiven.ps1`, then switch networks — see "Known limitations" |

To read logs: Render → your service → **Logs**.

---

## Known limitations

These are real and will affect you. None block deployment, but do not be
surprised by them.

**Uploaded files are deleted on every deploy.** Incident photos, user avatars
and generated reports are written to the container's own disk, and Render
replaces that disk on each deploy. Anything uploaded is lost. Fixing this means
moving to object storage (S3 or Cloudflare R2). *Do this before anyone relies on
incident photos as evidence.*

**Real-time updates are off until you deploy Reverb.** With
`BROADCAST_CONNECTION=log` the app works but pages do not update by themselves,
and notifications arrive on the frontend's 60-second poll instead of instantly.
The poll does chime, so this is a delay rather than silence. Phase 8 below
deploys Reverb and removes the limitation.

**Scheduled jobs do not run until you wire a cron.** This container has no cron,
so `schedule:run` never fires and `analytics:restock`, `archive:auto`,
`warranties:expire`, `archive:cleanup` and `ledger:drift-check` never execute.
Phase 9 below fixes it.

**Your existing data is not there.** The deployment creates an empty system with
configuration only — roles, permissions, statuses, categories, brands, units,
locations. Your products, inventory levels, users and history are still only in
your local MySQL database. Moving them is a separate task: run
`php artisan data:transfer`, documented in `docs/DATA-TRANSFER.md`.

> **The transfer may not run from every network.** It connects from your machine
> straight to Aiven on the port from Phase 1 — which is not 5432, and which
> plenty of managed networks do not allow out. The failure is quiet and easy to
> misread: DNS resolves, the TCP connection is *accepted*, and then PostgreSQL's
> handshake never gets a reply, timing out after 15 seconds with
> `SQLSTATE[08006] timeout expired`.
>
> That signature — accepted, then silence — means a firewall or proxy answered
> on the server's behalf and dropped the traffic. It is **not** a password, an
> IP allow-list, or a `PG_*` problem, and no configuration change fixes it. A
> campus or office network is the usual culprit; the same laptop on a home
> connection normally works, and switching networks is what resolved it here.
>
> `back-end/scripts/check-aiven.ps1` tells you which step fails and prints your
> public IP. Run it before and after switching networks — **if the public IP did
> not change, the network did not actually change**, which is the common reason
> a phone hotspot appears to make no difference.
>
> If no reachable network is available, `data:transfer --sql=<file>` writes the
> whole load to a file that `psql` can run from any machine that *can* reach the
> database. See "When this machine cannot reach the target" in
> `docs/DATA-TRANSFER.md`.

---

## Quick reference

| | |
|---|---|
| API service Root Directory | *(blank)* |
| Dockerfile Path | `./Dockerfile` |
| Frontend Root Directory | `frontend` |
| Frontend Build Command | `npm ci && npm run build` |
| Frontend Publish Directory | `build` |
| Frontend API variable | `REACT_APP_API_PUBLIC_URL` = `https://api.../api` |
| API CORS variable | `FRONTEND_URL` = `https://frontend...` (no trailing slash) |
| Frontend rewrite rule | `/*` → `/index.html`, action **Rewrite** |
| Success message in log | `Nothing to migrate.` |
| Expected table count | `86` |
| `RUN_MIGRATIONS` | `true` first deploy, then `false` |
| `MAIL_PORT` | `2525` on Render (587 is blocked on free instances) |

See also `docs/POSTGRES.md` for how the database layer works and how to
regenerate the schema baseline.
