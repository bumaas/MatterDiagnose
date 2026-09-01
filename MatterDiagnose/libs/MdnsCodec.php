<?php

declare(strict_types=1);

/**
 * Kodieren und Dekodieren von mDNS-Nachrichten (RFC 6762 / RFC 1035, Teilmenge).
 *
 * Reine Funktionen ohne Netzwerkzugriff — vollständig per Unit-Test abgedeckt.
 * Unterstützte Record-Typen: A, AAAA, PTR, TXT, SRV; alles andere wird als
 * Roh-Record mit unverändertem rdata durchgereicht.
 */
class MdnsCodec
{
    public const TYPE_A    = 1;
    public const TYPE_PTR  = 12;
    public const TYPE_TXT  = 16;
    public const TYPE_AAAA = 28;
    public const TYPE_SRV  = 33;

    private const CLASS_IN            = 0x0001;
    private const UNICAST_RESPONSE    = 0x8000; // QU-Bit in der Query-Klasse
    private const COMPRESSION_POINTER = 0xC0;

    /**
     * Baut eine mDNS-Query.
     *
     * @param array<int, array{name: string, type: int, unicast?: bool}> $questions
     */
    public static function encodeQuery(array $questions, int $id = 0): string
    {
        $msg = pack('nnnnnn', $id, 0, count($questions), 0, 0, 0);
        foreach ($questions as $q) {
            $class = self::CLASS_IN | (($q['unicast'] ?? true) ? self::UNICAST_RESPONSE : 0);
            $msg   .= self::encodeName($q['name']) . pack('nn', $q['type'], $class);
        }

        return $msg;
    }

    /**
     * Zerlegt eine mDNS-Nachricht in Header, Fragen und Records.
     *
     * Wirft bei strukturell kaputten Paketen eine InvalidArgumentException;
     * unbekannte Record-Typen sind kein Fehler.
     *
     * @return array{
     *     id: int, flags: int, isResponse: bool,
     *     questions: array<int, array{name: string, type: int, class: int}>,
     *     records: array<int, array<string, mixed>>
     * }
     */
    public static function decodeMessage(string $raw): array
    {
        if (strlen($raw) < 12) {
            throw new InvalidArgumentException('Nachricht kürzer als DNS-Header');
        }
        /** @var array{id: int, flags: int, qd: int, an: int, ns: int, ar: int} $h */
        $h      = unpack('nid/nflags/nqd/nan/nns/nar', substr($raw, 0, 12));
        $offset = 12;

        $questions = [];
        for ($i = 0; $i < $h['qd']; $i++) {
            $name = self::decodeName($raw, $offset);
            if ($offset + 4 > strlen($raw)) {
                throw new InvalidArgumentException('Frage #' . $i . ' abgeschnitten');
            }
            /** @var array{type: int, class: int} $q */
            $q           = unpack('ntype/nclass', substr($raw, $offset, 4));
            $offset      += 4;
            $questions[] = ['name' => $name, 'type' => $q['type'], 'class' => $q['class']];
        }

        $records = [];
        $total   = $h['an'] + $h['ns'] + $h['ar'];
        for ($i = 0; $i < $total; $i++) {
            $records[] = self::decodeRecord($raw, $offset);
        }

        return [
            'id'         => $h['id'],
            'flags'      => $h['flags'],
            'isResponse' => (bool)($h['flags'] & 0x8000),
            'questions'  => $questions,
            'records'    => $records,
        ];
    }

    public static function encodeName(string $name): string
    {
        $out = '';
        foreach (explode('.', trim($name, '.')) as $label) {
            $len = strlen($label);
            if ($len === 0 || $len > 63) {
                throw new InvalidArgumentException('Ungültiges Label in Name: ' . $name);
            }
            $out .= chr($len) . $label;
        }

        return $out . chr(0);
    }

    /**
     * Liest einen (möglicherweise komprimierten) Namen; $offset wird auf das
     * Ende des Namens im ursprünglichen Datenstrom gesetzt.
     */
    public static function decodeName(string $raw, int &$offset): string
    {
        $labels  = [];
        $pos     = $offset;
        $jumped  = false;
        $guard   = 0;
        $rawLen  = strlen($raw);

        while (true) {
            if (++$guard > 128) {
                throw new InvalidArgumentException('Kompressionsschleife im Namen');
            }
            if ($pos >= $rawLen) {
                throw new InvalidArgumentException('Name reicht über das Paketende hinaus');
            }
            $len = ord($raw[$pos]);
            if ($len === 0) {
                $pos++;
                break;
            }
            if (($len & self::COMPRESSION_POINTER) === self::COMPRESSION_POINTER) {
                if ($pos + 1 >= $rawLen) {
                    throw new InvalidArgumentException('Kompressionszeiger abgeschnitten');
                }
                $target = (($len & 0x3F) << 8) | ord($raw[$pos + 1]);
                if (!$jumped) {
                    $offset = $pos + 2;
                    $jumped = true;
                }
                if ($target >= $pos) {
                    throw new InvalidArgumentException('Kompressionszeiger zeigt vorwärts');
                }
                $pos = $target;
                continue;
            }
            if (($len & self::COMPRESSION_POINTER) !== 0) {
                throw new InvalidArgumentException('Unbekannter Label-Typ 0x' . dechex($len));
            }
            if ($pos + 1 + $len > $rawLen) {
                throw new InvalidArgumentException('Label reicht über das Paketende hinaus');
            }
            $labels[] = substr($raw, $pos + 1, $len);
            $pos      += 1 + $len;
        }

        if (!$jumped) {
            $offset = $pos;
        }

        return implode('.', $labels);
    }

    /** @return array<string, mixed> */
    private static function decodeRecord(string $raw, int &$offset): array
    {
        $name = self::decodeName($raw, $offset);
        if ($offset + 10 > strlen($raw)) {
            throw new InvalidArgumentException('Record-Kopf abgeschnitten (bei ' . $name . ')');
        }
        /** @var array{type: int, class: int, ttl: int, len: int} $rr */
        $rr     = unpack('ntype/nclass/Nttl/nlen', substr($raw, $offset, 10));
        $offset += 10;
        if ($offset + $rr['len'] > strlen($raw)) {
            throw new InvalidArgumentException('Record-Daten abgeschnitten (bei ' . $name . ')');
        }
        $rdataOffset = $offset;
        $offset      += $rr['len'];

        $record = [
            'name'  => $name,
            'type'  => $rr['type'],
            'class' => $rr['class'] & 0x7FFF, // Cache-Flush-Bit ausblenden
            'ttl'   => $rr['ttl'],
        ];

        switch ($rr['type']) {
            case self::TYPE_A:
                if ($rr['len'] !== 4) {
                    throw new InvalidArgumentException('A-Record mit Länge ' . $rr['len']);
                }
                $record['address'] = inet_ntop(substr($raw, $rdataOffset, 4));
                break;
            case self::TYPE_AAAA:
                if ($rr['len'] !== 16) {
                    throw new InvalidArgumentException('AAAA-Record mit Länge ' . $rr['len']);
                }
                $record['address'] = inet_ntop(substr($raw, $rdataOffset, 16));
                break;
            case self::TYPE_PTR:
                $ptrOffset        = $rdataOffset;
                $record['target'] = self::decodeName($raw, $ptrOffset);
                break;
            case self::TYPE_SRV:
                if ($rr['len'] < 6) {
                    throw new InvalidArgumentException('SRV-Record zu kurz');
                }
                /** @var array{prio: int, weight: int, port: int} $srv */
                $srv              = unpack('nprio/nweight/nport', substr($raw, $rdataOffset, 6));
                $srvOffset        = $rdataOffset + 6;
                $record['port']   = $srv['port'];
                $record['target'] = self::decodeName($raw, $srvOffset);
                break;
            case self::TYPE_TXT:
                $record['txt'] = self::decodeTxt(substr($raw, $rdataOffset, $rr['len']));
                break;
            default:
                $record['rdata'] = substr($raw, $rdataOffset, $rr['len']);
        }

        return $record;
    }

    /** @return array<string, string> Schlüssel/Wert-Paare; Einträge ohne '=' bekommen den Wert '' */
    private static function decodeTxt(string $rdata): array
    {
        $result = [];
        $pos    = 0;
        $len    = strlen($rdata);
        while ($pos < $len) {
            $entryLen = ord($rdata[$pos]);
            $entry    = substr($rdata, $pos + 1, $entryLen);
            $pos      += 1 + $entryLen;
            if ($entry === '') {
                continue;
            }
            $eq = strpos($entry, '=');
            if ($eq === false) {
                $result[$entry] = '';
            } else {
                $result[substr($entry, 0, $eq)] = substr($entry, $eq + 1);
            }
        }

        return $result;
    }
}
