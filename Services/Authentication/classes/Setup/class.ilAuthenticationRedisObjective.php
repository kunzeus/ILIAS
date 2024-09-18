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
use ILIAS\Setup\Environment;
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

    public function getPreconditions(Environment $environment): array
    {
        $preconditions = [];
        $preconditions[] = new Setup\Objective\ClientIdReadObjective();
        $preconditions[] = new ilIniFilesPopulatedObjective();
        return $preconditions;
    }

    public function achieve(Environment $environment): Environment
    {
        return $environment;
    }

    /**
     * @throws Exception
     */
    public function isApplicable(Environment $environment): bool
    {
        $client_ini = $environment->getResource(Environment::RESOURCE_CLIENT_INI);

        if ($client_ini->readVariable('session', 'redis_enabled') == 0) {
            echo "Redis not enabled";
            return false;
        }
        try {
            // Initialize Predis client with connection parameters
            $redis_client = new Client([
                'scheme' => 'tcp',
                'host' => $client_ini->readVariable('session', 'redis_host'),
                'port' => (int) $client_ini->readVariable('session', 'redis_port'),
                'password' => $client_ini->readVariable('session', 'redis_auth') == 1 ? $client_ini->readVariable('session', 'redis_password') : null,
                'user' => $client_ini->readVariable('session', 'redis_auth') == 1 ? $client_ini->readVariable('session', 'redis_user') : null,
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
