# Beauty scheduled prices operations

The two flags are read from `config/meli_price_manager.php`:

```env
MELI_BEAUTY_SCHEDULED_PRICES_ENABLED=false
MELI_BEAUTY_SCHEDULED_PRICES_SCHEDULER_ENABLED=false
```

## Modes

- Safe: both flags `false`. Dry-run works; apply, Jobs and the scheduler cannot write prices.
- Manual test: `ENABLED=true`, `SCHEDULER=false`. Dry-run works and controlled apply is allowed with `--item`, `--discount`, `--account`, or explicit `--all`; the scheduler remains inactive.
- Full auto: both flags `true`. The scheduler runs every minute with `withoutOverlapping` and `--apply --all`.

`SCHEDULER=true` has no effect while `ENABLED=false`.

| ENABLED | SCHEDULER | Dry-run | Manual apply | Automatic scheduler |
| --- | --- | --- | --- | --- |
| false | false | Yes | Blocked | Off |
| true | false | Yes | Allowed with explicit scope | Off |
| true | true | Yes | Allowed with explicit scope | On |
| false | true | Yes | Blocked | Off |

## Safe rollout

Keep both flags disabled for the initial production deploy. After deployment and migrations, run these commands manually in the production procedure, not as part of deployment automation:

```bash
php artisan config:clear
php artisan config:cache
```

Dry-run examples:

```bash
php artisan meli:beauty-scheduled-prices --dry-run --verbose
php artisan meli:beauty-scheduled-prices --dry-run --account=ID
php artisan meli:beauty-scheduled-prices --dry-run --discount=ID
php artisan meli:beauty-scheduled-prices --dry-run --item=MLMXXXXXXXX --verbose
```

For a controlled publication test, set the following values, refresh the config cache, and run the item dry-run:

```env
MELI_BEAUTY_SCHEDULED_PRICES_ENABLED=true
MELI_BEAUTY_SCHEDULED_PRICES_SCHEDULER_ENABLED=false
```

Review the account, brand, remote base, discount, target, action and any block shown by `--verbose`. Only then run explicitly:

```bash
php artisan meli:beauty-scheduled-prices --apply --item=MLMXXXXXXXX
```

To test restore, disable the rule or move its window outside the current time, then run the same filtered `--apply` command. The existing state machine restores the confirmed `base_price`; no force-restore bypass is provided.

An unfiltered `--apply` is always rejected, even when the scheduler is enabled. Use `--item` for the initial production test. `--discount` and especially `--account` have broader scope and must be reviewed before use. Use `--all` only when global intent is explicit. The scheduler itself supplies `--all` only when both flags are enabled.

## Immediate rollback

Set both flags to `false`, then run `php artisan config:clear` and `php artisan config:cache`. This prevents new writes. Existing active states cannot be restored automatically while `ENABLED=false`; prefer `ENABLED=true` and `SCHEDULER=false` when the goal is to stop cron while retaining controlled manual restore capability.
