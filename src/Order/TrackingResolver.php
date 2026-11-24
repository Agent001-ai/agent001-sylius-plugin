<?php

declare(strict_types=1);

namespace Agent001\SyliusAiAssistantPlugin\Order;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ShipmentInterface;

final class TrackingResolver implements TrackingResolverInterface
{
    private const MAX_ENTRIES = 5;

    private const URL_TEMPLATES = [
        'inpost' => 'https://inpost.pl/pl/pomoc/znajdz-przesylke?parcel=%s',
        'dpd' => 'https://tracktrace.dpd.com.pl/parcelDetails?p1=%s',
        'dhl' => 'https://www.dhl.com/pl-pl/home/sledzenie-przesylek.html?tracking-id=%s',
        'pocztapolska' => 'https://emonitoring.poczta-polska.pl/?numer=%s',
        'gls' => 'https://gls-group.com/PL/pl/sledzenie-paczek?match=%s',
        'ups' => 'https://www.ups.com/track?loc=pl_PL&tracknum=%s',
        'fedex' => 'https://www.fedex.com/fedextrack/?trknbr=%s',
    ];

    private const CARRIER_HOSTS = [
        'inpost.pl' => 'inpost',
        'dpd.com.pl' => 'dpd',
        'dpd.pl' => 'dpd',
        'dhl.com' => 'dhl',
        'dhl.pl' => 'dhl',
        'dhlparcel.pl' => 'dhl',
        'poczta-polska.pl' => 'pocztapolska',
        'gls-group.com' => 'gls',
        'gls-group.eu' => 'gls',
        'gls-pl.com' => 'gls',
        'ups.com' => 'ups',
        'fedex.com' => 'fedex',
        'orlenpaczka.pl' => 'orlen',
    ];

    private const CARRIER_KEYWORDS = [
        'inpost' => '/inpost|paczkomat/i',
        'dpd' => '/\bdpd\b/i',
        'dhl' => '/\bdhl\b/i',
        'pocztapolska' => '/poczta\s+polska|pocztex|envelo/i',
        'gls' => '/\bgls\b/i',
        'ups' => '/\bups\b/i',
        'fedex' => '/fedex/i',
        'orlen' => '/orlen/i',
    ];

    /**
     * @return list<array{number: string, carrier: string, url: string}>
     */
    public function resolve(OrderInterface $order): array
    {
        $entries = [];

        /** @var ShipmentInterface $shipment */
        foreach ($order->getShipments() as $shipment) {
            $tracking = trim((string) $shipment->getTracking());
            if ('' === $tracking) {
                continue;
            }

            $methodName = (string) $shipment->getMethod()?->getName();
            $entries[] = $this->entry($tracking, $this->carrierFromText($methodName));
        }

        return $this->unique($entries);
    }

    /**
     * @return array{number: string, carrier: string, url: string}
     */
    private function entry(string $tracking, string $fallbackCarrier): array
    {
        if (str_starts_with($tracking, 'https://')) {
            $fromUrl = $this->fromUrl($tracking);
            if (null !== $fromUrl) {
                return $fromUrl;
            }
        }

        $url = isset(self::URL_TEMPLATES[$fallbackCarrier])
            ? sprintf(self::URL_TEMPLATES[$fallbackCarrier], rawurlencode($tracking))
            : '';

        return ['number' => $tracking, 'carrier' => $fallbackCarrier, 'url' => $url];
    }

    /**
     * @return array{number: string, carrier: string, url: string}|null
     */
    private function fromUrl(string $url): ?array
    {
        $host = strtolower((string) parse_url($url, \PHP_URL_HOST));
        $carrier = '';
        foreach (self::CARRIER_HOSTS as $domain => $slug) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                $carrier = $slug;

                break;
            }
        }

        if ('' === $carrier) {
            return null;
        }

        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
        $params = ['parcel', 'number', 'numer', 'p1', 'tracking-id', 'tracknum', 'match', 'trknbr', 'tracking_number'];
        foreach ($params as $param) {
            $value = $query[$param] ?? null;
            if (is_string($value) && 1 === preg_match('/\A[A-Za-z0-9]{6,40}\z/', $value)) {
                return ['number' => $value, 'carrier' => $carrier, 'url' => $url];
            }
        }

        return null;
    }

    private function carrierFromText(string $text): string
    {
        foreach (self::CARRIER_KEYWORDS as $carrier => $pattern) {
            if (1 === preg_match($pattern, $text)) {
                return $carrier;
            }
        }

        return '';
    }

    /**
     * @param list<array{number: string, carrier: string, url: string}> $entries
     *
     * @return list<array{number: string, carrier: string, url: string}>
     */
    private function unique(array $entries): array
    {
        $byNumber = [];
        foreach ($entries as $entry) {
            $byNumber[$entry['number']] ??= $entry;
        }

        return array_slice(array_values($byNumber), 0, self::MAX_ENTRIES);
    }
}
