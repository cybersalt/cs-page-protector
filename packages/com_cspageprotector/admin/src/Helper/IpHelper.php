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
        $source = (string) $params->get('ip_source', 'remote_addr');

        if ($source === 'remote_addr' || $remote === '') {
            return $remote;
        }

        $trustedList = (string) $params->get('trusted_proxies', '');
        $isTrusted   = static function (string $ip) use ($trustedList, $source): bool {
            if ($source === 'cf_connecting_ip' && self::isCloudflare($ip)) {
                return true;
            }

            if (trim($trustedList) !== '') {
                return self::matchesList($ip, $trustedList);
            }

            // No list given: a proxy on the same machine or private network.
            return self::isPrivate($ip);
        };

        // A header is only believed when the connection itself comes from the
        // proxy that sets it. Otherwise anyone reaching the origin directly
        // could send their own header and pick an allow-listed address.
        if (!$isTrusted($remote)) {
            return $remote;
        }

        if ($source === 'cf_connecting_ip') {
            return self::valid((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '')) ?: $remote;
        }

        if ($source === 'x_real_ip') {
            return self::valid((string) ($_SERVER['HTTP_X_REAL_IP'] ?? '')) ?: $remote;
        }

        if ($source === 'x_forwarded_for') {
            // Proxies append to whatever the client sent, so the left end is
            // client-controlled. Walk from the right, skipping our own proxies;
            // the first address that isn't one of them is the real client.
            $hops = array_reverse(array_map('trim', explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))));

            foreach ($hops as $hop) {
                $hop = self::valid($hop);

                if ($hop === '' || $isTrusted($hop)) {
                    continue;
                }

                return $hop;
            }
        }

        return $remote;
    }

    /**
     * Is the address one of Cloudflare's published edge ranges?
     * (https://www.cloudflare.com/ips/ — update if Cloudflare adds ranges.)
     *
     * @param   string  $ip  Address.
     *
     * @return  boolean
     *
     * @since   0.1.0
     */
    public static function isCloudflare(string $ip): bool
    {
        foreach (self::CLOUDFLARE_RANGES as $range) {
            if (self::matches($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Private, loopback or otherwise reserved address?
     *
     * @param   string  $ip  Address.
     *
     * @return  boolean
     *
     * @since   0.1.0
     */
    public static function isPrivate(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false
            && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    /**
     * Cloudflare edge ranges, IPv4 and IPv6.
     *
     * @var    string[]
     * @since  0.1.0
     */
    private const CLOUDFLARE_RANGES = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
        '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
        '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
        '2a06:98c0::/29', '2c0f:f248::/32',
    ];

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
