# 08. Backup System

## Component

- Main class: `Components\Backup`.
- Supports fluent selection of database and/or file backup.
- Backup outputs ZIP archives under configured backup path.

## Confirmed Public API

- `database(array $config = [])`
- `files(array $directories = [])`
- `setBackupPath(string $path)`
- `addDirectory(string $path)`
- `exclude(string $pattern)`
- `prefix(string $prefix)`
- `run(): array`
- `cleanup(int $days = 30): int`
- `listBackups(): array`
- `getLastBackupPath(): ?string`

## DB Dump Strategy

- Tries `mysqldump` first.
- Supports explicit path override via `mysqldump_path`.
- Supports ordered search via `mysqldump_search_paths` (glob patterns allowed).
- Falls back to PHP-based dump logic when binary is unavailable.

## CLI Integration

- `db:backup`
- `backup:run`
- `backup:clean`

## Examples

### Full backup with custom config

```php
$backup = new \Components\Backup([
	'backup_path' => ROOT_DIR . 'storage/backups',
	'mysqldump_path' => null,
	'mysqldump_search_paths' => [
		'/usr/bin/mysqldump',
		'C:/laragon/bin/mysql/*/bin/mysqldump.exe',
	],
]);

$result = $backup->database()->files([ROOT_DIR . 'app', ROOT_DIR . 'public/upload'])->run();
```

### Cleanup old backups

```php
$removedCount = (new \Components\Backup())->cleanup(30);
```

## How To Use

1. Start with default config; only override paths when necessary.
2. Use `database()->files()` for full backup.
3. Schedule `backup:run` and `backup:clean` through scheduler.
4. Check backup list regularly with `listBackups()`.

## What To Avoid

- Avoid writing backups into publicly served directories.
- Avoid running cleanup without retention policy agreement.
- Avoid assuming `mysqldump` exists on all hosts; keep search paths configured.

## Benefits

- Reliable disaster recovery baseline.
- Flexible binary detection across environments.
- Simple fluent API + CLI workflow.

## Evidence

- `systems/Components/Backup.php`
- `systems/Core/Console/Commands.php`
