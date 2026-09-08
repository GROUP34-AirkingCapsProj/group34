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

3. Deploy, and note the static site's URL.

4. **Go back to your API service** and add:

| Key | Value |
|---|---|
| `FRONTEND_URL` | `https://YOUR-FRONTEND.onrender.com` |

Save. The API redeploys.

This last step is what makes the browser allow the frontend to call the API. Skip
it and every request fails with a CORS error even though both sites are up.

---

## Phase 6 — Email (Brevo)

1. Create a Brevo account.
2. Go to **Senders, Domains & IPs** and verify a sender email address. Brevo
   silently discards mail from unverified senders.
3. Go to **SMTP & API** → **SMTP** and create an SMTP key.

Add to the **API** service:

| Key | Value |
|---|---|
| `MAIL_MAILER` | `smtp` |
| `MAIL_HOST` | `smtp-relay.brevo.com` |
| `MAIL_PORT` | `587` |
| `MAIL_ENCRYPTION` | `tls` |
| `MAIL_USERNAME` | your Brevo SMTP **login** (an email address) |
| `MAIL_PASSWORD` | your Brevo SMTP **key** |
| `MAIL_FROM_ADDRESS` | the verified sender |
| `MAIL_FROM_NAME` | `Airking Inventory System` |

`MAIL_PASSWORD` is the SMTP key, **not** your Brevo account password.

Test by using "forgot password" on the deployed site.

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

**Real-time updates do not work.** The dashboard's live updates need a Reverb
WebSocket server, which cannot run inside the API container. `BROADCAST_CONNECTION=log`
keeps the app working; the pages simply do not update by themselves. Fixing this
means a second Render service.

**The restock analytics job never runs.** `analytics:restock` is scheduled but
nothing triggers it. Fixing this means a Render Cron Job running
`php artisan schedule:run`.

**Your existing data is not there.** The deployment creates an empty system with
configuration only — roles, permissions, statuses, categories, brands, units,
locations. Your products, inventory levels, users and history are still only in
your local MySQL database. Moving them is a separate task.

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
| Success message in log | `Nothing to migrate.` |
| Expected table count | `86` |
| `RUN_MIGRATIONS` | `true` first deploy, then `false` |

See also `docs/POSTGRES.md` for how the database layer works and how to
regenerate the schema baseline.
