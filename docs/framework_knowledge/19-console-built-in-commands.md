# 19. Console Built-in Commands

## Complete Command List (39 commands)

### Cache (7)

| Command | Description |
|---------|-------------|
| `cache:clear` | Clear application cache (supports `--store` option) |
| `config:cache` | Compile all `app/config/*.php` into `storage/cache/config.cache.php` |
| `config:clear` | Delete the compiled config cache file |
| `route:cache` | Compile all routes into `storage/cache/routes.cache.php` (skips closures) |
| `route:clear` | Delete the compiled route cache file |
| `view:cache` | Pre-compile all view templates into `storage/cache/views/` |
| `view:clear` | Delete compiled view cache files |

### Routes (1)

| Command | Description |
|---------|-------------|
| `route:list` | Display all registered routes (supports `--method`, `--path` filters) |

### Generators (8)

| Command | Description |
|---------|-------------|
| `make:controller` | Generate controller (supports `--resource` for CRUD) |
| `make:middleware` | Generate middleware class |
| `make:request` | Generate FormRequest class |
| `make:model` | Generate model class |
| `make:job` | Generate queue job class |
| `make:command` | Generate custom console command |
| `make:repository` | Generate lightweight query-builder repository |
| `make:dto` | Generate DTO/value object class |

### Database / Backup (4)

| Command | Description |
|---------|-------------|
| `db:backup` | Create database backup |
| `backup:run` | Run full backup (supports `--only-db`, `--only-files`) |
| `backup:clean` | Clean old backups |
| `db:seed` | Run database seeders |

### App Runtime (8)

| Command | Description |
|---------|-------------|
| `serve` | Start PHP development server |
| `key:generate` | Generate application key |
| `storage:link` | Create symbolic link for storage |
| `down` | Put application in maintenance mode (`--message`, `--retry`, `--refresh`, `--secret`, `--status`, `--redirect`, `--render`) |
| `up` | Bring application out of maintenance mode |
| `env` | Display current environment |
| `env:check` | Validate env keys/types and feature-gated secrets (`--strict`, `--ci`, `--show-values`) |
| `about` | Display application information |

### Security & Performance (4)

| Command | Description |
|---------|-------------|
| `security:audit` | Run OWASP-aligned baseline checks (`--strict`, `--ci`) |
| `auth:security:test` | Run auth hardening tests (`--strict`, `--ci`) |
| `perf:benchmark` | Run routing/validation/query benchmark workload |
| `perf:report` | Show and optionally export/reset performance monitor report (`--json`, `--export`, `--limit`, `--reset`) |

### Queue (5)

| Command | Description |
|---------|-------------|
| `queue:work` | Process queue jobs (supports `--queue`, `--tries`, `--timeout`, `--sleep`, `--once`) |
| `queue:retry` | Retry failed job(s) (supports `all` or specific ID) |
| `queue:failed` | List all failed jobs |
| `queue:flush` | Delete all failed jobs |
| `queue:clear` | Clear all jobs from queue |

### Scheduler (2) — Registered in Console Kernel

| Command | Description |
|---------|-------------|
| `schedule:run` | Execute all due scheduled commands |
| `schedule:list` | List all registered scheduled commands |

## Kernel-level Commands

In addition to the above, the Console Kernel registers:
- `list` — Show all available commands
- `help` — Show help for a command

## Example Commands

```bash
php myth list                               # Show all commands
php myth route:cache                        # Compile routes to cache
php myth route:clear                        # Clear route cache
php myth config:cache                       # Compile all config files
php myth config:clear                       # Clear config cache
php myth view:cache                         # Pre-compile all view templates
php myth view:clear                         # Clear compiled views
php myth route:list --method=POST           # Filter routes by method
php myth down --secret=superadmin-bypass    # Maintenance mode with bypass URL
php myth down --redirect=/status            # Redirect traffic while app is down
php myth down --render=app/views/errors/503.php --refresh=30
php myth make:controller UserController --resource
php myth backup:run --only-db
php myth queue:work --queue=emails --tries=3 --timeout=60
php myth schedule:run                       # Run due scheduled tasks
php myth schedule:list                      # List all schedules
php myth down --secret=bypass123 --status=503
php myth cache:clear --store=file           # Clear specific store
```

## How To Use

1. Use built-in commands for scaffolding and operations first.
2. Register custom commands in `app/routes/console.php` or via `make:command`.
3. Add `schedule:run` to system crontab for scheduler support.
4. Keep generator output aligned with framework base classes.

## What To Avoid

- Avoid editing framework built-in command registrations directly for app-specific needs.
- Avoid running destructive commands (`queue:flush`, `cache:clear`) in production without confirmation.
- Avoid running `queue:work` without `--tries` in production.

## Benefits

- 39 built-in commands covering development, security, performance, deployment, and operations.
- Standardized developer workflow.
- Faster setup for common tasks.
- Reduced manual mistakes for repetitive operations.

## Evidence

- `systems/Core/Console/Commands.php` (built-in command registry)
- `systems/Core/Console/Kernel.php` (schedule:run, schedule:list, list, help)
