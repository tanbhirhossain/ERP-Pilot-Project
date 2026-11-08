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
# 89 mig; 35 COA accounts incl. 2115 GRNI; 3 FYs (FY2526-2728) + 36 periods; 16 stocks;
# 1 fallback shift; 200 users; 998 employees; 177 permissions. Pest: 498 passed, 1 skipped.
# The --seed is load-bearing. Migrations create schema only: a migrations-only deploy has no chart
# of accounts and no fallback shift, and every posting path then refuses with a DomainException
# naming the missing code instead of writing documents marked posted with no entries (item 38).
```

3. Serve
```bash
php artisan serve --host=0.0.0.0 --port=8000
# or docker: docker compose up -d && docker compose exec app php artisan migrate:fresh --seed --force
```

4. FY lock: postings require open FY (FY2627 covers 2026-11-02). Add new FY in ACCOUNTS→Fiscal Years when July rolls.

5. Cron (prod): `* * * * * php artisan schedule:run` for queues/attendance.
