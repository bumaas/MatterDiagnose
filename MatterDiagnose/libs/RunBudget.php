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

    /**
     * Budget für einen Lauf: Nur wer pingt, braucht die Reserve für den Erreichbarkeitstest.
     * Der Wächterlauf pingt nie (schlafende Geräte bleiben in Ruhe) — mit Reserve verbot
     * phaseAllowed dort Schritte, für die reichlich Zeit war (build 66, nuc 25.09.2026).
     */
    public static function forRun(float $total, float $pingReserve, float $start, bool $pings): self
    {
        return new self($total, $pings ? $pingReserve : 0.0, $start);
    }

    /**
     * Darf eine Runde der Nachfrage vor „nicht mehr erreichbar" noch laufen (build 66)?
     * Die erste Runde darf die Reserve anbrechen: Sie kostet höchstens BUDGET_DIRECT, und
     * ohne sie stünde ein rotes Urteil ohne Gegenprobe da — am nuc lief die Nachfrage so
     * seit build 63 nie (16,7 s von 24 s, „kein Budget"). Weitere Runden lassen die Reserve
     * unangetastet; über das Gesamtbudget geht keine.
     */
    public function judgementAllowed(float $now, float $cost, bool $firstRound): bool
    {
        return $firstRound ? $cost <= $this->remaining($now) : $this->phaseAllowed($now, $cost);
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
