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

namespace interfaces;

interface ilSessionBackendInterface
{
    public static function getDataBySessionId(string $session_id): string;

    public static function lookupExpireTimestampBySessionId(string $session_id): int;

    public static function writeDataToSessionId(string $session_id, string $data): bool;

    public static function sessionExists(string $session_id): bool|int;

    public static function destroySession(string|array $session_id, int $closing_context = null, int|bool $expired_at = null): bool;

    public static function destroySessionByUserId(int $user_id): bool;

    public static function destroyExpiredSessions(): int;

    public static function duplicateSession(string $session_id): string;

    public static function getUserIdBySessionId(string $session_id): bool|int;

    public static function hasMoreThanOneActiveSession(int $user_id, string $session_id): bool;

    public static function getActiveUserIds(): array;

    public static function getSessionBySessionId(string $session_id): array;

}
