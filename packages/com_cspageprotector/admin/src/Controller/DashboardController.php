<?php

/**
 * @package    com_cspageprotector
 * @copyright  Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license    GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Cybersalt\Component\Cspageprotector\Administrator\Controller;

\defined('_JEXEC') or die;

use Cybersalt\Component\Cspageprotector\Administrator\Helper\PermissionHelper;
use Cybersalt\Component\Cspageprotector\Administrator\Helper\VerificationHelper;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\Database\DatabaseInterface;

/**
 * Dashboard actions.
 *
 * @since  0.1.0
 */
final class DashboardController extends BaseController
{
    /**
     * Invalidate every visitor's pass by rotating the signing salt. Everyone
     * gets challenged again on their next visit to a protected page.
     *
     * @return  void
     *
     * @since   0.1.0
     */
    public function resetpasses(): void
    {
        $this->checkToken();
        PermissionHelper::requireAdmin();

        try {
            $params = clone ComponentHelper::getParams('com_cspageprotector');
            $params->set('pass_salt', bin2hex(random_bytes(16)));
            $json = $params->toString();

            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $db->createQuery()
                ->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('params') . ' = :params')
                ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('com_cspageprotector'))
                ->bind(':params', $json);

            $db->setQuery($query)->execute();

            $this->setMessage(Text::_('COM_CSPAGEPROTECTOR_RESET_PASSES_DONE'));
        } catch (\Throwable $e) {
            Log::add('cs-page-protector reset passes failed: ' . $e->getMessage(), Log::ERROR, 'com_cspageprotector');
            $this->setMessage(Text::_('COM_CSPAGEPROTECTOR_RESET_PASSES_FAILED'), 'error');
        }

        $this->setRedirect(Route::_('index.php?option=com_cspageprotector&view=dashboard', false));
    }

    /**
     * Clear the pass in the admin's own browser so they can see the check
     * again while testing. Only affects this browser.
     *
     * @return  void
     *
     * @since   0.1.0
     */
    public function clearmypass(): void
    {
        $this->checkToken();
        PermissionHelper::requireView();

        VerificationHelper::clearPass($this->app);

        $this->setMessage(Text::_('COM_CSPAGEPROTECTOR_CLEAR_MY_PASS_DONE'));
        $this->setRedirect(Route::_('index.php?option=com_cspageprotector&view=dashboard', false));
    }
}
