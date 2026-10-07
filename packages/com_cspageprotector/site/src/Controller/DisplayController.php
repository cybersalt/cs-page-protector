<?php

/**
 * @package    com_cspageprotector
 * @copyright  Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license    GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Cybersalt\Component\Cspageprotector\Site\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\BaseController;

/**
 * Front-end display controller. The only front-end view is the challenge.
 *
 * @since  0.1.0
 */
final class DisplayController extends BaseController
{
    /**
     * @var    string
     * @since  0.1.0
     */
    protected $default_view = 'challenge';

    /**
     * @param   boolean  $cachable   Ignored: the challenge must never be cached.
     * @param   array    $urlparams  Unused.
     *
     * @return  static
     *
     * @since   0.1.0
     */
    public function display($cachable = false, $urlparams = [])
    {
        $this->input->set('view', 'challenge');

        return parent::display(false, $urlparams);
    }
}
