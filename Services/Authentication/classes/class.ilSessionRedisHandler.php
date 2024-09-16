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

use Predis\Client;
use Predis\Connection\ConnectionException;
use Predis\Response\ServerException;

class ilSessionRedisHandler
{
    private static ilSessionRedisHandler $instance;
    private static Client $redis_client;

    private function __construct()
    {
        global $DIC;
        $client_ini = $DIC->clientIni();
        $redis_enabled = $client_ini->readVariable('session', 'redis_enabled');

        if ($redis_enabled == '1') {
            try {
                // Initialize Predis client with connection parameters
                self::$redis_client = new Client([
                    'scheme' => 'tcp',
                    'host'   => $client_ini->readVariable('session', 'redis_host'),
                    'port'   => (int) $client_ini->readVariable('session', 'redis_port'),
                    'password' => $client_ini->readVariable('session', 'redis_auth') == 1 ? $client_ini->readVariable('session', 'redis_password') : null,
                    'user' => $client_ini->readVariable('session', 'redis_auth') == 1 ? $client_ini->readVariable('session', 'redis_user') : null,
                ]);
            } catch (ConnectionException $e) {
                // Handle connection exception
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
        return (bool) $DIC->clientIni()->readVariable('session', 'redis_enabled');
    }

    public static function getTTL(string $key): bool|int
    {
        try {
            if (isset(self::$redis_client)) {
                return self::$redis_client->ttl($key);
            }
        } catch (ServerException | ConnectionException $e) {
            return false;
        }
        return false;
    }

    public static function setExpireTimestamp(string $key, $timeStampInSeconds): bool|int
    {
        if (isset(self::$redis_client)) {
            try {
                return self::$redis_client->expireat($key, $timeStampInSeconds);
            } catch (ServerException | ConnectionException $e) {
                return false;
            }
        }
        return false;
    }

    public static function set(string $key, string $value): bool
    {
        if (isset(self::$redis_client)) {
            try {
                return (bool) self::$redis_client->set($key, $value);
            } catch (ServerException | ConnectionException $e) {
                return false;
            }
        }
        return false;
    }

    public static function exists(string $key): bool
    {
        if (isset(self::$redis_client)) {
            try {
                return (bool) self::$redis_client->exists($key);
            } catch (ServerException | ConnectionException $e) {
                return false;
            }
        }
        return false;
    }

    public static function get(string $key): bool|string
    {
        if (isset(self::$redis_client)) {
            try {
                return self::$redis_client->get($key) ?: false;
            } catch (ServerException | ConnectionException $e) {
                return false;
            }
        }
        return false;
    }

    public static function delete(string $key): bool|int
    {
        if (isset(self::$redis_client)) {
            try {
                return self::$redis_client->del([$key]);
            } catch (ServerException | ConnectionException $e) {
                return false;
            }
        }
        return false;
    }

    public static function keys(): array
    {
        try {
            if (isset(self::$redis_client)) {
                return self::$redis_client->keys('*');
            }
        } catch (ServerException | ConnectionException $e) {
            return [];
        }
        return [];
    }
}
