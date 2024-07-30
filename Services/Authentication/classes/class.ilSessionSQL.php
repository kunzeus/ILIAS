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
class ilSessionSQL implements ilSessionBackendInterface
{
    public static function getDataBySessionId(string $session_id): string
    {
        $rec = ilSessionSQLHandler::getDataBySessionId($session_id);
        if (!is_array($rec)) {
            return '';
        }

        // fix for php #70520
        return (string) $rec["data"];
    }

    public static function lookupExpireTimestampBySessionId(string $session_id): int
    {
        return ilSessionSQLHandler::lookupExpireTimestampBySessionId($session_id);
    }

    public static function writeDataToSessionId(string $session_id, string $data): bool
    {
        global $DIC;
        $ilDB = $DIC['ilDB'];

        /** @var ilIniFile $ilClientIniFile */
        $ilClientIniFile = $DIC['ilClientIniFile'];

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
        $fields = [
            'user_id' => [ilDBConstants::T_INTEGER, (int) (ilSession::get('_authsession_user_id') ?? 0)],
            'expires' => [ilDBConstants::T_INTEGER, ilSession::getExpireValue()],
            'data' => [ilDBConstants::T_CLOB, $data],
            'ctime' => [ilDBConstants::T_INTEGER, $now],
            'type' => [ilDBConstants::T_INTEGER, (int) (ilSession::get('SessionType') ?? 0)],
        ];
        if ($ilClientIniFile->readVariable('session', 'save_ip')) {
            $fields['remote_addr'] = [ilDBConstants::T_TEXT, $_SERVER['REMOTE_ADDR'] ?? ''];
        }

        if (self::sessionExists($session_id)) {
            // note that we do this only when inserting the new record
            // updating may get us other contexts for the same session, especially ilContextWAC, which we do not want
            if (class_exists('ilContext') && ilContext::isSessionMainContext()) {
                $fields['context'] = [ilDBConstants::T_TEXT, ilContext::getType()];
            }
            ilSessionSQLHandler::updateSession($fields, $session_id);
        } else {
            $fields['session_id'] = [ilDBConstants::T_TEXT, $session_id];
            $fields['createtime'] = [ilDBConstants::T_INTEGER, $now];

            // note that we do this only when inserting the new record
            // updating may get us other contexts for the same session, especially ilContextWAC, which we do not want
            if (class_exists('ilContext')) {
                $fields['context'] = [ilDBConstants::T_TEXT, ilContext::getType()];
            }

            $insert_fields = implode(', ', array_keys($fields));
            $insert_values = implode(
                ', ',
                array_map(
                    static fn(string $type, $value) : string => $ilDB->quote($value, $type),
                    array_column($fields, 0),
                    array_column($fields, 1)
                )
            );

            $update_fields = array_filter(
                $fields,
                static fn(string $field) : bool => !in_array($field, ['session_id', 'user_id', 'createtime'], true),
                ARRAY_FILTER_USE_KEY
            );
            $update_values = implode(
                ', ',
                array_map(
                    static fn(string $field, string $type, $value) : string => $field . ' = ' . $ilDB->quote(
                            $value,
                            $type
                        ),
                    array_keys($update_fields),
                    array_column($update_fields, 0),
                    array_column($update_fields, 1)
                )
            );

            ilSessionSQLHandler::insertSession($insert_fields, $insert_values, $update_values);

            // check type against session control
            $type = (int) $fields['type'][1];
            if (in_array($type, ilSessionControl::$session_types_controlled, true)) {
                ilSessionStatistics::createRawEntry(
                    $fields['session_id'][1],
                    $type,
                    $fields['createtime'][1],
                    $fields['user_id'][1]
                );
            }
        }

        if (!$DIC->cron()->manager()->isJobActive('auth_destroy_expired_sessions')) {
            // finally delete deprecated sessions
            $random = new ilRandom();
            if ($random->int(0, 50) === 2) {
                // get time _before_ destroying expired sessions
                self::destroyExpiredSessions();
                ilSessionStatistics::aggretateRaw($now);
            }
        }

        return true;
    }

    public static function sessionExists(string $session_id): bool|int
    {
        return ilSessionSQLHandler::sessionExists($session_id);
    }

    public static function destroySession(
        string|array $session_id,
        int|null $closing_context = null,
        int|bool $expired_at = null
    ) : bool {
        global $DIC;

        if (!$closing_context) {
            $closing_context = ilSession::$closing_context;
        }

        ilSessionStatistics::closeRawEntry($session_id, $closing_context, $expired_at);

        if (!is_array($session_id)) {
            ilSessionSQLHandler::destroySession($session_id);
        } else {
            // array: id => timestamp - so we get rid of timestamps
            if ($expired_at) {
                $session_id = array_keys($session_id);
            }
            ilSessionSQLHandler::destroySessionsByIds($session_id);
        }

        ilSessionIStorage::destroySession($session_id);

        try {
            // only delete session cookie if it is set in the current request
            if ($DIC->http()->wrapper()->cookie()->has(session_name()) &&
                $DIC->http()->wrapper()->cookie()->retrieve(session_name(),
                    $DIC->refinery()->kindlyTo()->string()) === $session_id) {
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
        ilSessionSQLHandler::destroySessionByUserId($user_id);
        return true;
    }

    public static function destroyExpiredSessions(): int
    {
        $ids = ilSessionSQLHandler::getExpiredSessions();
        if (is_array($ids)) {
            self::destroySession($ids, ilSession::SESSION_CLOSE_EXPIRE, true);
            return count($ids);
        }
        return 0;
    }

    public static function duplicateSession(string $session_id): string
    {
        // Create new session id
        $new_session_id = $session_id;
        do {
            $new_session_id = md5($new_session_id);
        } while (ilSessionSQLHandler::duplicateSessionCheck($new_session_id));

        //$res = ilSessionSQLHandler::duplicateSessionFetch($session_id);
        $data = ilSessionSQL::getDataBySessionId($session_id);
        if ($data) {
            self::writeDataToSessionId($new_session_id, $data);
            return $new_session_id;
        }
        // TODO: check if throwing an exception might be a better choice
        return "";
    }

    public static function getUserIdBySessionId(string $session_id): bool|int
    {
        return ilSessionSQLHandler::getUserIdBySessionId($session_id);
    }

    public static function hasMoreThanOneActiveSession(int $user_id, string $session_id): bool
    {
        return ilSessionSQLHandler::hasMoreThanOneActiveSession($user_id, $session_id);
    }

    public static function getActiveUserIds(): array
    {
        $result = ilSessionSQLHandler::getActiveUserIds();
        return array_column($result, 'user_id');
    }

    public static function getSessionBySessionId(string $session_id) : array
    {
        return ilSessionSQLHandler::getSessionBySessionId($session_id);
    }
}
