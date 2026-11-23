<?php
/**
 * @package   Angeo_AeoAudit
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\AeoAudit\Service;

/**
 * Tells whether a host name points only at public internet addresses.
 *
 * Used for redirects that leave the host the audit asked for: the audit may
 * read the store itself wherever it runs (also on a private network), but a
 * redirect must not send it to some other machine on that network.
 *
 * @since 4.6.0
 */
class PublicAddress
{
    /**
     * True when the host resolves and EVERY address it resolves to is public.
     */
    public function hostIsPublic(string $host): bool
    {
        $host = trim($host, '[]');
        if ($host === '') {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return self::isPublic($host);
        }

        $addresses = [];
        foreach (['A' => DNS_A, 'AAAA' => DNS_AAAA] as $type => $flag) {
            // Magento's error handler turns the DNS warning into an exception.
            try {
                $records = dns_get_record($host, $flag) ?: [];
            } catch (\Throwable) {
                $records = [];
            }
            foreach ($records as $record) {
                $addresses[] = (string) ($record[$type === 'A' ? 'ip' : 'ipv6'] ?? '');
            }
        }
        $addresses = array_values(array_filter($addresses));
        if ($addresses === []) {
            return false;
        }
        foreach ($addresses as $address) {
            if (!self::isPublic($address)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Public unicast only: no private, loopback, link-local or reserved
     * range, no carrier-grade NAT, no IPv4 hidden in an IPv6 address.
     */
    public static function isPublic(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $long = ip2long($address);

            return $long !== false
                && ($long & 0xFFC00000) !== 0x64400000    // 100.64.0.0/10
                && ($long & 0xFFFE0000) !== 0xC6120000;   // 198.18.0.0/15
        }
        $bin = (string) inet_pton($address);

        return !(strlen($bin) === 16
            && (substr($bin, 0, 12) === str_repeat("\0", 10) . "\xff\xff"
                || substr($bin, 0, 12) === "\x00\x64\xff\x9b" . str_repeat("\0", 8)));
    }
}
