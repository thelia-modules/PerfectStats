<?php

namespace PerfectStats;

use Propel\Runtime\Connection\ConnectionInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use Thelia\Module\BaseModule;

class PerfectStats extends BaseModule
{
    const MESSAGE_DOMAIN = "perfectstats";
    const MODULE_CODE = "PerfectStats";

    public function postActivation(ConnectionInterface $con = null): void
    {
        $currentYear = date('Y');
        self::setConfigValue('current_year', $currentYear);
        self::setConfigValue('previous_year', $currentYear - 1);
    }

    public static function configureServices(ServicesConfigurator $servicesConfigurator): void
    {
        $servicesConfigurator->load(self::getModuleCode().'\\', __DIR__)
            ->exclude([__DIR__ . '/I18n/*'])
            ->autowire(true)
            ->autoconfigure(true);
    }
}
