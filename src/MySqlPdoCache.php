<?php

namespace MysqlCache4Doctrine2;

/**
 * MysqlCache cache provider.
 */
class MySqlPdoCache extends \Doctrine\Common\Cache\CacheProvider
{
    /**
     * The ID field will store the cache key.
     */
    public const ID_FIELD = 'k';

    /**
     * The data field will store the serialized PHP value.
     */
    public const DATA_FIELD = 'd';

    /**
     * The expiration field will store a date value indicating when the
     * cache entry should expire.
     */
    public const EXPIRATION_FIELD = 'e';

    /** @var PDO */
    private $mysql;

    /** @var string */
    private $table;

    /**
     * Calling the constructor will ensure that the database file and table
     * exist and will create both if they don't.
     *
     * @param PDO $driver
     * @param string $table
     */
    public function __construct(\PDO $driver, string $table)
    {
        $this->mysql = $driver;
        $this->table = $table;

        $this->ensureTableExists();
    }

    private function ensureTableExists() : void
    {
        $this->mysql->query(
            sprintf(
                'CREATE TABLE IF NOT EXISTS %s(%s VARCHAR(500) PRIMARY KEY NOT NULL, %s LONGTEXT, %s INTEGER)',
                $this->table,
                static::ID_FIELD,
                static::DATA_FIELD,
                static::EXPIRATION_FIELD
            )
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function doFetch($id)
    {
        $item = $this->findById($id);

        if (! $item) {
            return false;
        }

        return unserialize($item[self::DATA_FIELD]);
    }

    /**
     * {@inheritdoc}
     */
    protected function doContains($id)
    {
        return $this->findById($id, false) !== null;
    }

    /**
     * {@inheritdoc}
     */
    protected function doSave($id, $data, $lifeTime = 0)
    {

        $statement = $this->mysql->prepare(sprintf(
            'REPLACE INTO %s (%s) VALUES(?, ?, ?)',
            $this->table,
            implode(',', $this->getFields())
        ));

        $lifeTime = (
            $lifeTime > 0
            ? time() + $lifeTime
            : null
        );
        $serializedData = serialize($data);
        $statement->bindValue(1, $id, \PDO::PARAM_STR);
        $statement->bindValue(2, $serializedData, \PDO::PARAM_STR);
        $statement->bindValue(3, $lifeTime, \PDO::PARAM_INT);

        return $statement->execute();
    }

    /**
     * {@inheritdoc}
     */
    protected function doDelete($id)
    {
        list($idField) = $this->getFields();

        $statement = $this->mysql->prepare(sprintf(
            'DELETE FROM %s WHERE %s = ?',
            $this->table,
            $idField
        ));

        $statement->bindValue(1, $id, \PDO::PARAM_STR);

        return $statement->execute();
    }

    /**
     * {@inheritdoc}
     */
    protected function doFlush()
    {
        return $this->mysql->query(sprintf('DELETE FROM %s', $this->table));
    }

    /**
     * {@inheritdoc}
     */
    protected function doGetStats()
    {
        // no-op.
    }

    /**
     * Find a single row by ID.
     *
     * @param mixed $id
     *
     * @return array|null
     */
    private function findById($id, bool $includeData = true) : ?array
    {
        list($idField) = $fields = $this->getFields();

        if (! $includeData) {
            $key = array_search(static::DATA_FIELD, $fields);
            unset($fields[$key]);
        }

        $statement = $this->mysql->prepare(sprintf(
            'SELECT %s FROM %s WHERE %s = ? LIMIT 1',
            implode(',', $fields),
            $this->table,
            $idField
        ));

        $statement->bindValue(1, $id, \PDO::PARAM_STR);

        $item = $statement->execute()->fetch();

        if ($item === false) {
            return null;
        }

        if ($this->isExpired($item)) {
            $this->doDelete($id);

            return null;
        }

        return $item;
    }

    /**
     * Gets an array of the fields in our table.
     *
     * @return array
     */
    private function getFields() : array
    {
        return [static::ID_FIELD, static::DATA_FIELD, static::EXPIRATION_FIELD];
    }

    /**
     * Check if the item is expired.
     *
     * @param array $item
     */
    private function isExpired(array $item) : bool
    {
        return isset($item[static::EXPIRATION_FIELD]) &&
            $item[self::EXPIRATION_FIELD] !== null &&
            $item[self::EXPIRATION_FIELD] < time();
    }
}
