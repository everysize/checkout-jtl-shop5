<?php

declare(strict_types=1);

namespace Plugin\everysize_checkout\Db;

/**
 * The narrow database surface the plugin needs. Always with bound parameters —
 * never concatenate values into SQL. The JTL implementation wraps
 * \JTL\DB\DbInterface; the tests use an in-memory fake.
 */
interface Connection
{
    /**
     * @param array<string, mixed> $params
     *
     * @return array<int, object>
     */
    public function getObjects(string $sql, array $params = []): array;

    /**
     * @param array<string, mixed> $params
     */
    public function getSingleObject(string $sql, array $params = []): ?object;

    /**
     * First column of the first row, null without a row.
     *
     * @param array<string, mixed> $params
     *
     * @return mixed int|string|float|null
     */
    public function getScalar(string $sql, array $params = []);

    /**
     * @param array<string, mixed> $params
     *
     * @return int affected rows
     */
    public function execute(string $sql, array $params = []): int;

    /**
     * @param array<string, mixed> $data column => value
     *
     * @return int the new row id
     */
    public function insert(string $table, array $data): int;

    /**
     * @param array<string, mixed>    $data  column => value
     * @param int|string|array<mixed> $value key value (or list for a compound key)
     */
    public function update(string $table, string $key, $value, array $data): int;

    /**
     * @param int|string $value key value
     */
    public function delete(string $table, string $key, $value): int;

    public function beginTransaction(): void;

    public function commit(): void;

    public function rollback(): void;
}
