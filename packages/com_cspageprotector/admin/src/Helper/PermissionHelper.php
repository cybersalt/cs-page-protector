<?php

/**
 * @package    com_cspageprotector
 * @copyright  Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license    GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Cybersalt\Component\Cspageprotector\Administrator\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

/**
 * One-line ACL gates for every admin controller method.
 *
 * - view  = open the dashboard and the event log (cspageprotector.view)
 * - write = delete log rows, clear the log (cspageprotector.write)
 * - admin = reset every visitor's pass (core.admin)
 *
 * Super Users pass every check through core.admin.
 *
 * @since  0.1.0
 */
final class PermissionHelper
{
    /**
     * @return  boolean
     *
     * @since   0.1.0
     */
    public static function canView(): bool
    {
        return self::authorise('cspageprotector.view') || self::authorise('core.admin');
    }

    /**
     * @return  boolean
     *
     * @since   0.1.0
     */
    public static function canWrite(): bool
    {
        return self::authorise('cspageprotector.write') || self::authorise('core.admin');
    }

    /**
     * @return  boolean
     *
     * @since   0.1.0
     */
    public static function canAdmin(): bool
    {
        return self::authorise('core.admin');
    }

    /**
     * Throw when the current user may not view.
     *
     * @return  void
     *
     * @throws  NotAllowed
     *
     * @since   0.1.0
     */
    public static function requireView(): void
    {
        if (!self::canView()) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }
    }

    /**
     * Throw when the current user may not write.
     *
     * @return  void
     *
     * @throws  NotAllowed
     *
     * @since   0.1.0
     */
    public static function requireWrite(): void
    {
        if (!self::canWrite()) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }
    }

    /**
     * Throw when the current user is not a component admin.
     *
     * @return  void
     *
     * @throws  NotAllowed
     *
     * @since   0.1.0
     */
    public static function requireAdmin(): void
    {
        if (!self::canAdmin()) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }
    }

    /**
     * @param   string  $action  ACL action.
     *
     * @return  boolean
     *
     * @since   0.1.0
     */
    private static function authorise(string $action): bool
    {
        $user = Factory::getApplication()->getIdentity();

        return $user !== null && (bool) $user->authorise($action, 'com_cspageprotector');
    }
}
