<?php

declare(strict_types=1);

/**
 * This file is part of ILIAS, a powerful learning management system
 * published by ILIAS open source e-Learning e.V.
 *
 * ILIAS is licensed with the GPL-3.0,
 * see https://www.gnu.org/licenses/gpl-3.0.en.html
 * You should have received a copy of said license along with the
 * source code, too.
 *
 * If this is not the case or you just want to try ILIAS, you'll find
 * us at:
 * https://www.ilias.de
 * https://github.com/ILIAS-eLearning
 *
 *********************************************************************/

/**
 * @author Ulf Bischoff <ulf.bischoff@tik.uni-stuttgart.de>
 */
class ilSessionRedisHandler
{
    private static ilSessionRedisHandler $instance;
    private static Redis $redis_client;

    private function __construct()
    {
        global $DIC;
        $client_ini = $DIC->clientIni();
        $redis_enabled = $client_ini->readVariable('session', 'redis_enabled');

        if ($redis_enabled == '1') {
            try {
                self::$redis_client = new Redis();
                self::$redis_client->connect(
                    $client_ini->readVariable('session', 'redis_host'),
                    (int) $client_ini->readVariable('session', 'redis_port')
                );
                $redis_auth = $client_ini->readVariable('session', 'redis_auth');
                if ($redis_auth == '1') {
                    self::$redis_client->auth($client_ini->readVariable('session', 'redis_password'));
                }
            } catch (RedisException $e) {
                return false;
            }
        }
        return self::$redis_client;
    }

    public static function getInstance(): ilSessionRedisHandler
    {
        if (!isset(self::$instance)) {
            self::$instance = new ilSessionRedisHandler();
        }
        return self::$instance;
    }

    public static function isEnabled(): bool
    {
        global $DIC;
        if ($DIC->clientIni()->readVariable('session', 'redis_enabled')) {
            return true;
        }
        return false;
    }

    public static function getTTL(string $key): bool|int
    {
        try {
            if(isset(self::$redis_client)) {
                return self::$redis_client->ttl($key);
            }
        } catch (RedisException $e) {
            return false;
        }
        return false;
    }

    public static function setExpireTimestamp(string $key, $timeStampInSeconds): bool
    {
        if(isset(self::$redis_client)) {
            try {
                return self::$redis_client->expireAt($key, $timeStampInSeconds);
            } catch (RedisException $e) {
                return false;
            }
        }
        return true;
    }

    public static function set(string $key, string $value): bool
    {
        if (isset(self::$redis_client)) {
            try {
                return self::$redis_client->set($key, $value);
            } catch (RedisException $e) {
            }
        }
        return false;
    }

    public static function exists(string $key): bool
    {
        if (isset(self::$redis_client)) {
            try {
                return self::$redis_client->exists($key);
            } catch (RedisException $e) {
            }
        }
        return false;
    }

    public static function get(string $key): bool|string
    {
        if (isset(self::$redis_client)) {
            try {
                return self::$redis_client->get($key);
            } catch (RedisException $e) {
            }
        }
        return false;
    }


    public static function delete(string $key): bool|int
    {
        if (isset(self::$redis_client)) {
            try {
                return self::$redis_client->del($key);
            } catch (RedisException $e) {
            }
        }
        return false;
    }

    public static function keys(): array
    {
        try {
            return self::$redis_client->keys('*');
        } catch (RedisException $e) {
        }
    }

}
