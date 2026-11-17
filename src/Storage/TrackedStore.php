<?php
declare(strict_types=1);

namespace Landlords\Storage;

use PDO;

/**
 * Keeps one table in step with what the council returns: inserts new rows,
 * records every change to a tracked field in the events table, and marks rows
 * that disappear as removed (never deleting them), restoring them if they return.
 * Used for both licences and student accommodation.
 */
final class TrackedStore
{
    /**
     * @param list<string> $tracked fields compared between runs
     * @param list<string> $quiet   fields stored and refreshed without logging changes
     */
    public function __construct(
        private PDO $pdo,
        private string $table,
        private string $keyColumn,
        private string $entity,
        private array $tracked,
        private array $quiet = []
    ) {
    }

    public function isEmpty(): bool
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM {$this->table}")->fetchColumn() === 0;
    }

    public function countActive(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM {$this->table} WHERE removed_at IS NULL")->fetchColumn();
    }

    /**
     * Insert, update and mark removed, all in one transaction (the caller's, if one is open).
     *
     * @param list<array<string, mixed>> $records
     * @param bool $backfill mark new rows as history (the first run), so they are never emailed
     * @return array{inserted: int, updated: int, removed: int, restored: int}
     */
    public function sync(array $records, string $now, bool $backfill = false): array
    {
        $stats = ['inserted' => 0, 'updated' => 0, 'removed' => 0, 'restored' => 0];
        $columns = array_merge($this->tracked, $this->quiet);
        $k = $this->keyColumn;

        $select = $this->pdo->prepare("SELECT * FROM {$this->table} WHERE {$k} = ?");
        $insert = $this->pdo->prepare(sprintf(
            'INSERT INTO %s (%s, %s, first_seen_at, last_seen_at, updated_at, backfilled) VALUES (:key, %s, :now, :now2, :now3, :backfilled)',
            $this->table, $k, implode(', ', $columns), implode(', ', array_map(static fn($f) => ':' . $f, $columns))
        ));
        $touch = $this->pdo->prepare("UPDATE {$this->table} SET last_seen_at = ? WHERE {$k} = ?");
        $event = $this->pdo->prepare(
            'INSERT INTO events (entity, rkey, field, old_value, new_value, changed_at) VALUES (?, ?, ?, ?, ?, ?)'
        );

        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            foreach ($records as $record) {
                $key = $record[$k];
                $select->execute([$key]);
                $existing = $select->fetch();
                $select->closeCursor();

                if ($existing === false) {
                    $params = [':key' => $key, ':now' => $now, ':now2' => $now, ':now3' => $now, ':backfilled' => $backfill ? 1 : 0];
                    foreach ($columns as $f) {
                        $params[':' . $f] = $record[$f] ?? null;
                    }
                    $insert->execute($params);
                    $stats['inserted']++;
                    continue;
                }

                $changes = [];
                foreach ($this->tracked as $f) {
                    if (!self::same($existing[$f], $record[$f] ?? null)) {
                        $changes[$f] = $record[$f] ?? null;
                        $event->execute([$this->entity, $key, $f, self::str($existing[$f]), self::str($record[$f] ?? null), $now]);
                    }
                }
                $quiet = [];
                foreach ($this->quiet as $f) {
                    if (!self::same($existing[$f], $record[$f] ?? null)) {
                        $quiet[$f] = $record[$f] ?? null;
                    }
                }
                if ($existing['removed_at'] !== null) {
                    $event->execute([$this->entity, $key, 'removed_at', $existing['removed_at'], null, $now]);
                    $stats['restored']++;
                }

                if ($changes || $quiet || $existing['removed_at'] !== null) {
                    $sets = array_map(static fn($f) => "{$f} = :{$f}", array_keys($changes + $quiet));
                    $sets[] = 'last_seen_at = :now';
                    $sets[] = 'removed_at = NULL';
                    if ($changes) {
                        $sets[] = 'updated_at = :now2';
                    }
                    $params = [':key' => $key, ':now' => $now];
                    if ($changes) {
                        $params[':now2'] = $now;
                    }
                    foreach ($changes + $quiet as $f => $v) {
                        $params[':' . $f] = $v;
                    }
                    $this->pdo->prepare("UPDATE {$this->table} SET " . implode(', ', $sets) . " WHERE {$k} = :key")->execute($params);
                    if ($changes) {
                        $stats['updated']++;
                    }
                } else {
                    $touch->execute([$now, $key]);
                }
            }

            // Anything we hold that the council no longer returns is marked, not deleted.
            $gone = $this->pdo->prepare("SELECT {$k} FROM {$this->table} WHERE removed_at IS NULL AND last_seen_at < ?");
            $gone->execute([$now]);
            $markRemoved = $this->pdo->prepare("UPDATE {$this->table} SET removed_at = ? WHERE {$k} = ?");
            foreach ($gone->fetchAll(PDO::FETCH_COLUMN) as $key) {
                $markRemoved->execute([$now, $key]);
                $event->execute([$this->entity, $key, 'removed_at', null, $now, $now]);
                $stats['removed']++;
            }

            if ($own) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($own) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return $stats;
    }

    /** All rows still present in the council feed. */
    public function allActive(string $orderBy): array
    {
        return $this->pdo->query("SELECT * FROM {$this->table} WHERE removed_at IS NULL ORDER BY {$orderBy}")->fetchAll();
    }

    /** Compare a stored value with a fresh one, tolerating numeric/string differences from the driver. */
    private static function same(mixed $stored, mixed $fresh): bool
    {
        if ($stored === null || $fresh === null) {
            return $stored === $fresh;
        }
        if (is_float($fresh) || is_int($fresh)) {
            return is_numeric($stored) && abs((float) $stored - (float) $fresh) < 0.0000005;
        }
        return (string) $stored === (string) $fresh;
    }

    private static function str(mixed $v): ?string
    {
        return $v === null ? null : (string) $v;
    }
}
