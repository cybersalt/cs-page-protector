<?php

/**
 * @package    com_cspageprotector
 * @copyright  Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license    GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Cybersalt\Component\Cspageprotector\Administrator\Helper;

\defined('_JEXEC') or die;

use Joomla\Registry\Registry;

/**
 * Client-IP resolution, allowlist matching (single IPs and CIDR ranges, v4 + v6)
 * and log anonymisation.
 *
 * @since  0.1.0
 */
final class IpHelper
{
    /**
     * Resolve the visitor's IP address.
     *
     * Proxy headers are only trusted when the admin has explicitly said the site
     * sits behind that proxy (`ip_source` option) — otherwise a scraper could
     * send its own `X-Forwarded-For` and pretend to be an allowlisted address.
     *
     * @param   Registry  $params  Component params.
     *
     * @return  string  A validated IP, or an empty string when none is available.
     *
     * @since   0.1.0
     */
    public static function getClientIp(Registry $params): string
    {
        $remote = self::valid((string) ($_SERVER['REMOTE_ADDR'] ?? ''));

        $candidate = match ((string) $params->get('ip_source', 'remote_addr')) {
            'cf_connecting_ip' => (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''),
            'x_real_ip'        => (string) ($_SERVER['HTTP_X_REAL_IP'] ?? ''),
            // Left-most entry is the original client as reported by the first proxy.
            'x_forwarded_for'  => trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))[0]),
            default            => '',
        };

        return self::valid($candidate) ?: $remote;
    }

    /**
     * Does the IP match any entry in a newline / comma separated allowlist?
     * Entries may be single addresses or CIDR ranges. Lines starting with # are
     * comments.
     *
     * @param   string  $ip    The address to test.
     * @param   string  $list  The raw allowlist text from the options form.
     *
     * @return  boolean
     *
     * @since   0.1.0
     */
    public static function matchesList(string $ip, string $list): bool
    {
        if ($ip === '' || trim($list) === '') {
            return false;
        }

        foreach (preg_split('/[\r\n,]+/', $list) ?: [] as $entry) {
            $entry = trim(preg_replace('/#.*$/', '', $entry) ?? '');

            if ($entry !== '' && self::matches($ip, $entry)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Match one IP against one entry (address or CIDR).
     *
     * @param   string  $ip     The address to test.
     * @param   string  $entry  Single address or CIDR range.
     *
     * @return  boolean
     *
     * @since   0.1.0
     */
    public static function matches(string $ip, string $entry): bool
    {
        $ipBin = @inet_pton($ip);

        if ($ipBin === false) {
            return false;
        }

        if (!str_contains($entry, '/')) {
            $entryBin = @inet_pton($entry);

            return $entryBin !== false && hash_equals($entryBin, $ipBin);
        }

        [$subnet, $bits] = explode('/', $entry, 2);
        $subnetBin       = @inet_pton(trim($subnet));

        if ($subnetBin === false || \strlen($subnetBin) !== \strlen($ipBin) || !ctype_digit(trim($bits))) {
            return false;
        }

        $bits     = (int) $bits;
        $maxBits  = \strlen($ipBin) * 8;

        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }

        $fullBytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if (substr($ipBin, 0, $fullBytes) !== substr($subnetBin, 0, $fullBytes)) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (\ord($ipBin[$fullBytes]) & $mask) === (\ord($subnetBin[$fullBytes]) & $mask);
    }

    /**
     * Strip the host part of an address for privacy-friendly logging:
     * IPv4 keeps the first three octets, IPv6 keeps the first 48 bits.
     *
     * @param   string  $ip  The address.
     *
     * @return  string
     *
     * @since   0.1.0
     */
    public static function anonymize(string $ip): string
    {
        $bin = @inet_pton($ip);

        if ($bin === false) {
            return '';
        }

        if (\strlen($bin) === 4) {
            return inet_ntop(substr($bin, 0, 3) . "\0") ?: '';
        }

        return inet_ntop(substr($bin, 0, 6) . str_repeat("\0", 10)) ?: '';
    }

    /**
     * Return the address when it is a syntactically valid IP, otherwise ''.
     *
     * @param   string  $ip  Candidate address.
     *
     * @return  string
     *
     * @since   0.1.0
     */
    private static function valid(string $ip): string
    {
        $ip = trim($ip);

        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '';
    }
}
