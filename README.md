# Pixel Studio

A small Laravel 12 + Vue 3 + Inertia app on top of the (simulated) Pixelforge image API. Users register, generate images, watch the status change, see an estimate before they submit and the exact cost afterwards. They pay Pixelforge's cost plus 25%. Failed generations are free.

Stack: PHP 8.4+, Laravel 12, Inertia 2, Vue 3 (the official Laravel Vue starter kit), Tailwind, SQLite, database queue, Pest.

## What it does

| Page | What |
| --- | --- |
| `/generate` | Prompt, size, quality, up to 4 reference image URLs. Shows "about $X, at most $Y" live as you type and your balance. Submit is disabled when the balance is below the maximum. |
| `/generations` | History, newest first: thumbnail, status badge, error message, estimate, charge. Refreshes itself every 3 seconds while anything is unfinished. |
| `/credits` | Balance, ledger, "buy" $1 / $5 / $20 (fake, no payment). Every new user gets $2.00 welcome credit. |
| `/admin/report` | Admins only. Per UTC day and per user: generations, charged, provider cost, profit, plus a live comparison of our daily provider cost against Pixelforge's `GET /v1/usage` (shows a tick or a cross). |
| `POST /webhooks/pixelforge` | Pixelforge webhooks. |

## Setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate        # creates database/database.sqlite
```

Fill in `.env`:

| Variable | Meaning |
| --- | --- |
| `PIXELFORGE_API_KEY` | Pixelforge API key (use one key per environment) |
| `PIXELFORGE_WEBHOOK_SECRET` | The `whsec_...` signing secret of the SAME key |
| `PIXELFORGE_BASE_URL` | Defaults to `https://pixelforge.promptedit.dev/v1` |

Run it:

```bash
composer run dev     # web server, queue worker, scheduler, vite
```

Webhooks need a public URL (set it in the Pixelforge dashboard as `https://<host>/webhooks/pixelforge`); locally use a tunnel. Without webhooks the app still works, the poller (below) just takes up to a minute longer to notice results.

Make an admin (register first, then):

```bash
php artisan app:make-admin you@example.com
```

Tests (no network: Pixelforge is faked with `Http::fake`):

```bash
php artisan test
```

## Deploy (Docker)

```bash
cp .env.example .env     # set APP_KEY (php artisan key:generate --show), APP_ENV=production, APP_DEBUG=false, APP_URL, PIXELFORGE_*
docker compose up -d --build
```

Three containers from one image share a single volume (`/data`: the SQLite file and Laravel's `storage`):

- `web`: FrankenPHP on `127.0.0.1:8210`, plain HTTP. Put a reverse proxy with TLS in front. Runs migrations on start.
- `queue`: `php artisan queue:work --tries=1`
- `scheduler`: `php artisan schedule:work` (runs the poller every minute)

## Design decisions

**Money is an integer number of picodollars (1 USD = 10^12), never a float.** Pixelforge costs have 9 decimals (nanodollars). Our price is cost x 1.25, which is nanodollars x 1250 picodollars: always a whole number. So the 25% markup needs no rounding at all, and sums can be compared exactly. `App\Support\Money` parses and formats; rates like `"12.50"` per 1M tokens become picodollars per token (rate x 10^6) with string arithmetic, and a rate with more than 6 decimals throws instead of being rounded. Cost = sum of tokens x rate, using the rates stored on that generation when it was created, not today's pricing. (There is one guarded `ceil` in `Money::withMarkup`: it can never trigger with rates of up to 2 decimals, and exists so a future odd rate could not produce a fraction or undercharge.)

**Estimate and hold.** Rates and token rules are read from `GET /v1/pricing` (cached 10 minutes), never hardcoded; a clearly labelled fallback constant is used only if that endpoint is down. One PHP service (`CostEstimator`) computes both a typical and a maximum cost: prompt tokens = ceil(chars / 4), the maximum adds 15%; 765 tokens per reference image; exact output tokens by quality and size; reasoning = midpoint (typical) or top (maximum) of the quality's range. The form calls a small JSON endpoint with the prompt length, so there is no pricing logic in TypeScript, and the same service sets the hold.

**Hold / release ledger.** `credit_transactions` is an append-only ledger and `users.balance_pico` is its cached sum; `Ledger` is the only code that writes either, always in one DB transaction. On submit, in one transaction with the user row locked, we check `balance >= maximum charge` and write a `hold` of the maximum. When the generation ends we write a `release` of the same hold and, only if it succeeded, a `charge` of the real amount. A failed generation therefore nets to exactly zero. A unique index on `(generation_id, type)` means a generation can have at most one hold, one release and one charge: a double charge is impossible even if code had a bug. If the real charge is ever above the hold (the hold used cached pricing), the balance can dip slightly below zero; it is not clamped.

**One state service.** `ApplyGenerationState` is the single place where a generation changes state and money moves. Webhooks, the poller and the submit job all feed it a Pixelforge generation object. It locks the row, only moves forward (`pending_submit < queued < processing < succeeded / failed`), never changes a final state, and settles (usage, cost, charge, release, balance) in the same transaction as the status change. So duplicates, out-of-order events and races between the webhook and the poller cannot double-charge or move a generation backwards. A later event may only refresh a succeeded generation's signed image URL.

**Webhooks.** The route is outside the `web` middleware group (no session or CSRF). The signature is verified on the raw body (`HMAC-SHA256` of `<t>.<body>`, `hash_equals`, timestamp within 300 s), and an empty secret fails closed. De-duplication is the unique `event_id` insert: in one transaction we insert the event row and apply it, a unique violation means "duplicate, answer 204 and do nothing", and if applying fails the event row rolls back so Pixelforge's retry is processed. The generation is found by `provider_id`, or by `metadata.local_id` when the webhook arrives before our own `POST /v1/generations` has returned. The work is done inline rather than in a queued job: it only touches our own database, takes milliseconds, and answering 2xx only after the state is saved is what makes Pixelforge's retries useful.

**Submitting.** `SubmitGeneration` posts with `Idempotency-Key` = the generation's uuid (also sent as `metadata.local_id`), so a retry can never create or bill a second generation. 429 (rate / concurrency), 5xx and network errors are released back to the queue after `Retry-After` seconds, waiting longer each time, at most 6 attempts, then the generation fails with `submit_gave_up`. Permanent rejections (`content_policy_violation`, `validation_failed`, `daily_limit_exceeded`, ...) fail it at once. Every failure path releases the hold.

**Polling fallback.** Webhook delivery is not guaranteed. `php artisan app:poll-generations` runs every minute: unfinished generations with a provider id and no update for 60 s are fetched with `GET /v1/generations/{id}` and passed through `ApplyGenerationState` (at most 20 per run, to stay far below 60 requests/minute). Generations stuck in `pending_submit` for over 5 minutes are submitted again (safe because of the idempotency key).

**SQLite.** The web process, queue worker and scheduler share one file, so it runs in WAL mode with a busy timeout and `IMMEDIATE` transactions (the write lock is taken at the start of a transaction, which avoids read-then-write races). `lockForUpdate()` is a no-op on SQLite, but on MySQL or Postgres the same code takes real row locks.

**Admin report.** Grouped by the UTC day of `completed_at` (Pixelforge bills on the day a generation completed) and only succeeded generations. Sums are done in SQL on the integer columns. Provider cost is shown with 9 decimals and compared as a string with `/v1/usage` `cost_usd` for each day.

### Known limits

- If a submission fails permanently on our side (for example 6 network errors in a row) but Pixelforge had actually accepted one of those requests, that generation is already marked failed and refunded, and a later "succeeded" event is ignored (final states never change). The Pixelforge usage report would then show a cost we did not bill. The idempotency key makes this very unlikely, and the admin report's tick or cross would reveal it.
- Image files are not copied: Pixelforge's signed URLs expire after 24 hours, after which the history shows a placeholder.
- The fake "buy credits" has no payment, and there is no email verification.
- `/v1/usage` covers the API key the app uses. If other tools use the same key, the comparison will show a cross.

## Instructions for reviewers

1. Open the site and register: you get $2.00 of credit. (Or use any account you made earlier.)
2. Go to Generate, type a prompt. The estimate ("about ... at most ...") changes as you type and as you change size, quality or reference URLs.
3. Submit and watch History: queued, processing, succeeded. Hover a dollar amount to see the exact value.
4. Test keywords from Pixelforge go anywhere in the prompt:
   - `[fail]`: accepted, then fails: shows the error, charged $0, balance restored.
   - `[blocked]`: rejected at submission (content policy): fails at once, balance restored.
   - `[overloaded]`: Pixelforge answers 503: watch it retry with the same idempotency key (then give up after 6 tries).
   - `[slow]`: takes 60 to 90 seconds, good for watching the live status.
   - `[duplicate]`: every webhook is delivered twice: still charged once.
5. Credits: buy $1 / $5 / $20, see the ledger (hold, release, charge rows).
6. Admin report: an admin runs `php artisan app:make-admin <email>`; the report is then at `/admin/report`.
