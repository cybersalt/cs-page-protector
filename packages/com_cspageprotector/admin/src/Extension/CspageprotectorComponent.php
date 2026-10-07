<?php

/**
 * @package    com_cspageprotector
 * @copyright  Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license    GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Cybersalt\Component\Cspageprotector\Administrator\Extension;

\defined('_JEXEC') or die;

use Joomla\CMS\Extension\BootableExtensionInterface;
use Joomla\CMS\Extension\MVCComponent;
use Psr\Container\ContainerInterface;

/**
 * Component class for Cybersalt Page Protector.
 *
 * @since  0.1.0
 */
final class CspageprotectorComponent extends MVCComponent implements BootableExtensionInterface
{
    /**
     * Reserved for future bootstrap (e.g. registering more challenge providers).
     *
     * @param   ContainerInterface  $container  The container.
     *
     * @return  void
     *
     * @since   0.1.0
     */
    public function boot(ContainerInterface $container): void
    {
    }
}
