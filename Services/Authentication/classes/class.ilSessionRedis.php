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

use interfaces\ilSessionBackendInterface;

/**
 * @author Ulf Bischoff <ulf.bischoff@tik.uni-stuttgart.de>
 */
class ilSessionRedis implements ilSessionBackendInterface
{
    public static function isRedisEnabled(): bool
    {
        if (ilSessionRedisHandler::isEnabled()) {
            ilSessionRedisHandler::getInstance();
            return true;
        }
        return false;
    }

    public static function getDataBySessionId(string $session_id): string
    {
        $result = self::getValue($session_id);
        if (is_array($result)) {
            return $result['data'];
        }
        return '';
    }

    public static function lookupExpireTimestampBySessionId(string $session_id): int
    {
        $ttl = ilSessionRedisHandler::getTTL($session_id);
        if (is_int($ttl)) {
            return time() + $ttl;
        }
        return 0;
    }

    public static function writeDataToSessionId(string $session_id, string $data): bool
    {
        if (ilSession::isWebAccessWithoutSessionEnabled()) {
            // Prevent session data written for web access checker
            // when no cookie was sent (e.g. for pdf files linking others).
            // This would result in new session records for each request.
            return true;
        }

        if (!$session_id) {
            return true;
        }

        $now = time();
        // prepare session data
        $value_fields = [
            'createtime' => $now,
            'session_id' => $session_id,
            'user_id' => (int) (ilSession::get('_authsession_user_id') ?? 0),
            'expires' => ilSession::getExpireValue(),
            'data' => $data,
            'ctime' => $now,
            'type' => (int) (ilSession::get('SessionType') ?? 0),
            'context' => ilContext::getType(),
            'remote_addr' => $_SERVER['REMOTE_ADDR'],
        ];
        $value_string = json_encode($value_fields);

        if (self::sessionExists($session_id)) {
            // note that we do this only when inserting the new record
            // updating may get us other contexts for the same session, especially ilContextWAC, which we do not want
            ilSessionRedisHandler::set($session_id, $value_string);
            ilSessionRedisHandler::setExpireTimestamp($session_id, ilSession::getExpireValue());
        } else {
            ilSessionRedisHandler::set($session_id, $value_string);
            ilSessionRedisHandler::setExpireTimestamp($session_id, ilSession::getExpireValue());

            $type = (int) $value_fields['type'];
            if (in_array($type, ilSessionControl::$session_types_controlled, true)) {
                ilSessionStatistics::createRawEntry(
                    $value_fields['session_id'],
                    $type,
                    $value_fields['createtime'],
                    $value_fields['user_id']
                );
            }
        }
        return true;
    }

    public static function sessionExists(string $session_id): bool|int
    {
        if (is_array(self::getValue($session_id))) {
            return true;
        }
        return false;
    }

    public static function destroySession(string|array $session_id, int $closing_context = null, $expired_at = null): bool
    {
        // update other tables expiration should take care of the sessions
        if (!$closing_context) {
            $closing_context = ilSession::$closing_context;
        }
        ilSessionStatistics::closeRawEntry($session_id, $closing_context, $expired_at);
        ilSessionIStorage::destroySession($session_id);

        try {
            // only delete session cookie if it is set in the current request
            global $DIC;
            if ($DIC->http()->wrapper()->cookie()->has(session_name())
                && $DIC->http()->wrapper()->cookie()->retrieve(session_name(), $DIC->refinery()->kindlyTo()->string()) === $session_id) {
                $cookieJar = $DIC->http()->cookieJar()->without(session_name());
                $cookieJar->renderIntoResponseHeader($DIC->http()->response());
            }
        } catch (Throwable $e) {
            // ignore
            // this is needed for "header already"  sent errors when the random cleanup of expired sessions is triggered
        }
        return true;
    }

    public static function destroySessionByUserId(int $user_id): bool
    {
        // used to delete active sessions of users that get deleted (ilObjUser)
        // actually might be usefully
        $keys = ilSessionRedisHandler::keys();
        foreach ($keys as $key) {
            $session_user_id = self::getUserIdBySessionId($key);
            if ($session_user_id == $user_id) {
                ilSessionRedisHandler::delete($key);
            }
        }
        return true;
    }

    public static function destroyExpiredSessions(): int
    {
        return 0;
    }

    public static function duplicateSession(string $session_id): string
    {
        // Create new session id by recreating md5s from md5s
        $new_session_id = $session_id;
        do {
            $new_session = md5($new_session_id);
            $keys = ilSessionRedisHandler::keys();
        } while (!in_array($new_session, $keys));

        // get old session data
        $value = self::getDataBySessionId($session_id);
        if ($value) {
            self::writeDataToSessionId($new_session_id, $value);
            return $new_session_id;
        }
        // TODO: check if throwing an exception might be a better choice
        return "";
    }

    public static function getValue($session_id): bool|array
    {
        $result = ilSessionRedisHandler::get($session_id);
        if (is_bool($result)) {
            return $result;
        } elseif (is_string($result)) {
            return json_decode($result, true);
        }
        // TODO: check if throwing an exception might be a better choice
        return false;
    }

    public static function getUserIdBySessionId(string $session_id): bool|int
    {
        $value = self::getValue($session_id);
        if (is_array($value) && count($value) == 1) {
            return (int) $value['user_id'];
        }
        return false;
    }

    public static function hasMoreThanOneActiveSession($user_id, $session_id): bool
    {
        $keys = ilSessionRedisHandler::keys();
        foreach ($keys as $key) {
            $value = self::getValue($key);
            if ($value['user_id'] == $user_id && $key != $session_id) {
                return true;
            }
        }
        return false;
    }

    public static function getActiveUsers(): array
    {
        $result_array = [];
        $keys = ilSessionRedisHandler::keys();
        foreach ($keys as $key) {
            $result_array[] = self::getValue($key);
        }
        return $result_array;
    }

    public static function getSessionBySessionId(string $session_id): array
    {
        return self::getValue($session_id);
    }
}
