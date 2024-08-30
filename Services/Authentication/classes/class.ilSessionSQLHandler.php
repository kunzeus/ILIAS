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
class ilSessionSQLHandler
{
    private static function getDB()
    {
        global $DIC;
        return $DIC['ilDB'];
    }

    public static function getDataBySessionId(string $session_id): ?array
    {
        $ilDB = self::getDB();
        $q = "SELECT data FROM usr_session WHERE session_id = " . $ilDB->quote($session_id, "text");
        $set = $ilDB->query($q);
        return $ilDB->fetchAssoc($set);
    }

    public static function lookupExpireTimestampBySessionId(string $session_id): int
    {
        $ilDB = self::getDB();
        $query = 'SELECT expires FROM usr_session WHERE session_id = ' . $ilDB->quote($session_id, 'text');
        $result = $ilDB->query($query);
        if ($row = $result->fetchRow(ilDBConstants::FETCHMODE_OBJECT)) {
            return (int) $row->expires;
        }
        return 0;
    }

    public static function updateSession(array $fields, string $session_id): int
    {
        $ilDB = self::getDB();
        return $ilDB->update(
            'usr_session',
            $fields,
            ['session_id' => [ilDBConstants::T_TEXT, $session_id]]
        );
    }

    public static function insertSession(string $insert_fields, string $insert_values, string $update_values): int
    {
        $ilDB = self::getDB();
        return $ilDB->manipulate(
            'INSERT INTO usr_session (' . $insert_fields . ') '
            . 'VALUES (' . $insert_values . ') '
            . 'ON DUPLICATE KEY UPDATE ' . $update_values
        );
    }

    public static function sessionExists(string $session_id): bool
    {
        $ilDB = self::getDB();
        $q = "SELECT 1 FROM usr_session WHERE session_id = " . $ilDB->quote($session_id, "text");
        $set = $ilDB->query($q);
        return $ilDB->numRows($set) > 0;
    }

    public static function destroySession(string $session_id): void
    {
        $ilDB = self::getDB();
        $q = "DELETE FROM usr_session WHERE session_id = " . $ilDB->quote($session_id, "text");
        $ilDB->manipulate($q);
    }

    public static function destroySessionsByIds(array $session_ids): void
    {
        $ilDB = self::getDB();
        $q = "DELETE FROM usr_session WHERE " . $ilDB->in("session_id", $session_ids, false, "text");
        $ilDB->manipulate($q);
    }

    public static function destroySessionByUserId(int $user_id): void
    {
        $ilDB = self::getDB();
        $q = "DELETE FROM usr_session WHERE user_id = " . $ilDB->quote($user_id, "integer");
        $ilDB->manipulate($q);
    }

    public static function getExpiredSessions(): array|string
    {
        $ilDB = self::getDB();
        $q = 'SELECT session_id, expires FROM usr_session WHERE expires < ' . $ilDB->quote(time(), ilDBConstants::T_INTEGER);
        $res = $ilDB->query($q);
        $ids = [];
        while ($row = $ilDB->fetchAssoc($res)) {
            $ids[$row['session_id']] = (int) $row['expires'];
        }
        return $ids;
    }

    public static function duplicateSessionCheck(string $new_session_id): bool
    {
        $ilDB = self::getDB();
        $q = "SELECT * FROM usr_session WHERE session_id = " . $ilDB->quote($new_session_id, "text");
        $res = $ilDB->query($q);
        $values = $ilDB->fetchAssoc($res);
        if (isset($values)) {
            return true;
        }
        return false;
    }

    public static function getUserIdBySessionId(string $session_id): bool|int
    {
        $ilDB = self::getDB();
        $parts = explode('::', $session_id);
        $query = 'SELECT user_id FROM usr_session WHERE session_id = %s';
        $res = $ilDB->queryF($query, array('text'), array($parts[0]));
        $data = $ilDB->fetchAssoc($res);
        if (isset($data['user_id'])) {
            return $data['user_id'];
        }
        return false;
    }

    public static function hasMoreThanOneActiveSession(int $user_id, string $session_id): bool
    {
        $ilDB = self::getDB();
        $set = $ilDB->queryf(
            'SELECT COUNT(*) session_count
			FROM usr_session WHERE user_id = %s AND expires > %s AND session_id != %s ',
            ['integer', 'integer', 'text'],
            [$user_id, time(), $session_id]
        );
        $row = $ilDB->fetchAssoc($set);
        return (bool) $row['session_count'];
    }

    public static function getSessionBySessionId(string $session_id): array
    {
        $ilDB = self::getDB();
        $query = $ilDB->queryF(
            'SELECT expires, user_id, data FROM usr_session WHERE MD5(session_id) = %s',
            ['text'],
            [$session_id]
        );
        return $ilDB->fetchAssoc($query);
    }

    public static function getActiveUsers(): array
    {
        $ilDB = self::getDB();
        $set = $ilDB->queryf(
            'SELECT * FROM usr_session WHERE expires > %s',
            ['integer'],
            [time()]
        );
        return $ilDB->fetchAssoc($set);

    }
}
