# SyncBridge Admin

Production-style React 19 + Vite 7 + TypeScript SPA for operating the **SyncBridge** Laravel 11 sync hub (PrestaShop ↔ local DB ↔ AboutYou). Lives at repo root as `admin/`, **not** inside `laravel11/`.

## Stack

- React 19, React Router 7, TanStack Query, Axios, Zustand  
- Headless UI, Radix Tooltip, Heroicons, Lucide, Tailwind CSS v4 (`@tailwindcss/vite`)  
- react-hook-form + Zod + `@hookform/resolvers`  
- i18next, react-helmet-async, date-fns, dompurify, jsPDF  

## Setup

```bash
cd admin
npm install
cp .env.example .env
# Edit .env — set VITE_API_BASE_URL to your Laravel origin + /api/v1
npm run dev
```

Dev server defaults to `http://127.0.0.1:5173`. Laravel must allow CORS for that origin (the bundled Laravel app already allows `*` on `api/*`).

## Environment

| Variable | Description |
|----------|-------------|
| `VITE_API_BASE_URL` | e.g. `http://127.0.0.1:8080/api/v1` |
| `VITE_API_TOKEN` | Optional default token for the login form |
| `VITE_API_TOKEN_HEADER` | `bearer` (default) or `x-api-token` |

Successful JSON responses use `{ ok: true, message?, ... }`. Errors: `{ ok: false, error, errors? }` or HTTP 401 (clears session).

## Build

```bash
npm run build
```

Output in `dist/`. Serve as static files (nginx, S3, etc.) or behind the same host as the API.

## Keyboard shortcuts

- **⌘K** / **Ctrl+K** — command palette  
- **⌘B** / **Ctrl+B** — toggle mobile sidebar  

## API gaps (frontend vs Laravel today)

Documented in code and UI where relevant:

- **Order detail** — `OrderResource` is minimal; customer/address/timeline data needs API expansion.  
- **Product payload tab** — full AY/PS payload fields are not returned on `GET /products/{id}` yet.  
- **Orders list** — server ignores extra query params; client-side filter applies to the **current page** only.  
- **Product sort** — server has no `sort_by`; sorting is **client-side on the loaded page**.  
- **Run history / retry REST** — UI uses mocks until `/sync-runs`, `/sync-logs`, `/retry-jobs`, etc. exist.  
- **dry_run / test_mode** — stored in `settings`; status snapshot reads them via `POST /sync` `command: "status"`.

## Scripts

Scripts call `typescript` and `vite` via explicit `node …/lib/tsc.js` and `node …/vite/bin/vite.js` so `npm run build` works in environments where local `node_modules/.bin` is not on `PATH`.

- `npm run dev` — Vite dev server  
- `npm run build` — `tsc -b` + production bundle  
- `npm run lint` — ESLint (flat config)  
- `npm run preview` — preview production build  
