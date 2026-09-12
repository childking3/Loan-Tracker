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
     * pconnect() rather than connect(): every one of this app's Redis calls
     * is a single atomic command (GET/SET/INCR/EXPIRE, checked - nothing
     * here uses MULTI/pipeline), so there is no multi-command transaction
     * state that could leak from one request into the next by reusing the
     * underlying socket. That makes this the safe half of the connection-
     * reuse question; see db.php for the half that was measured and
     * rejected. Confirmed live: this cache backs the dashboard's poll
     * endpoint (DashboardCache::getVersion()), which every open dashboard
     * tab hits once a second - by far the highest-frequency round trip
     * anywhere in the app - so this is where reusing the TCP connection
     * instead of reopening it every request actually matters, unlike the
     * database connection where request volume is nowhere near this high.
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
     * Atomically increments a counter key via Redis's own INCR, setting its
     * TTL only on the request that actually creates the key. Added during
     * the Phase 9 hardening pass for LoginForm's failed-attempt counter,
     * which previously did get() then set(current + 1) - a classic
     * read-modify-write race: two near-simultaneous failed attempts against
     * the same username can both read the same starting count and each
     * independently write count + 1, undercounting real attempts and
     * letting a few extra guesses through before the lockout engages.
     * INCR is a single atomic operation on the Redis server, so concurrent
     * callers serialize there instead of racing in PHP.
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
     * get()/getValue() path entirely.
     *
     * Caught live while testing this Phase 9 change, not spotted by
     * review: yii\caching\Cache::get() runs unserialize() on whatever
     * getValue() returns, because ordinary Cache::set() always stores a
     * PHP-serialized value first. increment() above writes a raw integer
     * via Redis's own INCR instead - there is no serialize() step to
     * match, since INCR only operates on plain integer strings. Calling
     * the stock get() against a key increment() had touched threw an
     * uncaught unserialize() ErrorException, which would have 500'd the
     * login page on every attempt after the first failed one - worse than
     * the race condition this change was meant to fix. This method reads
     * the same raw format increment() writes; LoginForm's throttle check
     * must use this instead of cache->get() for this specific key.
     */
    public function getCounter(string $key): int
    {
        $value = $this->_redis->get($this->buildKey($key));
        return $value === false ? 0 : (int) $value;
    }
}
