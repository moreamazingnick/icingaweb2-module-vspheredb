<?php

namespace Icinga\Module\Vspheredb\Clicommands;

use Exception;
use gipfl\Cli\Process;
use Icinga\Module\Vspheredb\Daemon\PerfDataRunner;
use Icinga\Module\Vspheredb\Daemon\RpcWorker;
use Icinga\Module\Vspheredb\Daemon\SyncRunner;
use Icinga\Module\Vspheredb\Db;
use Icinga\Module\Vspheredb\DbObject\VCenter;
use Icinga\Module\Vspheredb\DbObject\VCenterServer;
use Icinga\Module\Vspheredb\PerformanceData\PerformanceSet\VmDiskTagHelper;
use Icinga\Module\Vspheredb\Sync\VCenterInitialization;

/**
 * Sync a vCenter or ESXi host
 */
class TaskCommand extends Command
{
    /**
     * Connect to a vCenter, create/update it's base definition
     *
     * USAGE
     *
     * icingacli vsphere task initialize --serverId <id> [--rpc]
     */
    public function initializeAction()
    {
        $this->loop()->futureTick(function () {
            $hostname = null;
            try {
                Process::setTitle('Icinga::vSphereDB::initialize');
                $server = $this->requireVCenterServer();
                $hostname = $server->get('host');
                Process::setTitle(sprintf('Icinga::vSphereDB::initialize (%s)', $hostname));
                VCenterInitialization::initializeFromServer($server, $this->logger);
                $this->loop()->stop();
            } catch (Exception $e) {
                $this->failFriendly('initialize', $e, $hostname ?: '-');
            }
        });
        $this->loop()->run();
    }

    /**
     * Sync all objects
     *
     * Still a prototype
     *
     * USAGE
     *
     * icingacli vsphere task sync --vCenterId <id> [--rpc]
     */
    public function syncAction()
    {
        $this->loop()->futureTick(function () {
            $subject = null;
            try {
                Process::setTitle('Icinga::vSphereDB::sync');
                $vCenter = $this->requireVCenter();
                $subject = $vCenter->get('name');
                if ($subject === null) {
                    $subject = 'unknown';
                }
                $this->runSync($vCenter, $subject);
            } catch (Exception $e) {
                $this->failFriendly('sync', $e, $subject);
            }
        });
        $this->loop()->run();
    }

    public function workerAction()
    {
        $this->loop()->futureTick(function () {
            try {
                $worker = new RpcWorker($this->rpc, $this->logger, $this->loop());
                $worker->run();
            } catch (Exception $e) {
                $this->failFriendly('worker', $e);
            }
        });
        $this->loop()->run();
    }

    public function demoActionx()
    {
        $vCenter = $this->requireVCenter();
        $helper = new VmDiskTagHelper($vCenter);
        print_r($helper->fetchVmTags());
    }

    protected function runSync(VCenter $vCenter, $subject)
    {
        Process::setTitle(sprintf('Icinga::vSphereDB::sync (%s)', $subject));
        $time = microtime(true);
        (new SyncRunner($vCenter, $this->logger))
            ->showTrace($this->showTrace())
            ->on('beginTask', function ($taskName) use ($subject, &$time) {
                Process::setTitle(sprintf('Icinga::vSphereDB::sync (%s: %s)', $subject, $taskName));
                $time = microtime(true);
            })
            ->on('endTask', function ($taskName) use ($subject, &$time) {
                Process::setTitle(sprintf('Icinga::vSphereDB::sync (%s)', $subject));
                $duration = microtime(true) - $time;
                $this->logger->debug(sprintf(
                    'Task "%s" took %.2Fms on %s',
                    $taskName,
                    ($duration * 1000),
                    $subject
                ));
            })
            ->on('dbError', function (\Zend_Db_Exception $e) use ($subject) {
                Process::setTitle(sprintf('Icinga::vSphereDB::sync (%s: FAILED)', $subject));
                $this->failFriendly('sync', $e, $subject);
            })
            ->run($this->loop())
            ->then(function () use ($subject) {
                $this->failFriendly('sync', 'Sync stopped. Should not happen', $subject);
            })->otherwise(function ($reason = null) use ($subject) {
                $this->failFriendly('sync', $reason, $subject);
            });
    }

    protected function requireVCenter()
    {
        return VCenter::loadWithAutoIncId(
            $this->requiredParam('vCenterId'),
            Db::newConfiguredInstance()
        );
    }

    protected function requireVCenterServer()
    {
        return VCenterServer::loadWithAutoIncId(
            $this->requiredParam('serverId'),
            Db::newConfiguredInstance()
        );
    }
}
