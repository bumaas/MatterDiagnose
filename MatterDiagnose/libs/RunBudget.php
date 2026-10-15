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
    private float $pingFloor;

    /**
     * @param float $pingFloor was dem Ping mindestens bleiben muss, auch wenn die erste
     *                         Nachfragerunde die Reserve anbricht (build 81)
     */
    public function __construct(float $total, float $reserve, float $start, float $pingFloor = 0.0)
    {
        $this->total     = $total;
        $this->reserve   = $reserve;
        $this->start     = $start;
        $this->pingFloor = $pingFloor;
    }

    /**
     * Budget für einen Lauf: Nur wer pingt, braucht die Reserve für den Erreichbarkeitstest.
     * Der Wächterlauf pingt nie (schlafende Geräte bleiben in Ruhe) — mit Reserve verbot
     * phaseAllowed dort Schritte, für die reichlich Zeit war (build 66, nuc 25.09.2026).
     * Braucht der Ping der Plattform mehr als die Reserve (macOS: ping6 wartet ohne Antwort
     * fest 10 s, build 90), wachsen Reserve und Gesamtbudget um die Differenz, das Budget
     * aber höchstens bis `$ceiling`. Was darüber hinausginge, zahlt die Erhebung davor: Nach
     * dem Budget laufen noch Bericht, Variablen und Formular, und mit 29,5 s unter macOS ging
     * ein Lauf über die zugesagten 30 s (Code-Review 15.10.2026). Windows und Linux bleiben,
     * wie sie waren.
     */
    public static function forRun(float $total, float $pingReserve, float $start, bool $pings, float $pingFloor = 0.0, float $ceiling = PHP_FLOAT_MAX): self
    {
        if (!$pings) {
            return new self($total, 0.0, $start);
        }
        $extra = max(0.0, $pingFloor - $pingReserve);

        return new self(min($total + $extra, max($total, $ceiling)), $pingReserve + $extra, $start, $pingFloor);
    }

    /** Das Gesamtbudget dieses Laufs in Sekunden (fürs Debug, unter macOS größer als die Vorgabe). */
    public function total(): float
    {
        return $this->total;
    }

    /** Die Reserve für den Erreichbarkeitstest in diesem Lauf (0 im Wächterlauf). */
    public function reserve(): float
    {
        return $this->reserve;
    }

    /**
     * Darf eine Runde der Nachfrage vor „nicht mehr erreichbar" noch laufen (build 66)?
     * Die erste Runde darf die Reserve anbrechen: Sie kostet höchstens BUDGET_DIRECT, und
     * ohne sie stünde ein rotes Urteil ohne Gegenprobe da — am nuc lief die Nachfrage so
     * seit build 63 nie (16,7 s von 24 s, „kein Budget"). Weitere Runden lassen die Reserve
     * unangetastet; über das Gesamtbudget geht keine.
     * Anbrechen, nicht aufzehren (build 81): Dem Ping bleibt seine Mindestzeit. Am nuc kam
     * der Lauf nach der Nachfrage an zwei Shellys mit 0,7 s Luft am Test an; ein etwas
     * langsamerer Lauf pingte gar nicht.
     */
    public function judgementAllowed(float $now, float $cost, bool $firstRound): bool
    {
        return $firstRound ? $cost + $this->pingFloor <= $this->remaining($now) : $this->phaseAllowed($now, $cost);
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
