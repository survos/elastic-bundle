<?php

declare(strict_types=1);

namespace Survos\ElasticBundle\Tests\Outbox;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\TestCase;
use Survos\ElasticBundle\Outbox\ElasticOutbox;

/**
 * Runs against a real Postgres, in a throwaway schema:
 * ELASTIC_OUTBOX_TEST_DATABASE_URL=postgresql://user:pass@127.0.0.1:5434/db
 */
final class ElasticOutboxTest extends TestCase
{
    private const string CLASS_NAME = 'App\Entity\Item';

    private Connection $connection;
    private Connection $other;
    private string $schema;
    private ElasticOutbox $outbox;

    protected function setUp(): void
    {
        $url = getenv('ELASTIC_OUTBOX_TEST_DATABASE_URL') ?: null;
        if ($url === null) {
            self::markTestSkipped('Set ELASTIC_OUTBOX_TEST_DATABASE_URL to a Postgres database.');
        }
        $this->schema = 'elastic_outbox_test_'.bin2hex(random_bytes(4));
        $this->connection = $this->connect($url);
        $this->connection->executeStatement("CREATE SCHEMA {$this->schema}");
        $this->connection->executeStatement("SET search_path TO {$this->schema}");
        $this->connection->executeStatement('CREATE TABLE item (id int PRIMARY KEY, title text)');
        $this->other = $this->connect($url);
        $this->other->executeStatement("SET search_path TO {$this->schema}");

        $this->outbox = new ElasticOutbox();
        $this->outbox->install($this->connection, self::CLASS_NAME, 'item', 'id');
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->other->close();
            $this->connection->executeStatement("DROP SCHEMA {$this->schema} CASCADE");
            $this->connection->close();
        }
    }

    public function testRawSqlChangesAreQueuedOncePerId(): void
    {
        $this->connection->executeStatement("INSERT INTO item VALUES (1, 'a'), (2, 'b'), (3, 'c')");
        $this->connection->executeStatement("UPDATE item SET title = 'z'");
        $this->connection->executeStatement('DELETE FROM item WHERE id = 3');
        $this->connection->executeStatement('UPDATE item SET id = 20 WHERE id = 2');

        self::assertTrue($this->outbox->isInstalled($this->connection, self::CLASS_NAME));
        self::assertSame(['1', '2', '20', '3'], $this->pendingIds());
    }

    public function testRolledBackWritesAreNotQueued(): void
    {
        $this->connection->beginTransaction();
        $this->connection->executeStatement("INSERT INTO item VALUES (1, 'a')");
        $this->connection->rollBack();

        self::assertSame([], $this->pendingIds());
    }

    public function testDrainGroupsByClassAndRemovesConsumedIds(): void
    {
        $this->connection->executeStatement("INSERT INTO item VALUES (1, 'a'), (2, 'b')");
        $seen = [];
        $count = $this->outbox->drain($this->connection, 10, function (string $class, array $ids) use (&$seen): void {
            sort($ids);
            $seen[$class] = $ids;
        });

        self::assertSame(2, $count);
        self::assertSame([self::CLASS_NAME => ['1', '2']], $seen);
        self::assertSame([], $this->pendingIds());
        self::assertSame(['pending' => 0, 'claimed' => 0, 'oldest' => null], $this->outbox->stats($this->connection));
    }

    public function testFailedConsumeReleasesTheClaim(): void
    {
        $this->connection->executeStatement("INSERT INTO item VALUES (1, 'a')");
        try {
            $this->outbox->drain($this->connection, 10, static fn () => throw new \RuntimeException('ES down'));
            self::fail('Expected the consume error to propagate.');
        } catch (\RuntimeException) {
        }

        self::assertSame(['1'], $this->pendingIds());
        self::assertSame(0, $this->outbox->stats($this->connection)['claimed']);
    }

    public function testWriteDuringAClaimIsQueuedAgain(): void
    {
        $this->connection->executeStatement("INSERT INTO item VALUES (1, 'a'), (2, 'b')");
        // Another session commits a change after the consumer read the row: it must not be lost.
        $this->outbox->drain($this->connection, 10, function (): void {
            $this->other->executeStatement("UPDATE item SET title = 'later' WHERE id = 1");
        });

        self::assertSame(['1'], $this->pendingIds());
    }

    public function testUninstallStopsCapture(): void
    {
        $this->outbox->uninstall($this->connection, self::CLASS_NAME, 'item');
        $this->connection->executeStatement("INSERT INTO item VALUES (1, 'a')");

        self::assertFalse($this->outbox->isInstalled($this->connection, self::CLASS_NAME));
        self::assertSame([], $this->pendingIds());
    }

    public function testCommitWakesAListener(): void
    {
        $this->outbox->listen($this->connection);
        $this->other->executeStatement("INSERT INTO item VALUES (1, 'a')");

        self::assertTrue($this->outbox->wait($this->connection, 2));
        self::assertFalse($this->outbox->wait($this->connection, 0));
    }

    /** @return list<string> */
    private function pendingIds(): array
    {
        return array_map(strval(...), $this->connection->fetchFirstColumn(
            'SELECT entity_id FROM elastic_outbox WHERE entity_class = ? ORDER BY entity_id',
            [self::CLASS_NAME],
        ));
    }

    private function connect(string $url): Connection
    {
        $params = (new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url);

        return DriverManager::getConnection($params);
    }
}
