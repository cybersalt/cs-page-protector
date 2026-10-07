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
use Joomla\CMS\MVC\Controller\BaseController;

/**
 * Default admin controller: dashboard and event log.
 *
 * @since  0.1.0
 */
final class DisplayController extends BaseController
{
    /**
     * @var    string
     * @since  0.1.0
     */
    protected $default_view = 'dashboard';

    /**
     * @param   boolean  $cachable   Unused.
     * @param   array    $urlparams  Unused.
     *
     * @return  static
     *
     * @since   0.1.0
     */
    public function display($cachable = false, $urlparams = [])
    {
        PermissionHelper::requireView();

        $view = $this->input->getCmd('view', $this->default_view);

        if (!\in_array($view, ['dashboard', 'logs'], true)) {
            $this->input->set('view', $this->default_view);
        }

        return parent::display(false, $urlparams);
    }
}
