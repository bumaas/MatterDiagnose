<?php

declare(strict_types=1);

require_once __DIR__ . '/DiagnosisEngine.php';

/**
 * Die Befunde eines Laufs als kurzer Klartext — für die Variable „Befunde",
 * MATD_RunSelfTest und MATD_RunDiagnosis (MCP-Regeln 5 und 9). Der HTML-Bericht bleibt
 * die ausführliche Fassung mit Geräteliste.
 *
 * Eine Zeile je Befund mit Handlungsbedarf (Blocker zuerst), die Abhilfe in derselben
 * Zeile; Befunde ohne Handlungsbedarf nur als Zahl. Die Texte kommen übersetzt herein.
 */
final class FindingSummary
{
    /**
     * @param array<int, array{severity: string, title: string, advice: string, devices?: string}> $findings
     *        devices: die betroffenen Geräte, wenn der Befund sie nennt — sonst stünden sie nur im
     *        langen Detailtext des HTML-Berichts
     * @param array{blocker: string, notice: string, ok: string, allOk: string, advice: string, devices?: string} $labels
     *        ok enthält %d für die Zahl der Befunde ohne Handlungsbedarf
     */
    public static function plainText(array $findings, string $header, array $labels): string
    {
        $lines = [$header];
        $okCount = 0;
        foreach ([DiagnosisEngine::SEVERITY_BLOCKER, DiagnosisEngine::SEVERITY_NOTICE] as $severity) {
            foreach ($findings as $finding) {
                if ($finding['severity'] !== $severity) {
                    continue;
                }
                $line    = $labels[$severity] . ': ' . self::oneLine($finding['title']);
                $devices = self::oneLine($finding['devices'] ?? '');
                if ($devices !== '' && isset($labels['devices'])) {
                    $line .= ' — ' . $labels['devices'] . ': ' . $devices;
                }
                $advice = self::oneLine($finding['advice']);
                if ($advice !== '') {
                    $line .= ' — ' . $labels['advice'] . ': ' . $advice;
                }
                $lines[] = $line;
            }
        }
        foreach ($findings as $finding) {
            if ($finding['severity'] === DiagnosisEngine::SEVERITY_OK) {
                $okCount++;
            }
        }
        if (count($lines) === 1) {
            $lines[] = $labels['allOk'];
        }
        if ($okCount > 0) {
            $lines[] = sprintf($labels['ok'], $okCount);
        }

        return implode("\n", $lines);
    }

    private static function oneLine(string $text): string
    {
        return trim((string)preg_replace('/\s*\R\s*/u', ' ', $text));
    }
}
