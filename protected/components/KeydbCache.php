<?php

namespace app\components;

use yii\caching\Cache;

/**
 * Cache backed by KeyDB (Redis-protocol compatible) via PHP's native ext-redis,
 * used instead of the Composer-only yiisoft/yii2-redis package.
 */
class KeydbCache extends Cache
{
    public string $host = '127.0.0.1';
    public int $port = 6379;
    public int $database = 0;
    public float $connectTimeout = 2.0;

    private \Redis $_redis;

    /**
     * pconnect() rather than connect(): every call here is a single atomic
     * command (no MULTI/pipeline), so there's no transaction state that
     * could leak across requests via the reused socket. Worth it because
     * this cache backs the dashboard poll endpoint
     * (DashboardCache::getVersion()), hit once a second by every open tab -
     * the highest-frequency round trip in the app, unlike the database
     * connection (see db.php) where volume never justified the same
     * tradeoff.
     */
    public function init(): void
    {
        parent::init();
        $this->_redis = new \Redis();
        $this->_redis->pconnect($this->host, $this->port, $this->connectTimeout);
        if ($this->database !== 0) {
            $this->_redis->select($this->database);
        }
    }

    protected function getValue($key)
    {
        $value = $this->_redis->get($key);
        return $value === false ? false : $value;
    }

    protected function setValue($key, $value, $duration)
    {
        return $duration > 0
            ? $this->_redis->set($key, $value, $duration)
            : $this->_redis->set($key, $value);
    }

    protected function addValue($key, $value, $duration)
    {
        if ($duration > 0) {
            return $this->_redis->set($key, $value, ['nx', 'ex' => $duration]);
        }
        return $this->_redis->setnx($key, $value);
    }

    protected function deleteValue($key)
    {
        return $this->_redis->del($key) > 0;
    }

    protected function existsValue($key): bool
    {
        return (bool) $this->_redis->exists($key);
    }

    protected function flushValues()
    {
        return $this->_redis->flushDB();
    }

    /**
     * Atomic INCR-based counter for LoginForm's failed-attempt throttle,
     * TTL set only on the request that creates the key. Replaces a prior
     * get()-then-set(current+1) pattern: that read-modify-write race let
     * two near-simultaneous failed attempts undercount, letting extra
     * guesses through before lockout. INCR serializes concurrent callers
     * on the Redis server instead of racing in PHP.
     */
    public function increment(string $key, int $ttl): int
    {
        $builtKey = $this->buildKey($key);
        $count = (int) $this->_redis->incr($builtKey);
        if ($count === 1) {
            $this->_redis->expire($builtKey, $ttl);
        }

        return $count;
    }

    /**
     * Reads a counter written by increment(), bypassing the inherited
     * get()/getValue() path. Necessary because Cache::get() unserializes
     * whatever getValue() returns, but increment() writes a raw integer via
     * INCR with no serialize() step - the stock get() would throw an
     * uncaught unserialize() error on any key increment() touched. Callers
     * must use this instead of cache->get() for counter keys.
     */
    public function getCounter(string $key): int
    {
        $value = $this->_redis->get($this->buildKey($key));
        return $value === false ? 0 : (int) $value;
    }
}
