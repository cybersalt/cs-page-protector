<?php

/**
 * @package    com_cspageprotector
 * @copyright  Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license    GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Cybersalt\Component\Cspageprotector\Administrator\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Factory;

/**
 * Verifies that a request claiming to be a major search-engine crawler really
 * comes from that search engine, using forward-confirmed reverse DNS — the
 * method Google, Bing, Apple, Yandex and Baidu document for exactly this job.
 *
 * Anyone can put "Googlebot" in their User-Agent string; only Google controls
 * the reverse DNS for Google's crawler IPs. So: reverse-resolve the IP, check
 * the hostname is under the engine's domain, then forward-resolve that hostname
 * and make sure it maps back to the same IP.
 *
 * DNS is only touched when the User-Agent claims to be one of these crawlers,
 * so ordinary visitors pay nothing. Results are cached per IP for a day.
 *
 * AI training / scraping crawlers (GPTBot, ClaudeBot, CCBot, Bytespider, ...)
 * are deliberately NOT on this list — they are exactly what the extension is
 * meant to slow down.
 *
 * @since  0.1.0
 */
final class BotVerifier
{
    /**
     * User-Agent pattern => hostname suffixes the reverse DNS must end with.
     *
     * @var    array<string, string[]>
     * @since  0.1.0
     */
    private const CRAWLERS = [
        '/Googlebot|Google-InspectionTool|AdsBot-Google|Storebot-Google|Mediapartners-Google/i' => ['.googlebot.com', '.google.com'],
        '/bingbot|adidxbot|BingPreview/i'                                                      => ['.search.msn.com'],
        '/Applebot/i'                                                                          => ['.applebot.apple.com'],
        '/YandexBot|YandexMobileBot/i'                                                         => ['.yandex.ru', '.yandex.net', '.yandex.com'],
        '/Baiduspider/i'                                                                       => ['.baidu.com', '.baidu.jp'],
        '/Slurp/i'                                                                             => ['.crawl.yahoo.net'],
    ];

    /**
     * Does the User-Agent claim to be one of the supported crawlers?
     *
     * @param   string  $userAgent  The request User-Agent.
     *
     * @return  string[]  Allowed hostname suffixes, or [] when it's not a claimed crawler.
     *
     * @since   0.1.0
     */
    public static function claimedSuffixes(string $userAgent): array
    {
        foreach (self::CRAWLERS as $pattern => $suffixes) {
            if (preg_match($pattern, $userAgent)) {
                return $suffixes;
            }
        }

        return [];
    }

    /**
     * Is this request a genuine search-engine crawler?
     *
     * @param   string  $ip         Client IP.
     * @param   string  $userAgent  Client User-Agent.
     *
     * @return  boolean
     *
     * @since   0.1.0
     */
    public static function isVerifiedCrawler(string $ip, string $userAgent): bool
    {
        $suffixes = self::claimedSuffixes($userAgent);

        if ($suffixes === [] || $ip === '') {
            return false;
        }

        $cacheId = 'bot_' . hash('sha256', $ip . '|' . implode(',', $suffixes));
        $cache   = self::getCache();

        if ($cache !== null) {
            $cached = $cache->get($cacheId);

            if ($cached === '1' || $cached === '0') {
                return $cached === '1';
            }
        }

        $verified = self::forwardConfirmedReverseDns($ip, $suffixes);

        try {
            $cache?->store($verified ? '1' : '0', $cacheId);
        } catch (\Throwable $e) {
            // A cache write failure only costs us a repeat DNS lookup next time.
        }

        return $verified;
    }

    /**
     * Reverse-resolve, check the domain, then forward-resolve and compare.
     *
     * @param   string    $ip        Client IP.
     * @param   string[]  $suffixes  Allowed hostname suffixes.
     *
     * @return  boolean
     *
     * @since   0.1.0
     */
    private static function forwardConfirmedReverseDns(string $ip, array $suffixes): bool
    {
        $host = @gethostbyaddr($ip);

        if (!\is_string($host) || $host === '' || $host === $ip) {
            return false;
        }

        $host = strtolower(rtrim($host, '.'));

        $domainOk = false;

        foreach ($suffixes as $suffix) {
            if (str_ends_with($host, $suffix)) {
                $domainOk = true;
                break;
            }
        }

        if (!$domainOk) {
            return false;
        }

        $resolved = [];

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
                if (!empty($record['ipv6'])) {
                    $resolved[] = $record['ipv6'];
                }
            }
        } else {
            $resolved = @gethostbynamel($host) ?: [];
        }

        $ipBin = inet_pton($ip);

        foreach ($resolved as $candidate) {
            $candidateBin = @inet_pton($candidate);

            if ($candidateBin !== false && hash_equals($candidateBin, (string) $ipBin)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A day-long output cache that works even when the site has global caching off.
     *
     * @return  \Joomla\CMS\Cache\Controller\OutputController|null
     *
     * @since   0.1.0
     */
    private static function getCache()
    {
        try {
            return Factory::getContainer()
                ->get(CacheControllerFactoryInterface::class)
                ->createCacheController('output', [
                    'defaultgroup' => 'com_cspageprotector',
                    'caching'      => true,
                    'lifetime'     => 1440,
                ]);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
