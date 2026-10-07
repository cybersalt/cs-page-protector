<?php

/**
 * @package    com_cspageprotector
 * @copyright  Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license    GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Cybersalt\Component\Cspageprotector\Administrator\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Uri\Uri;
use Joomla\Registry\Registry;

/**
 * Remembers that a visitor has passed the challenge.
 *
 * Two layers:
 *  1. A session flag — cheapest check, gone when the Joomla session ends.
 *  2. A signed "pass" cookie — survives session expiry for the configured
 *     lifetime. Format: `v1.<expires>.<bind>.<hmac>`. The HMAC key is derived
 *     from the site's `$secret` plus a per-install salt the admin can rotate
 *     ("Reset all passes") to invalidate every outstanding cookie at once.
 *     The cookie is bound to a hash of the User-Agent (and optionally the IP)
 *     so a pass harvested by one client can't simply be replayed by a fleet of
 *     different scrapers.
 *
 * @since  0.1.0
 */
final class VerificationHelper
{
    public const COOKIE_NAME = 'cspp_pass';

    private const SESSION_KEY = 'com_cspageprotector.pass';

    private const VERSION = 'v1';

    /**
     * Has this visitor already passed the challenge?
     *
     * @param   CMSApplicationInterface  $app        The application.
     * @param   Registry                 $params     Component params.
     * @param   string                   $ip         Client IP.
     * @param   string                   $userAgent  Client User-Agent.
     *
     * @return  boolean
     *
     * @since   0.1.0
     */
    public static function isVerified(CMSApplicationInterface $app, Registry $params, string $ip, string $userAgent): bool
    {
        $bind       = self::bindHash($params, $ip, $userAgent);
        $generation = self::generation($params);
        $now        = time();

        // The cookie is the pass. No cookie, no pass - even mid-session - so
        // clearing it (or "Clear my pass" on the dashboard) always resets.
        $cookie = (string) $app->getInput()->cookie->getCmd(self::COOKIE_NAME, '');

        if ($cookie === '') {
            return false;
        }

        $cookieHash = hash('sha256', $cookie);
        $session    = $app->getSession()->get(self::SESSION_KEY);

        // Session is only a cache of "this exact cookie already checked out".
        if (
            \is_array($session)
            && ($session['gen'] ?? '') === $generation
            && hash_equals((string) ($session['bind'] ?? ''), $bind)
            && hash_equals((string) ($session['cookie'] ?? ''), $cookieHash)
            && (int) ($session['until'] ?? 0) > $now
        ) {
            return true;
        }

        $parts = explode('.', $cookie);

        if (\count($parts) !== 4 || $parts[0] !== self::VERSION || !ctype_digit($parts[1])) {
            return false;
        }

        [$version, $expires, $cookieBind, $signature] = $parts;

        if ((int) $expires <= $now || !hash_equals($bind, $cookieBind)) {
            return false;
        }

        $expected = self::sign($app, $params, $version . '|' . $expires . '|' . $cookieBind);

        if (!hash_equals($expected, $signature)) {
            return false;
        }

        // Re-prime the session so the next request skips the HMAC.
        $app->getSession()->set(self::SESSION_KEY, ['gen' => $generation, 'bind' => $bind, 'cookie' => $cookieHash, 'until' => (int) $expires]);

        return true;
    }

    /**
     * Remove this browser's pass (cookie + session cache). Used by the
     * dashboard's "Clear my pass" button so admins can re-test the check.
     *
     * @param   CMSApplicationInterface  $app  The application.
     *
     * @return  void
     *
     * @since   0.1.0
     */
    public static function clearPass(CMSApplicationInterface $app): void
    {
        $app->getSession()->remove(self::SESSION_KEY);

        $app->getInput()->cookie->set(self::COOKIE_NAME, '', [
            'expires'  => time() - 3600,
            'path'     => (string) ($app->get('cookie_path') ?: '/'),
            'domain'   => (string) $app->get('cookie_domain', ''),
            'secure'   => Uri::getInstance()->isSsl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * Record a successful challenge in the session and the pass cookie.
     *
     * @param   CMSApplicationInterface  $app        The application.
     * @param   Registry                 $params     Component params.
     * @param   string                   $ip         Client IP.
     * @param   string                   $userAgent  Client User-Agent.
     *
     * @return  void
     *
     * @since   0.1.0
     */
    public static function markVerified(CMSApplicationInterface $app, Registry $params, string $ip, string $userAgent): void
    {
        $hours   = max(1, min(8760, (int) $params->get('pass_lifetime', 24)));
        $expires = time() + ($hours * 3600);
        $bind    = self::bindHash($params, $ip, $userAgent);

        $payload = self::VERSION . '|' . $expires . '|' . $bind;
        $value   = self::VERSION . '.' . $expires . '.' . $bind . '.' . self::sign($app, $params, $payload);

        $app->getSession()->set(self::SESSION_KEY, ['gen' => self::generation($params), 'bind' => $bind, 'cookie' => hash('sha256', $value), 'until' => $expires]);

        $app->getInput()->cookie->set(self::COOKIE_NAME, $value, [
            'expires'  => $expires,
            'path'     => (string) ($app->get('cookie_path') ?: '/'),
            'domain'   => (string) $app->get('cookie_domain', ''),
            'secure'   => Uri::getInstance()->isSsl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * Hash of what the pass is bound to (User-Agent, plus IP when configured).
     *
     * @param   Registry  $params     Component params.
     * @param   string    $ip         Client IP.
     * @param   string    $userAgent  Client User-Agent.
     *
     * @return  string  32 hex chars.
     *
     * @since   0.1.0
     */
    private static function bindHash(Registry $params, string $ip, string $userAgent): string
    {
        $material = $userAgent;

        if ((int) $params->get('bind_ip', 0) === 1) {
            $material .= '|' . $ip;
        }

        return substr(hash('sha256', $material), 0, 32);
    }

    /**
     * Short fingerprint of the current salt — changes when passes are reset.
     *
     * @param   Registry  $params  Component params.
     *
     * @return  string
     *
     * @since   0.1.0
     */
    private static function generation(Registry $params): string
    {
        return substr(hash('sha256', (string) $params->get('pass_salt', '')), 0, 12);
    }

    /**
     * HMAC-SHA256 with a key derived from the site secret and the pass salt.
     *
     * @param   CMSApplicationInterface  $app      The application.
     * @param   Registry                 $params   Component params.
     * @param   string                   $payload  Data to sign.
     *
     * @return  string
     *
     * @since   0.1.0
     */
    private static function sign(CMSApplicationInterface $app, Registry $params, string $payload): string
    {
        $key = hash_hmac('sha256', 'com_cspageprotector|' . (string) $params->get('pass_salt', ''), (string) $app->get('secret'));

        return hash_hmac('sha256', $payload, $key);
    }
}
