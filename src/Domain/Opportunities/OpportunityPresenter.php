<?php

namespace Spoome\Domain\Opportunities;

use Spoome\Core\Config;

/**
 * Trasforma le righe DB arricchite di Opportunities/Applications nella forma JSON pubblica dell'API.
 * Unica sede della "shape" esposta (parità web↔API): se cambia un campo, cambia qui e vale ovunque.
 * Non espone campi interni (org_profile_id, created_by_user_id, applicant_profile_id).
 *
 * `state` è DERIVATO: 'closed' (chiusa manualmente) · 'expired' (aperta ma deadline passata) · 'open'.
 * Così "Scaduta" non è uno stato memorizzato e non serve alcun cron.
 */
final class OpportunityPresenter
{
    /** Scheda opportunità (liste, card di browse/gestione). */
    public static function card(array $o): array
    {
        return [
            'id'         => (int) $o['id'],
            'title'      => $o['title'],
            'kind'       => $o['kind'],
            'state'      => self::state($o),
            'status'     => $o['status'],
            'sport'      => ($o['sport_slug'] ?? null) ? [
                'slug' => $o['sport_slug'],
                'name' => $o['sport_name'] ?? null,
            ] : null,
            'location'   => self::locationLine($o) ?: null,
            'event_date' => $o['event_date'] ?? null,
            'deadline'   => $o['deadline'] ?? null,
            'applications_count' => (int) ($o['applications_count'] ?? 0),
            'created_at' => $o['created_at'] ?? null,
            'organization' => [
                'handle'          => $o['org_handle'] ?? null,
                'display_name'    => $o['org_display_name'] ?? null,
                'is_organization' => (bool) ($o['org_is_organization'] ?? true),
                'type_key'        => $o['org_type_key'] ?? null,
                'verified'        => !empty($o['org_verified_at']),
                'avatar_url'      => self::assetUrl($o['org_avatar_path'] ?? null),
                'url'             => ($o['org_handle'] ?? null) ? Config::absoluteUrl('atleti/' . $o['org_handle']) : null,
            ],
            'url' => Config::absoluteUrl('opportunita/' . (int) $o['id']),
        ];
    }

    /** Vista completa (pagina dettaglio): scheda + descrizione/requisiti. */
    public static function full(array $o): array
    {
        return self::card($o) + [
            'description' => $o['description'] ?? null,
        ];
    }

    /**
     * Candidatura vista dall'ORG (gestione): stato + messaggio + profilo candidato.
     * Il messaggio è testo grezzo (nessun HTML): l'escaping è a carico di view/client.
     */
    public static function application(array $a): array
    {
        return [
            'id'            => (int) $a['id'],
            'status'        => $a['status'],
            'cover_message' => $a['cover_message'] ?? null,
            'created_at'    => $a['created_at'] ?? null,
            'responded_at'  => $a['responded_at'] ?? null,
            'applicant'     => [
                'handle'       => $a['ap_handle'] ?? null,
                'display_name' => $a['ap_display_name'] ?? null,
                'headline'     => $a['ap_headline'] ?? null,
                'verified'     => !empty($a['ap_verified_at']),
                'type_key'     => $a['ap_type_key'] ?? null,
                'sport'        => ($a['ap_sport_slug'] ?? null) ? [
                    'slug' => $a['ap_sport_slug'],
                    'name' => $a['ap_sport_name'] ?? null,
                ] : null,
                'avatar_url'   => self::assetUrl($a['ap_avatar_path'] ?? null),
                'url'          => ($a['ap_handle'] ?? null) ? Config::absoluteUrl('atleti/' . $a['ap_handle']) : null,
            ],
        ];
    }

    /**
     * Candidatura vista dall'ATLETA ("le mie candidature"): stato + opportunità e org destinataria.
     */
    public static function myApplication(array $a): array
    {
        return [
            'id'           => (int) $a['id'],
            'status'       => $a['status'],
            'created_at'   => $a['created_at'] ?? null,
            'responded_at' => $a['responded_at'] ?? null,
            'opportunity'  => [
                'id'       => (int) $a['opportunity_id'],
                'title'    => $a['opp_title'] ?? null,
                'kind'     => $a['opp_kind'] ?? null,
                'status'   => $a['opp_status'] ?? null,
                'deadline' => $a['opp_deadline'] ?? null,
                'url'      => Config::absoluteUrl('opportunita/' . (int) $a['opportunity_id']),
            ],
            'organization' => [
                'handle'       => $a['org_handle'] ?? null,
                'display_name' => $a['org_display_name'] ?? null,
                'verified'     => !empty($a['org_verified_at']),
                'avatar_url'   => self::assetUrl($a['org_avatar_path'] ?? null),
                'url'          => ($a['org_handle'] ?? null) ? Config::absoluteUrl('atleti/' . $a['org_handle']) : null,
            ],
        ];
    }

    /** Stato derivato: 'closed' | 'expired' | 'open'. */
    public static function state(array $o): string
    {
        if (($o['status'] ?? 'open') === 'closed') {
            return 'closed';
        }
        $deadline = $o['deadline'] ?? null;
        // gmdate: UTC, coerente con UTC_DATE() in OpportunityRepository::listPublic (fuso-indipendente).
        if ($deadline !== null && $deadline < gmdate('Y-m-d')) {
            return 'expired';
        }
        return 'open';
    }

    private static function locationLine(array $o): string
    {
        return implode(', ', array_filter([
            $o['location_city'] ?? null,
            $o['location_region'] ?? null,
        ]));
    }

    private static function assetUrl(?string $path): ?string
    {
        return ($path !== null && $path !== '') ? Config::absoluteUrl($path) : null;
    }
}
