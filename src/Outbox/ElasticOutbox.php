<?php

declare(strict_types=1);

namespace Survos\ElasticBundle\Outbox;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;

/**
 * Postgres-side change capture: statement-level triggers record the ids of changed rows in
 * elastic_outbox, in the same transaction as the write, so raw SQL, DQL bulk updates and
 * database cascades are captured too, and a crash after COMMIT cannot lose a change.
 *
 * The table holds pending ids, not events: one row per (class, id), however many times the
 * row changed. Rows are claimed with a token rather than deleted up front, so a reindex that
 * fails leaves its ids queued, and no lock is held while Elasticsearch is called. A write that
 * lands on a claimed id clears the claim, so it is reindexed again after the in-flight batch.
 */
final class ElasticOutbox
{
    public const string TABLE = 'elastic_outbox';
    public const string CHANNEL = 'elastic_outbox';
    private const string FUNCTION = 'elastic_outbox_capture';

    /** Seconds after which a claim from a consumer that died is handed to another. */
    private const int CLAIM_LEASE = 300;

    public static function supports(Connection $connection): bool
    {
        return $connection->getDatabasePlatform() instanceof PostgreSQLPlatform;
    }

    /**
     * @param string $table    quoted table name, schema-qualified when needed
     * @param string $idColumn unquoted column name; the trigger quotes it with format(%I)
     */
    public function install(Connection $connection, string $class, string $table, string $idColumn): void
    {
        $connection->transactional(function (Connection $connection) use ($class, $table, $idColumn): void {
            foreach ($this->setupSql() as $sql) {
                $connection->executeStatement($sql);
            }
            foreach ($this->triggerSql($connection, $class, $table, $idColumn) as $sql) {
                $connection->executeStatement($sql);
            }
        });
    }

    public function uninstall(Connection $connection, string $class, string $table): void
    {
        foreach ($this->triggerNames($class) as $name) {
            $connection->executeStatement(sprintf('DROP TRIGGER IF EXISTS %s ON %s', $name, $table));
        }
    }

    public function isInstalled(Connection $connection, string $class): bool
    {
        return (int) $connection->fetchOne(
            'SELECT count(*) FROM pg_trigger WHERE NOT tgisinternal AND tgname IN (?, ?, ?)',
            $this->triggerNames($class),
        ) === 3;
    }

    /** @return array{pending: int, claimed: int, oldest: ?string}|null null when the table was never created */
    public function stats(Connection $connection): ?array
    {
        if ($connection->fetchOne('SELECT to_regclass(?)', [self::TABLE]) === null) {
            return null;
        }
        $row = $connection->fetchAssociative(sprintf(
            'SELECT count(*) AS pending, count(claim_token) AS claimed, min(queued_at)::text AS oldest FROM %s',
            self::TABLE,
        ));

        return ['pending' => (int) $row['pending'], 'claimed' => (int) $row['claimed'], 'oldest' => $row['oldest']];
    }

    /**
     * Claim up to $limit ids and hand them to $consume grouped by class. They are removed only
     * once every group succeeded; on failure the claim is released and the error rethrown.
     *
     * @param callable(class-string, list<string>): void $consume
     * @return int ids consumed
     */
    public function drain(Connection $connection, int $limit, callable $consume): int
    {
        $token = bin2hex(random_bytes(16));
        $rows = $connection->fetchAllAssociative(sprintf(
            'UPDATE %1$s o SET claim_token = ?, claimed_at = clock_timestamp()
               FROM (SELECT entity_class, entity_id FROM %1$s
                      WHERE claim_token IS NULL OR claimed_at < clock_timestamp() - make_interval(secs => ?)
                      ORDER BY queued_at LIMIT ? FOR UPDATE SKIP LOCKED) c
              WHERE o.entity_class = c.entity_class AND o.entity_id = c.entity_id
          RETURNING o.entity_class, o.entity_id',
            self::TABLE,
        ), [$token, self::CLAIM_LEASE, max(1, $limit)]);
        if ($rows === []) {
            return 0;
        }

        $byClass = [];
        foreach ($rows as $row) {
            $byClass[$row['entity_class']][] = (string) $row['entity_id'];
        }
        try {
            foreach ($byClass as $class => $ids) {
                $consume($class, $ids);
            }
        } catch (\Throwable $error) {
            $connection->executeStatement(
                sprintf('UPDATE %s SET claim_token = NULL, claimed_at = NULL WHERE claim_token = ?', self::TABLE),
                [$token],
            );
            throw $error;
        }
        // A write that re-queued one of these ids cleared its token, so it survives this delete.
        $connection->executeStatement(sprintf('DELETE FROM %s WHERE claim_token = ?', self::TABLE), [$token]);

        return count($rows);
    }

    public function listen(Connection $connection): void
    {
        $connection->executeStatement('LISTEN '.self::CHANNEL);
    }

    /** Block until a trigger notifies or the timeout passes. True when notified. */
    public function wait(Connection $connection, int $timeoutSeconds): bool
    {
        $native = $connection->getNativeConnection();
        if ($native instanceof \Pdo\Pgsql) {
            $notified = $native->getNotify(\PDO::FETCH_ASSOC, max(0, $timeoutSeconds) * 1000) !== false;
            // Several commits arrive as several notifications; one wake-up is enough.
            while ($notified && $native->getNotify(\PDO::FETCH_ASSOC, 0) !== false) {
            }

            return $notified;
        }
        if ($native instanceof \PgSql\Connection) {
            $socket = pg_socket($native);
            $read = [$socket];
            $write = $except = null;
            if (pg_get_notify($native) === false && stream_select($read, $write, $except, max(0, $timeoutSeconds)) > 0) {
                pg_consume_input($native);
            }
            $notified = false;
            while (pg_get_notify($native) !== false) {
                $notified = true;
            }

            return $notified;
        }

        // Unknown driver: degrade to polling.
        sleep(max(1, $timeoutSeconds));

        return false;
    }

    /** @return list<string> DDL shared by every searchable table */
    public function setupSql(): array
    {
        $table = self::TABLE;
        $function = self::FUNCTION;
        $channel = self::CHANNEL;

        return [
            <<<SQL
            CREATE TABLE IF NOT EXISTS {$table} (
                entity_class text        NOT NULL,
                entity_id    text        NOT NULL,
                queued_at    timestamptz NOT NULL DEFAULT clock_timestamp(),
                claim_token  text,
                claimed_at   timestamptz,
                PRIMARY KEY (entity_class, entity_id)
            ) WITH (autovacuum_vacuum_scale_factor = 0, autovacuum_vacuum_threshold = 1000)
            SQL,
            "CREATE INDEX IF NOT EXISTS {$table}_queued_at ON {$table} (queued_at)",
            // UNION, not two inserts: one statement may not touch the same conflicting row twice,
            // and an UPDATE needs the old ids too in case the primary key itself changed.
            <<<SQL
            CREATE OR REPLACE FUNCTION {$function}() RETURNS trigger LANGUAGE plpgsql AS \$fn\$
            DECLARE
                source text;
            BEGIN
                source := CASE TG_OP
                    WHEN 'INSERT' THEN format('SELECT %I::text FROM new_rows', TG_ARGV[1])
                    WHEN 'DELETE' THEN format('SELECT %I::text FROM old_rows', TG_ARGV[1])
                    ELSE format('SELECT %1\$I::text FROM new_rows UNION SELECT %1\$I::text FROM old_rows', TG_ARGV[1])
                END;
                EXECUTE format(
                    'INSERT INTO {$table} (entity_class, entity_id) SELECT $1, id FROM (%s) AS changed(id)
                     ON CONFLICT (entity_class, entity_id) DO UPDATE
                        SET claim_token = NULL, claimed_at = NULL, queued_at = clock_timestamp()
                      WHERE {$table}.claim_token IS NOT NULL', source)
                USING TG_ARGV[0];
                PERFORM pg_notify('{$channel}', '');
                RETURN NULL;
            END
            \$fn\$
            SQL,
        ];
    }

    /** @return list<string> */
    public function triggerSql(Connection $connection, string $class, string $table, string $idColumn): array
    {
        [$insert, $update, $delete] = $this->triggerNames($class);
        $args = sprintf('%s, %s', $connection->quote($class), $connection->quote($idColumn));
        $function = self::FUNCTION;

        // Transition tables need one trigger per event; DROP + CREATE because OR REPLACE is PG14+.
        return [
            "DROP TRIGGER IF EXISTS {$insert} ON {$table}",
            "CREATE TRIGGER {$insert} AFTER INSERT ON {$table} REFERENCING NEW TABLE AS new_rows FOR EACH STATEMENT EXECUTE FUNCTION {$function}({$args})",
            "DROP TRIGGER IF EXISTS {$update} ON {$table}",
            "CREATE TRIGGER {$update} AFTER UPDATE ON {$table} REFERENCING OLD TABLE AS old_rows NEW TABLE AS new_rows FOR EACH STATEMENT EXECUTE FUNCTION {$function}({$args})",
            "DROP TRIGGER IF EXISTS {$delete} ON {$table}",
            "CREATE TRIGGER {$delete} AFTER DELETE ON {$table} REFERENCING OLD TABLE AS old_rows FOR EACH STATEMENT EXECUTE FUNCTION {$function}({$args})",
        ];
    }

    /**
     * Named by class, not table: two searches can share a table (single-table inheritance).
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function triggerNames(string $class): array
    {
        $base = self::TABLE.'_'.substr(sha1($class), 0, 12);

        return [$base.'_ins', $base.'_upd', $base.'_del'];
    }
}
