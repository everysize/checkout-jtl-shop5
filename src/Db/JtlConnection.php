<?php

declare(strict_types=1);

namespace Plugin\everysize_checkout\Db;

use JTL\DB\DbInterface;

/**
 * The JTL implementation of the plugin's database interface.
 *
 * It does NOT route through NiceDB's convenience methods: outside
 * transactions NiceDB runs with PDO::ERRMODE_SILENT and returns 0/false on a
 * failed statement, which would turn a broken INSERT into a silent "nothing
 * reported" (exactly-once gone) and a broken SELECT into null. Every
 * statement here is prepared on the shared PDO handle and checked
 * explicitly — a failing statement always throws a \RuntimeException,
 * while an empty result stays a valid null/[].
 */
final class JtlConnection implements Connection
{
    /** @var int nesting counter of the plugin's own transactions */
    private int $transactionCount = 0;

    public function __construct(private DbInterface $db)
    {
    }

    public function getObjects(string $sql, array $params = []): array
    {
        return $this->fetch($sql, $params, static function (\PDOStatement $stmt): array {
            return $stmt->fetchAll(\PDO::FETCH_OBJ);
        });
    }

    public function getSingleObject(string $sql, array $params = []): ?object
    {
        return $this->fetch($sql, $params, static function (\PDOStatement $stmt): ?object {
            $row = $stmt->fetch(\PDO::FETCH_OBJ);

            return $row === false ? null : $row;
        });
    }

    public function getScalar(string $sql, array $params = [])
    {
        return $this->fetch($sql, $params, static function (\PDOStatement $stmt) {
            $row = $stmt->fetch(\PDO::FETCH_NUM);

            return $row === false ? null : $row[0];
        });
    }

    public function execute(string $sql, array $params = []): int
    {
        return $this->run($sql, $params, static function (\PDOStatement $stmt): int {
            return $stmt->rowCount();
        });
    }

    public function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', array_map(static fn (string $column): string => ':' . $column, $columns))
        );

        return $this->run($sql, $data, function (): int {
            $id = $this->pdo()->lastInsertId();

            return $id !== '' && $id !== '0' ? (int) $id : 0;
        });
    }

    public function update(string $table, string $key, $value, array $data): int
    {
        $assignments = implode(', ', array_map(static fn (string $column): string => $column . ' = :' . $column, array_keys($data)));
        $sql = sprintf('UPDATE %s SET %s WHERE %s = :__key', $table, $assignments, $key);
        $params = $data;
        $params['__key'] = $value;

        return $this->run($sql, $params, static function (\PDOStatement $stmt): int {
            return $stmt->rowCount();
        });
    }

    public function delete(string $table, string $key, $value): int
    {
        $sql = sprintf('DELETE FROM %s WHERE %s = :__key', $table, $key);

        return $this->run($sql, ['__key' => $value], static function (\PDOStatement $stmt): int {
            return $stmt->rowCount();
        });
    }

    public function beginTransaction(): void
    {
        if ($this->transactionCount++ === 0) {
            $this->pdo()->beginTransaction();
        }
    }

    public function commit(): void
    {
        if ($this->transactionCount === 0) {
            return;
        }
        if (--$this->transactionCount === 0) {
            $this->pdo()->commit();
        }
    }

    public function rollback(): void
    {
        if ($this->transactionCount === 0) {
            return;
        }
        $this->transactionCount = 0;
        $this->pdo()->rollBack();
    }

    private function pdo(): \PDO
    {
        return $this->db->getPDO();
    }

    /**
     * Prepares and executes a statement, turning every failure into a
     * \RuntimeException — independent of the PDO error mode NiceDB has set,
     * which stays untouched for the rest of the shop.
     *
     * @param array<string, mixed> $params
     */
    private function prepare(string $sql, array $params): \PDOStatement
    {
        $stmt = $this->pdo()->prepare($sql);
        if ($stmt === false) {
            $error = $this->pdo()->errorInfo();

            throw new \RuntimeException(sprintf('everysize checkout, SQL prepare failed (%s): %s', $sql, implode(' ', array_map('strval', $error))));
        }
        if ($stmt->execute($params) === false) {
            $error = $stmt->errorInfo();

            throw new \RuntimeException(sprintf('everysize checkout, SQL failed (%s): %s', $sql, implode(' ', array_map('strval', $error))));
        }

        return $stmt;
    }

    /**
     * @template T
     *
     * @param array<string, mixed>       $params
     * @param callable(\PDOStatement): T $fetch
     *
     * @return T
     */
    private function fetch(string $sql, array $params, callable $fetch)
    {
        $stmt = $this->prepare($sql, $params);

        return $fetch($stmt);
    }

    /**
     * @template T
     *
     * @param array<string, mixed>       $params
     * @param callable(\PDOStatement): T $result
     *
     * @return T
     */
    private function run(string $sql, array $params, callable $result)
    {
        $stmt = $this->prepare($sql, $params);

        return $result($stmt);
    }
}
