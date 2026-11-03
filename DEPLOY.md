# Deploy — ERP Pilot (Chattogram)

1. Clone & env
```bash
git clone <repo> && cd ERP-Pilot-Project
cp .env.example .env && php artisan key:generate
# SQLite dev: DB_CONNECTION=sqlite (default, database.sqlite)
# MySQL prod: edit .env DB_CONNECTION=mysql, DB_HOST=db, etc.
```

2. Install & migrate
```bash
composer install --no-dev
npm install && npm run build
php artisan migrate:fresh --seed --force
# 78 mig, 35 COA, 3 FYs (FY2526-2728), 16 stocks, 4 Pest tests PASS
```

3. Serve
```bash
php artisan serve --host=0.0.0.0 --port=8000
# or docker: docker compose up -d && docker compose exec app php artisan migrate:fresh --seed --force
```

4. FY lock: postings require open FY (FY2627 covers 2026-11-02). Add new FY in ACCOUNTS→Fiscal Years when July rolls.

5. Cron (prod): `* * * * * php artisan schedule:run` for queues/attendance.
