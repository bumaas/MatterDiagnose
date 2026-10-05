<?php

declare(strict_types=1);

/**
 * Text, den fremde Geräte über das Netz liefern (mDNS-Instanznamen, TXT-Records,
 * Reverse-Namen des Routers). Er landet in Bericht, Änderungen und Selbsttest und damit
 * auch im Kontext eines KI-Assistenten, der die Anlage über MCP liest (MCP-Regel 17):
 * ohne Steuer-, Format- und Umbruchzeichen und in der Länge begrenzt.
 */
final class ForeignText
{
    /** Länger ist kein Geräte- oder Modellname, den ein Anwender braucht. */
    public const MAX_LENGTH = 40;

    public static function clean(string $text, int $maxLength = self::MAX_LENGTH): string
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            return '';
        }
        // \p{Cc} Steuerzeichen (auch Tab/Umbruch), \p{Cf} Formatzeichen (Richtungswechsel, Nullbreite)
        $text = (string)preg_replace('/[\p{Cc}\p{Cf}]/u', '', strtr($text, ["\t" => ' ', "\r" => ' ', "\n" => ' ']));
        $text = trim((string)preg_replace('/\s+/u', ' ', $text));
        if (mb_strlen($text) > $maxLength) {
            $text = rtrim(mb_substr($text, 0, $maxLength - 1)) . '…';
        }

        return $text;
    }
}
