<?php

declare(strict_types=1);

/**
 * Das Zeitbudget eines Diagnoselaufs — und die Zusage, dass für den Erreichbarkeitstest
 * am Ende etwas übrig bleibt.
 *
 * Anlass (Forum t/144417, 18.09.2026): Auf Loerdys SymBox mit 21 Matter-Geräten kostet
 * allein das Lesen des Matter-Konfigurators rund 6 Sekunden. Zusammen mit den Nachfrage-,
 * Direkt- und TXT-Runden war das Budget aufgebraucht, bevor der erste Ping lief — der
 * Bericht meldete bei jedem Lauf „Thread-Netzwerk konnte nicht getestet werden".
 *
 * Deshalb fragt jeder optionale Erhebungsschritt vorher, ob seine Kosten noch in das
 * Budget passen, ohne die Reserve anzutasten. Lieber eine Nachfragerunde weniger als ein
 * Lauf ohne Urteil über den Weg ins Thread-Netz — das ist die Kernfrage des Moduls.
 */
class RunBudget
{
    private float $total;
    private float $reserve;
    private float $start;

    public function __construct(float $total, float $reserve, float $start)
    {
        $this->total   = $total;
        $this->reserve = $reserve;
        $this->start   = $start;
    }

    /** Vergangene Zeit seit dem Start des Laufs (nie negativ). */
    public function elapsed(float $now): float
    {
        return max(0.0, $now - $this->start);
    }

    /** Was vom Gesamtbudget übrig ist (nie negativ). */
    public function remaining(float $now): float
    {
        return max(0.0, $this->total - $this->elapsed($now));
    }

    /**
     * Darf ein optionaler Schritt mit diesen Kosten noch laufen? Nur, wenn danach die
     * Reserve für den Erreichbarkeitstest unangetastet bliebe.
     */
    public function phaseAllowed(float $now, float $cost): bool
    {
        return $cost + $this->reserve <= $this->remaining($now);
    }
}
