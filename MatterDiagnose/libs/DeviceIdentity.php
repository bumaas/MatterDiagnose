<?php

declare(strict_types=1);

require_once __DIR__ . '/MdnsCodec.php';

/**
 * Wer steckt hinter einem Gerät, das nur als Nummer erscheint?
 *
 * Die Matter-Annonce nennt weder Hersteller noch Modell. Zwei Quellen helfen trotzdem
 * (beide am 18.09.2026 im LAN belegt, Fixtures tests/fixtures/mdns/services_*.bin):
 *
 *  1. Andere Dienste desselben Geräts: Shelly annonciert `_shelly._tcp` (TXT app/gen), die
 *     Hue Bridge `_hue._tcp` (modelid), Cast-Geräte `_googlecast._tcp` (md), HomeKit-Geräte
 *     `_hap._tcp` (md), ESPHome `_esphomelib._tcp` (platform/board). Verbunden wird über die
 *     Adressen — der SRV-Host ist oft ein anderer als in der Matter-Annonce
 *     (`ShellyPlugSG3-E4B063E529D0.local` gegen `E4B063E529D0.local`).
 *  2. Der Hostname eines LAN-/WLAN-Geräts ist meist seine MAC-Adresse; die ersten sechs
 *     Hex-Stellen (OUI) nennen den Hersteller (Auszug der IEEE-Liste in oui.php).
 *
 * Thread-Geräte tragen eine zufällige 16-stellige Kennung — dort hilft keines von beiden.
 *
 * Reine Funktionen ohne Netzzugriff; die Antworten kommen aus MdnsBrowser.
 */
class DeviceIdentity
{
    /** Dienste, die in der ersten mDNS-Runde mit abgefragt werden */
    public const SERVICES = [
        '_shelly._tcp.local',
        '_hap._tcp.local',
        '_googlecast._tcp.local',
        '_hue._tcp.local',
        '_esphomelib._tcp.local',
    ];

    /** @var array<string, string>|null OUI-Präfix => Hersteller (lazy aus oui.php) */
    private static ?array $oui = null;

    /**
     * Identitäten aus den mDNS-Antworten: je Dienstinstanz Host, Adressen, Hersteller, Modell.
     *
     * @param array<int, array{from: string, message: array{records: array<int, array<string, mixed>>}}> $responses
     * @return array<int, array{service: string, instance: string, host: string, addresses: array<int, string>, vendor: string, model: string}>
     */
    public static function fromResponses(array $responses): array
    {
        $ptr       = []; // dienst => [instanz (klein) => Instanzname]
        $srv       = []; // instanz (klein) => host
        $txt       = []; // instanz (klein) => TXT
        $addresses = []; // host (klein) => [adresse, ...]
        foreach ($responses as $response) {
            foreach ($response['message']['records'] as $record) {
                $name = strtolower((string)$record['name']);
                switch ($record['type']) {
                    case MdnsCodec::TYPE_PTR:
                        if (in_array($name, self::SERVICES, true)) {
                            $ptr[$name][strtolower((string)$record['target'])] ??= (string)$record['target'];
                        }
                        break;
                    case MdnsCodec::TYPE_SRV:
                        $srv[$name] ??= (string)$record['target'];
                        break;
                    case MdnsCodec::TYPE_TXT:
                        $txt[$name] ??= $record['txt'];
                        break;
                    case MdnsCodec::TYPE_A:
                    case MdnsCodec::TYPE_AAAA:
                        $addresses[$name][] = (string)$record['address'];
                        break;
                }
            }
        }

        $identities = [];
        foreach ($ptr as $service => $instances) {
            foreach ($instances as $key => $instance) {
                $host      = $srv[$key] ?? '';
                $described = self::describe($service, $txt[$key] ?? [], $instance);
                if ($described['vendor'] === '' && $described['model'] === '') {
                    continue;
                }
                $identities[] = [
                    'service'   => $service,
                    'instance'  => $instance,
                    'host'      => $host,
                    'addresses' => array_values(array_unique($addresses[strtolower($host)] ?? [])),
                ] + $described;
            }
        }

        return $identities;
    }

    /**
     * Hersteller und Modell aus dem TXT eines Dienstes — je Dienst andere Schlüssel.
     *
     * @param array<string, string> $txt
     * @return array{vendor: string, model: string}
     */
    public static function describe(string $service, array $txt, string $instance): array
    {
        $upper = [];
        foreach ($txt as $key => $value) {
            $upper[strtoupper((string)$key)] = trim((string)$value);
        }
        $get = static fn(string $key): string => $upper[strtoupper($key)] ?? '';

        switch (strtolower($service)) {
            case '_shelly._tcp.local':
                $model = $get('app');
                if ($get('gen') !== '') {
                    $model = trim($model . ' Gen ' . $get('gen'));
                }

                return ['vendor' => 'Shelly', 'model' => $model];
            case '_hue._tcp.local':
                return ['vendor' => 'Philips Hue', 'model' => trim('Bridge ' . $get('modelid'))];
            case '_googlecast._tcp.local':
            case '_hap._tcp.local':
                return ['vendor' => '', 'model' => $get('md')];
            case '_esphomelib._tcp.local':
                return ['vendor' => 'ESPHome', 'model' => trim($get('platform') . ' ' . $get('board'))];
        }

        return ['vendor' => '', 'model' => ''];
    }

    /**
     * Hersteller aus dem Hostnamen, wenn der eine MAC-Adresse ist ("E4B063E529D0.local").
     * Zufällige Kennungen (Thread, 16-stellig) und lokal verwaltete MACs liefern null.
     */
    public static function ouiVendor(string $host): ?string
    {
        $label = strtoupper(self::hostLabel($host));
        if (preg_match('/^[0-9A-F]{12}$/', $label) !== 1) {
            return null;
        }
        // Bit 1 des ersten Bytes = lokal verwaltet, Bit 0 = Multicast: beides keine Herstellerkennung
        if ((hexdec(substr($label, 0, 2)) & 0x03) !== 0) {
            return null;
        }
        self::$oui ??= require __DIR__ . '/oui.php';

        return self::$oui[substr($label, 0, 6)] ?? null;
    }

    /**
     * Ordnet einem Matter-Gerät (Host und Adressen seiner Annonce) eine Identität zu:
     * zuerst ein anderer Dienst desselben Geräts (gleiche Adresse, gleicher Host oder
     * dieselbe MAC im Hostnamen), sonst der Hersteller aus der MAC-Adresse.
     *
     * @param array<int, string> $addresses
     * @param array<int, array{host: string, addresses: array<int, string>, vendor: string, model: string}> $identities
     * @return array{vendor: string, model: string}
     */
    public static function identify(string $host, array $addresses, array $identities): array
    {
        $label     = strtolower(self::hostLabel($host));
        $addresses = array_map('strtolower', $addresses);
        $oui       = self::ouiVendor($host) ?? '';

        foreach ($identities as $identity) {
            $identityLabel = strtolower(self::hostLabel($identity['host']));
            $sameHost      = $label !== '' && $identityLabel === $label;
            $sameMac       = $label !== '' && strlen($label) === 12 && str_ends_with($identityLabel, $label);
            $sameAddress   = array_intersect($addresses, array_map('strtolower', $identity['addresses'])) !== [];
            if ($sameHost || $sameMac || $sameAddress) {
                return [
                    'vendor' => $identity['vendor'] !== '' ? $identity['vendor'] : $oui,
                    'model'  => $identity['model'],
                ];
            }
        }

        return ['vendor' => $oui, 'model' => ''];
    }

    /** "ShellyPlugSG3-E4B063E529D0.local." → "ShellyPlugSG3-E4B063E529D0" */
    private static function hostLabel(string $host): string
    {
        return (string)preg_replace('/\.local\.?$/i', '', trim($host));
    }
}
