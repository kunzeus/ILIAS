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

use ILIAS\Setup;
use Predis\Client;
use Predis\Connection\ConnectionException;

class ilAuthenticationRedisObjective implements Setup\Objective
{
    public function getHash(): string
    {
        return hash("sha256", self::class);
    }

    public function getLabel(): string
    {
        return "Redis Server Connection check";
    }

    public function isNotable(): bool
    {
        return true;
    }

    public function getPreconditions(\ILIAS\Setup\Environment $environment): array
    {
        $preconditions = [];
        $preconditions[] = new Setup\Objective\ClientIdReadObjective();
        $preconditions[] = new ilIniFilesPopulatedObjective();
        return $preconditions;
    }

    public function achieve(\ILIAS\Setup\Environment $environment): \ILIAS\Setup\Environment
    {
        return $environment;
    }

    /**
     * @throws Exception
     */
    public function isApplicable(\ILIAS\Setup\Environment $environment): bool
    {
        $client_ini = $environment->getResource(Setup\Environment::RESOURCE_CLIENT_INI);
        $ilias_ini = $environment->getResource(Setup\Environment::RESOURCE_ILIAS_INI);

        echo "db_host: " . $client_ini->readVariable('db', 'host'). "\n";
        echo "db_user: " . $client_ini->readVariable('db', 'user'). "\n";
        echo "db_name: " . $client_ini->readVariable('db', 'name'). "\n";
        echo "redis_enabled: " . $client_ini->readVariable('sessions', 'redis_enabled'). "\n";
        echo "redis_auth: " . $client_ini->readVariable('sessions', 'redis_auth'). "\n";
        echo "expire: " . $client_ini->readVariable('sessions', 'expire'). "\n";
        echo "system_Folder_ID: " . $client_ini->readVariable('system', 'SYSTEM_FOLDER_ID'). "\n";

        if ($client_ini->readVariable('session', 'redis_enabled') == 0) {
            echo "Redis not enabled";
            return false;
        }
        try {
            // Initialize Predis client with connection parameters
            $redis_client = new Client([
                'scheme'   => 'tcp',
                'host'     => $client_ini->readVariable('session', 'redis_host'),
                'port'     => (int) $client_ini->readVariable('session', 'redis_port'),
                'password' => $client_ini->readVariable('session', 'redis_auth') == 1 ? $client_ini->readVariable('session', 'redis_password') : null,
                'user'     => $client_ini->readVariable('session', 'redis_auth') == 1 ? $client_ini->readVariable('session', 'redis_user') : null,
            ]);
            echo "finished redis client without exception";
            $redis_client->set('setup_session_key', 'setup_session_value');
            $redis_client->del('setup_session_key');
            echo "without exception";
        } catch (ConnectionException $e) {
            // Handle connection exception
            throw new Exception("Redis is enabled, but something is probably misconfigured  Error: " . $e->getMessage());
        } catch (Exception $e) {
            throw new Exception("general exception" . $e->getMessage());
        }

        return true;
    }
}
