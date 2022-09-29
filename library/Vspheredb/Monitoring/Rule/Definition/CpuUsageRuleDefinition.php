<?php

namespace Icinga\Module\Vspheredb\Monitoring\Rule\Definition;

use Icinga\Module\Vspheredb\DbObject\BaseDbObject;
use Icinga\Module\Vspheredb\DbObject\HostQuickStats;
use Icinga\Module\Vspheredb\DbObject\HostSystem;
use Icinga\Module\Vspheredb\DbObject\VirtualMachine;
use Icinga\Module\Vspheredb\DbObject\VmQuickStats;
use Icinga\Module\Vspheredb\Format;
use Icinga\Module\Vspheredb\Monitoring\CheckPluginState;
use Icinga\Module\Vspheredb\Monitoring\Rule\Enum\ObjectType;
use Icinga\Module\Vspheredb\Monitoring\Rule\Settings;
use Icinga\Module\Vspheredb\Monitoring\SingleCheckResult;

class CpuUsageRuleDefinition extends MonitoringRuleDefinition
{
    public const SUPPORTED_OBJECT_TYPES = [
        ObjectType::HOST_SYSTEM,
        ObjectType::VIRTUAL_MACHINE,
    ];

    public static function getIdentifier(): string
    {
        return 'CpuUsage';
    }

    public function getLabel(): string
    {
        return $this->translate('CPU Usage');
    }

    public function getInternalDefaults(): array
    {
        return [];
    }

    public function checkObject(BaseDbObject $object, Settings $settings): array
    {
        $this->assertSupportedObject($object);

        if ($object instanceof HostSystem) {
            $quickStats = HostQuickStats::loadFor($object);
            $cpuCount = $object->get('hardware_cpu_cores');
            $mhzSingleCpu = $object->get('hardware_cpu_mhz');
        } else {
            assert($object instanceof VirtualMachine);
            $quickStats = VmQuickStats::loadFor($object);
            $cpuCount = $object->get('hardware_numcpu');
            if ($object->hasRuntimeHost()) {
                $mhzSingleCpu = $object->getRuntimeHost()->get('hardware_cpu_mhz');
            } else {
                $mhzSingleCpu = 2000;
            }
        }
        $mhzUsed = $quickStats->get('overall_cpu_usage');
        $mhzCapacity = $mhzSingleCpu * $cpuCount;
        $mhzFree = $mhzCapacity - $mhzUsed;

        $percentFree = $mhzFree / $mhzCapacity * 100;
        $output = sprintf(
            '%s out of %s used, %s (%.2F%%) free',
            Format::mhz($mhzUsed),
            Format::mhz($mhzCapacity),
            Format::mhz($mhzFree),
            $percentFree
        );

        $state = new CheckPluginState();
        $min = $settings->get('warning_if_less_than_percent_free');
        if ($min && ($percentFree < (float) $min)) {
            $state->raiseState(CheckPluginState::WARNING);
        }
        $min = $settings->get('critical_if_less_than_percent_free');
        if ($min && ($percentFree < (float) $min)) {
            $state->raiseState(CheckPluginState::CRITICAL);
        }

        return [
            new SingleCheckResult($state, $output)
        ];
    }

    public function getParameters(): array
    {
        return [
            'warning_if_less_than_percent_free' => ['number', [
                'label' => $this->translate('Raise Warning with less then X percent free'),
                'placeholder' => '30',
            ]],
            'critical_if_less_than_percent_free' => ['number', [
                'label' => $this->translate('Raise Critical with less then X percent free'),
                'placeholder' => '10',
            ]],
        ];
    }
}
