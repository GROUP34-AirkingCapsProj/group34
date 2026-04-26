# Airking Inventory

Monorepo: **React** admin UI (`frontend/`) and **Laravel 12** API (`back-end/`).

## Quick start (local)

1. **Backend**

   ```bash
   cd back-end
   cp .env.example .env
   composer install
   php artisan key:generate
   touch database/database.sqlite
   php artisan migrate
   php artisan serve
   ```

2. **Frontend**

   ```bash
   cd frontend
   cp .env.example .env
   npm install
   npm start
   ```

   Point `REACT_APP_API_URL` in `frontend/.env` at your API (default `http://127.0.0.1:8000/api`).

## GitHub

1. Create a new empty repository on GitHub (no README/license if you will push this tree as the first commit).

2. From the project root:

   ```bash
   git init
   git add .
   git commit -m "Initial commit: Airking Inventory"
   git branch -M main
   git remote add origin https://github.com/YOUR_USER/YOUR_REPO.git
   git push -u origin main
   ```

3. **CI**: On push/PR to `main`, `master`, or `develop`, [GitHub Actions](.github/workflows/ci.yml) runs the frontend production build and Laravel tests.

## Deploy (overview)

You typically host the **API** and **SPA** separately.

| Layer    | Examples | Notes |
|----------|----------|--------|
| Frontend | [Netlify](https://netlify.com), [Vercel](https://vercel.com), Cloudflare Pages | Build command: `cd frontend && npm ci && npm run build`. Publish directory: `frontend/build`. Set env **`REACT_APP_API_URL`** to your public API URL (e.g. `https://api.yourdomain.com/api`). |
| Backend  | [Render](https://render.com), [Railway](https://railway.app), VPS + Nginx, Laravel Forge | PHP 8.2+, `composer install --no-dev`, `php artisan migrate --force`, set `APP_URL`, `APP_KEY`, database, and **`CORS_ALLOWED_ORIGINS`** to your frontend origin(s). |

**CORS:** In `back-end/.env`, set `CORS_ALLOWED_ORIGINS` to a comma-separated list of frontend URLs (no path), for example:

`https://myapp.netlify.app,https://www.mycompany.com`

**SPA routing:** `frontend/public/_redirects` (Netlify) and `frontend/vercel.json` (Vercel) send unknown paths to `index.html` so React Router works after refresh.

**Security:** Never commit `.env` or real databases; use the root [`.gitignore`](.gitignore).
