<?php

namespace Spoome\Core;

/**
 * Value object di paginazione. Centralizza — a comportamento invariato — l'idioma ripetuto in
 * controller e repository: parsing di pagina/per-page dalla richiesta, clamp, calcolo dell'offset
 * SQL e costruzione del blocco `meta` dell'envelope API ({page, per_page, total, pages}).
 *
 * Due ingressi:
 *  - fromRequest(): default dell'API (per_page clampato in [1,$max], pagina da `pagina`/`page`);
 *  - of(): valori già risolti (repository/service che ricevono page/perPage come argomenti).
 */
final class Pagination
{
    private function __construct(
        public readonly int $page,
        public readonly int $perPage,
    ) {
    }

    /** Costruisce da valori già risolti; clampa a un minimo di 1 (page e perPage). */
    public static function of(int $page, int $perPage): self
    {
        return new self(max(1, $page), max(1, $perPage));
    }

    /**
     * Parsing dalla richiesta con i default dell'API: `per_page` clampato in [1,$max] (default $default),
     * pagina da `pagina` (fallback `page`), minimo 1. Identico all'idioma inline dei controller API.
     */
    public static function fromRequest(Request $request, int $default, int $max): self
    {
        $perPage = max(1, min($max, (int) $request->input('per_page', $default)));
        $page    = max(1, (int) $request->input('pagina', $request->input('page', 1)));
        return new self($page, $perPage);
    }

    /** Offset SQL (0-based). Con page>=1 è sempre >= 0. */
    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    /** Numero totale di pagine per un dato totale di elementi (>= 1). */
    public function pages(int $total): int
    {
        return (int) max(1, (int) ceil($total / $this->perPage));
    }

    /**
     * Blocco `meta` dell'envelope: { page, per_page, total, pages } + eventuali extra (es. `filters`).
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    public function meta(int $total, array $extra = []): array
    {
        return array_merge([
            'page'     => $this->page,
            'per_page' => $this->perPage,
            'total'    => $total,
            'pages'    => $this->pages($total),
        ], $extra);
    }
}
