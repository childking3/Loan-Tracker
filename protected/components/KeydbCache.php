<?php

namespace app\components;

use Yii;
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
    public ?string $password = null;
    public float $connectTimeout = 2.0;
    // Separate from connectTimeout on purpose: that one only bounds the
    // initial pconnect(). Without this, a KeyDB that accepts the connection
    // but then stops responding mid-request (a network partition, not a
    // clean refusal) never throws \RedisException - the socket read just
    // blocks on PHP's default_socket_timeout (60s), stalling every request
    // that touches cache, including the once-a-second dashboard poll and
    // the login throttle, instead of degrading gracefully as intended.
    public float $readTimeout = 2.0;

    private ?\Redis $_redis = null;

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
        try {
            $redis = new \Redis();
            $redis->pconnect($this->host, $this->port, $this->connectTimeout);
            $redis->setOption(\Redis::OPT_READ_TIMEOUT, $this->readTimeout);
            if ($this->password !== null && $this->password !== '') {
                $redis->auth($this->password);
            }
            if ($this->database !== 0) {
                $redis->select($this->database);
            }
            $this->_redis = $redis;
        } catch (\RedisException $e) {
            Yii::warning('KeyDB unavailable, running without cache: ' . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * Runs one KeyDB command. If KeyDB is down (at connect time or
     * mid-request) the cache behaves as empty rather than taking the whole
     * app with it: reads miss, writes are dropped, and the login throttle
     * fails open (logged). Dashboard totals and RBAC then recompute from
     * the database on every request until KeyDB returns.
     */
    private function run(callable $command, $fallback)
    {
        if ($this->_redis === null) {
            return $fallback;
        }
        try {
            return $command($this->_redis);
        } catch (\RedisException $e) {
            Yii::warning('KeyDB command failed: ' . $e->getMessage(), __METHOD__);
            return $fallback;
        }
    }

    protected function getValue($key)
    {
        return $this->run(fn ($r) => $r->get($key), false);
    }

    protected function setValue($key, $value, $duration)
    {
        return $this->run(
            fn ($r) => $duration > 0 ? $r->set($key, $value, $duration) : $r->set($key, $value),
            false
        );
    }

    protected function addValue($key, $value, $duration)
    {
        return $this->run(
            fn ($r) => $duration > 0 ? $r->set($key, $value, ['nx', 'ex' => $duration]) : $r->setnx($key, $value),
            false
        );
    }

    protected function deleteValue($key)
    {
        return $this->run(fn ($r) => $r->del($key) > 0, false);
    }

    protected function flushValues()
    {
        return $this->run(fn ($r) => $r->flushDB(), false);
    }

}
