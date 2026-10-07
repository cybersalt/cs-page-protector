<?php

/**
 * @package    plg_system_cspageprotector
 * @copyright  Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license    GNU General Public License version 2 or later
 */

declare(strict_types=1);

\defined('_JEXEC') or die;

use Cybersalt\Plugin\System\Cspageprotector\Extension\Cspageprotector;
use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            static function (Container $container) {
                $plugin = new Cspageprotector((array) PluginHelper::getPlugin('system', 'cspageprotector'));
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            }
        );
    }
};
