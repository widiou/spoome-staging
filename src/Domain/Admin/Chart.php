<?php

namespace Spoome\Domain\Admin;

/**
 * Generatore di grafici SVG inline (nessuna dipendenza JS/CDN: compatibile con la CSP chiusa).
 * Restituisce markup SVG responsive (viewBox + width:100% via CSS). Puro output di presentazione.
 */
final class Chart
{
    private const W = 720;
    private const H = 220;
    private const PAD_X = 8;
    private const PAD_TOP = 12;
    private const PAD_BOTTOM = 22;

    /**
     * Grafico a linee multi-serie su asse condiviso.
     * @param array<int,array{name:string,color:string,values:array<int,int|float>}> $series
     * @param array<int,string> $labels etichette asse X (date 'Y-m-d')
     */
    public static function line(array $series, array $labels): string
    {
        $n = count($labels);
        if ($n < 2 || $series === []) {
            return '<div class="admin-chart-empty">—</div>';
        }

        $max = 1;
        foreach ($series as $s) {
            foreach ($s['values'] as $v) {
                $max = max($max, (int) $v);
            }
        }

        $w = self::W;
        $h = self::H;
        $plotH = $h - self::PAD_TOP - self::PAD_BOTTOM;
        $plotW = $w - self::PAD_X * 2;
        $step  = $plotW / ($n - 1);

        $x = static fn (int $i): float => round(self::PAD_X + $i * $step, 1);
        $y = static fn (float $v) => round(self::PAD_TOP + $plotH - ($v / $max) * $plotH, 1);

        $svg = '<svg class="admin-chart-svg" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" role="img" aria-hidden="true">';

        // Gridlines orizzontali (4 tacche)
        for ($g = 0; $g <= 4; $g++) {
            $gy = round(self::PAD_TOP + $plotH * $g / 4, 1);
            $svg .= '<line x1="' . self::PAD_X . '" y1="' . $gy . '" x2="' . ($w - self::PAD_X) . '" y2="' . $gy . '" class="admin-chart-grid"/>';
        }

        foreach ($series as $k => $s) {
            $pts = [];
            foreach ($s['values'] as $i => $v) {
                $pts[] = $x((int) $i) . ',' . $y((float) $v);
            }
            $poly = implode(' ', $pts);
            $color = $s['color'];

            // Area tenue solo per la prima serie (protagonista).
            if ($k === 0) {
                $area = self::PAD_X . ',' . $y(0) . ' ' . $poly . ' ' . $x($n - 1) . ',' . $y(0);
                $svg .= '<polygon points="' . $area . '" fill="' . $color . '" opacity="0.10"/>';
            }
            $svg .= '<polyline points="' . $poly . '" fill="none" stroke="' . $color . '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/>';
        }

        $svg .= '</svg>';
        return $svg;
    }

    /**
     * Sparkline compatta per le card KPI.
     * @param array<int,int|float> $values
     */
    public static function spark(array $values, string $color): string
    {
        $n = count($values);
        if ($n < 2) {
            return '';
        }
        $max = 1;
        $min = 0;
        foreach ($values as $v) {
            $max = max($max, (int) $v);
        }
        $w = 120;
        $h = 34;
        $pad = 2;
        $step = ($w - $pad * 2) / ($n - 1);
        $pts = [];
        foreach ($values as $i => $v) {
            $px = round($pad + $i * $step, 1);
            $py = round($pad + ($h - $pad * 2) - (($v - $min) / max(1, $max - $min)) * ($h - $pad * 2), 1);
            $pts[] = $px . ',' . $py;
        }
        return '<svg class="admin-spark" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" aria-hidden="true">'
            . '<polyline points="' . implode(' ', $pts) . '" fill="none" stroke="' . $color . '" stroke-width="1.5" vector-effect="non-scaling-stroke"/></svg>';
    }
}
