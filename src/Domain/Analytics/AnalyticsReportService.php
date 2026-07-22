<?php

namespace Spoome\Domain\Analytics;

use PDO;

/**
 * Aggregazione on-demand degli eventi d'uso per l'area admin (M4). Le letture propagano gli errori
 * (l'admin vuole vederli), a differenza della scrittura fail-safe di {@see AnalyticsService}.
 * Range in soli interi (giorni): nessun input testuale raggiunge l'SQL.
 */
final class AnalyticsReportService
{
    /** Intervalli selezionabili (giorni), coerenti con il modulo statistiche esistente. */
    public const RANGES = [7, 30, 90];

    private AnalyticsRepository $repo;

    public function __construct(?PDO $pdo = null)
    {
        $this->repo = new AnalyticsRepository($pdo);
    }

    /**
     * Payload completo per la pagina admin: funnel per tipo, serie giornaliere (zero-filled) dei due
     * eventi attivi e conversione search→profile_open sulla finestra.
     *
     * @return array<string,mixed>
     */
    public function overview(int $days): array
    {
        $days = in_array($days, self::RANGES, true) ? $days : 30;

        $conv = $this->repo->conversion(
            AnalyticsService::EVENT_SEARCH,
            AnalyticsService::EVENT_PROFILE_OPEN,
            $days
        );
        $rate = $conv['from'] > 0 ? round($conv['to'] / $conv['from'] * 100, 1) : null;

        // Asse temporale continuo condiviso (stesse etichette per tutte le serie), formato compatibile
        // con Chart::line del modulo statistiche esistente: labels + array di valori per serie.
        $labels = $this->dayLabels($days);
        $search = $this->repo->dailySeries(AnalyticsService::EVENT_SEARCH, $days);
        $opens  = $this->repo->dailySeries(AnalyticsService::EVENT_PROFILE_OPEN, $days);

        return [
            'range'  => $days,
            'ranges' => self::RANGES,
            'funnel' => $this->repo->funnelCounts($days),
            'series' => [
                'labels'       => $labels,
                'search'       => $this->alignTo($labels, $search),
                'profile_open' => $this->alignTo($labels, $opens),
            ],
            'conversion' => [
                'from'  => $conv['from'],   // attori distinti che hanno cercato
                'to'    => $conv['to'],     // attori distinti che hanno aperto un profilo
                'rate'  => $rate,           // % (o null se nessun cercatore)
            ],
        ];
    }

    /**
     * Etichette dei $days giorni consecutivi terminanti oggi ('Y-m-d').
     * @return array<int,string>
     */
    private function dayLabels(int $days): array
    {
        $labels = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $labels[] = date('Y-m-d', strtotime("-{$i} day"));
        }
        return $labels;
    }

    /**
     * Allinea una mappa 'Y-m-d'=>count all'asse $labels, riempiendo di zero i giorni assenti.
     * @param array<int,string>  $labels
     * @param array<string,int>  $sparse
     * @return array<int,int>
     */
    private function alignTo(array $labels, array $sparse): array
    {
        return array_map(static fn (string $d): int => (int) ($sparse[$d] ?? 0), $labels);
    }
}
