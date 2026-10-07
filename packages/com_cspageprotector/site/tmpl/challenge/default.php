<?php

/**
 * @package    com_cspageprotector
 * @copyright  Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license    GNU General Public License version 2 or later
 *
 * @var \Cybersalt\Component\Cspageprotector\Site\View\Challenge\HtmlView $this
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

$wa = $this->getDocument()->getWebAssetManager();
$wa->getRegistry()->addExtensionRegistryFile('com_cspageprotector');
$wa->useStyle('com_cspageprotector.challenge')->useScript('com_cspageprotector.challenge');
?>
<div class="cspp-challenge">
    <div class="cspp-challenge-card">
        <h1 class="cspp-challenge-heading"><?php echo $this->escape($this->heading); ?></h1>

        <div class="cspp-challenge-message">
            <?php // Admin-authored, filtered with "safehtml" when Options are saved. ?>
            <?php echo $this->message; ?>
        </div>

        <form
            id="cspp-challenge-form"
            class="cspp-challenge-form"
            method="post"
            action="<?php echo $this->formAction; ?>"
            data-auto-start="<?php echo $this->autoStart ? '1' : '0'; ?>"
            data-auto-submit="<?php echo $this->autoSubmit ? '1' : '0'; ?>"
        >
            <?php if ($this->captchaHtml !== '') : ?>
                <div class="cspp-challenge-captcha">
                    <?php echo $this->captchaHtml; ?>
                </div>
            <?php else : ?>
                <div class="alert alert-warning" role="alert">
                    <?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_CHALLENGE_UNAVAILABLE')); ?>
                </div>
            <?php endif; ?>

            <noscript>
                <div class="alert alert-warning" role="alert">
                    <?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_CHALLENGE_NOSCRIPT')); ?>
                </div>
            </noscript>

            <p class="cspp-challenge-status" aria-live="polite" data-working="<?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_CHALLENGE_WORKING')); ?>" data-done="<?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_CHALLENGE_DONE')); ?>"></p>

            <button type="submit" class="btn btn-primary cspp-challenge-submit">
                <?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_CHALLENGE_CONTINUE')); ?>
            </button>

            <input type="hidden" name="return" value="<?php echo $this->escape($this->returnB64); ?>">
            <input type="hidden" name="cspp_item" value="<?php echo (int) $this->itemId; ?>">
            <?php echo HTMLHelper::_('form.token'); ?>
        </form>

        <details class="cspp-challenge-why">
            <summary><?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_CHALLENGE_WHY')); ?></summary>
            <p class="mb-0"><?php echo $this->escape(Text::_('COM_CSPAGEPROTECTOR_CHALLENGE_WHY_BODY')); ?></p>
        </details>
    </div>
</div>
