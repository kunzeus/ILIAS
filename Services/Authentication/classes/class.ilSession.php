<?php

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

declare(strict_types=1);

/**
* @author Alex Killing <alex.killing@gmx.de>
*
* @externalTableAccess ilObjUser on usr_session
*/
class ilSession
{
    /**
     *
     * Constant for fixed dession handling
     *
     * @var int
     *
     */
    public const SESSION_HANDLING_FIXED = 0;

    /**
     *
     * Constant for load dependend session handling
     *
     * @var int
     *
     */
    public const SESSION_HANDLING_LOAD_DEPENDENT = 1;

    /**
     * Constant for reason of session destroy
     *
     * @var int
     */
    public const SESSION_CLOSE_USER = 1;  // manual logout
    public const SESSION_CLOSE_EXPIRE = 2;  // has expired
    public const SESSION_CLOSE_FIRST = 3;  // kicked by session control (first abidencer)
    public const SESSION_CLOSE_IDLE = 4;  // kickey by session control (ilde time)
    public const SESSION_CLOSE_LIMIT = 5;  // kicked by session control (limit reached)
    public const SESSION_CLOSE_LOGIN = 6;  // anonymous => login
    public const SESSION_CLOSE_PUBLIC = 7;  // => anonymous
    public const SESSION_CLOSE_TIME = 8;  // account time limit reached
    public const SESSION_CLOSE_IP = 9;  // wrong ip
    public const SESSION_CLOSE_SIMUL = 10; // simultaneous login
    public const SESSION_CLOSE_INACTIVE = 11; // inactive account

    public const USR_SESSION_SCHEMA = [
        "title" => "TEXT",
        "body" => "TEXT",
        "url" => "TAG",
    ];

    public static ?int $closing_context = null;

    protected static bool $enable_web_access_without_session = false;


    private static function getBackend(): \interfaces\ilSessionBackendInterface
    {
        return ilSessionRedis::isRedisEnabled() ? new ilSessionRedis() : new ilSessionSQL();
        //return new ilSessionRedis();
        //return new ilSessionSQL();
    }

    /**
     * Get session data from table
     *
     * According to https://bugs.php.net/bug.php?id=70520 read data must return a string.
     * Otherwise session_regenerate_id might fail with php 7.
     *
     * @param	string		$session_id
     * @return	string		session data
     */
    public static function _getData(string $session_id): string
    {
        if (!$session_id) {
            // fix for php #70520
            return '';
        }
        return self::getBackend()->getDataBySessionId($session_id);
    }

    /**
     * Lookup expire time for a specific session
     * @param string $session_id
     * @return int expired unix timestamp
     */
    public static function lookupExpireTime(string $session_id): int
    {
        return self::getBackend()->lookupExpireTimestampBySessionId($session_id);
    }

    public static function _writeData(string $a_session_id, string $a_data): bool
    {
        return self::getBackend()->writeDataToSessionId($a_session_id, $a_data);
    }

    /**
    * Check whether session exists
    *
    * @param	string		$session_id
    * @return    bool        true, if session id exists
    */
    public static function _exists(string $session_id): bool
    {
        if (!$session_id) {
            return false;
        }
        return self::getBackend()->sessionExists($session_id);
    }

    /**
    * Destroy session
    *
    * @param array|string  $session_id      session id|s
    * @param	int|null   $closing_context closing context
    * @param bool|int|null $expired_at      expired at timestamp
    */
    public static function _destroy(array|string $session_id, ?int $closing_context = null, bool|int $expired_at = null): bool
    {
        return self::getBackend()->destroySession($session_id, $closing_context, $expired_at);
    }

    /**
    * Destroy session
    *
    * @param	int $user_id user id
    */
    public static function _destroyByUserId(int $user_id): bool
    {
        return self::getBackend()->destroySessionByUserId($user_id);
    }

    /**
     * Destroy expired sessions
     * @return int The number of deleted sessions on success
     */
    public static function _destroyExpiredSessions(): int
    {
        return self::getBackend()->destroyExpiredSessions();
    }

    /**
    * Duplicate session
    *
    * @param	string		$session_id
    * @return	string		new session id
    */
    public static function _duplicate(string $session_id): string
    {
        return self::getBackend()->duplicateSession($session_id);

    }

    public static function getUserIdBySessionId(string $session_id): bool|int
    {
        return self::getBackend()->getUserIdBySessionId($session_id);
    }


    public static function hasMoreThanOneActiveSession(int $user_id, string $session_id): bool
    {
        return self::getBackend()->hasMoreThanOneActiveSession($user_id, $session_id);
    }

    public static function getActiveUsers(): array
    {
        return self::getBackend()->getActiveUsers();
    }

    public static function getSessionBySessionId($session_id): array
    {
        return self::getBackend()->getSessionBySessionId($session_id);
    }

    /**
     *
     * Returns the expiration timestamp in seconds
     *
     * @param bool $fixedMode If passed, the value for fixed session is returned
     * @return    int    The expiration timestamp in seconds
     * @static
     *
     */
    public static function getExpireValue(bool $fixedMode = false): int
    {
        global $DIC;

        if ($fixedMode) {
            // fixed session
            return time() + self::getIdleValue($fixedMode);
        }

        /** @var ilSetting $ilSetting */
        $ilSetting = $DIC['ilSetting'];
        if ($ilSetting->get('session_handling_type', (string) self::SESSION_HANDLING_FIXED) === (string) self::SESSION_HANDLING_FIXED) {
            return time() + self::getIdleValue($fixedMode);
        }

        if ($ilSetting->get('session_handling_type', (string) self::SESSION_HANDLING_FIXED) === (string) self::SESSION_HANDLING_LOAD_DEPENDENT) {
            // load dependent session settings
            $max_idle = (int) ($ilSetting->get('session_max_idle') ?? ilSessionControl::DEFAULT_MAX_IDLE);
            return time() + $max_idle * 60;
        }
        return time() + ilSessionControl::DEFAULT_MAX_IDLE * 60;
    }

    /**
     *
     * Returns the idle time in seconds
     *
     * @param bool $fixedMode If passed, the value for fixed session is returned
     * @return    int    The idle time in seconds
     */
    public static function getIdleValue(bool $fixedMode = false): int
    {
        global $DIC;

        $ilSetting = $DIC['ilSetting'];
        $ilClientIniFile = $DIC['ilClientIniFile'];

        if ($fixedMode || $ilSetting->get('session_handling_type', (string) self::SESSION_HANDLING_FIXED) === (string) self::SESSION_HANDLING_FIXED) {
            // fixed session
            return (int) $ilClientIniFile->readVariable('session', 'expire');
        }

        if ($ilSetting->get('session_handling_type', (string) self::SESSION_HANDLING_FIXED) === (string) self::SESSION_HANDLING_LOAD_DEPENDENT) {
            // load dependent session settings
            return ((int) $ilSetting->get('session_max_idle', (string) (ilSessionControl::DEFAULT_MAX_IDLE))) * 60;
        }
        return ilSessionControl::DEFAULT_MAX_IDLE * 60;
    }

    /**
     *
     * Returns the session expiration value
     *
     * @return int    The expiration value in seconds
     *
     */
    public static function getSessionExpireValue(): int
    {
        return self::getIdleValue(true);
    }

    /**
     * Set a value
     */
    public static function set(string $key, $value): void
    {
        $_SESSION[$key] = $value;
    }

    /**
     * @return mixed|null
     */
    public static function get(string $key): mixed
    {
        return $_SESSION[$key] ?? null;
    }

    public static function has($key): bool
    {
        return isset($_SESSION[$key]);
    }

    /**
     * @param string $a_var
     */
    public static function clear(string $key): void
    {
        if (isset($_SESSION[$key])) {
            unset($_SESSION[$key]);
        }
    }

    public static function dumpToString(): string
    {
        return print_r($_SESSION, true);
    }

    /**
     * set closing context (for statistics)
     */
    public static function setClosingContext(int $context): void
    {
        self::$closing_context = $context;
    }

    /**
     * get closing context (for statistics)
     */
    public static function getClosingContext(): int
    {
        return self::$closing_context;
    }

    /**
     * @return bool
     */
    public static function isWebAccessWithoutSessionEnabled(): bool
    {
        return self::$enable_web_access_without_session;
    }

    /**
     * @param bool $enable_web_access_without_session
     */
    public static function enableWebAccessWithoutSession(bool $enable_web_access_without_session): void
    {
        self::$enable_web_access_without_session = $enable_web_access_without_session;
    }
}
