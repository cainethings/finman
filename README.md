# FinMan Pro

FinMan Pro is a mobile-first personal money manager PWA with a `React + TypeScript` frontend and a `PHP + MySQL` backend. It supports email OTP auth, transaction tracking, statement PDF staging/import, budgets/goals/recurring payments, and OpenAI-powered financial insights plus chat.

## Structure

- `frontend/`: Vite-based React PWA
- `api/`: PHP REST API using PDO and MySQL
- `api/database/schema.sql`: MySQL schema
- `api/database/seed.sql`: starter categories

## Frontend setup

1. Copy `frontend/.env.example` to `frontend/.env`
2. Install dependencies with `npm install` inside `frontend/`
3. Run `npm run dev`

## Backend setup

1. Copy `api/.env.example` to `api/.env`
2. Create a MySQL database named `finman_pro`
3. Run `api/database/schema.sql`, then optionally `api/database/seed.sql`
4. Serve the API from `api/public/`, for example with:

```bash
php -S localhost:8080 -t api/public
```

## OpenAI behavior

- `POST /ai/insights/generate` creates insight cards
- `GET /ai/insights/latest` returns the latest saved insights
- `POST /ai/chat/message` creates grounded coaching responses
- `POST /statements/:id/analyze` summarizes low-confidence statement rows

If `OPENAI_API_KEY` is missing, the backend falls back to deterministic local insight/chat behavior so the app still works in development.
