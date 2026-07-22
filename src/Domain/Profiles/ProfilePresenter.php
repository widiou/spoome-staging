<?php

namespace Spoome\Domain\Profiles;

use Spoome\Core\Config;

/**
 * Trasforma le righe DB arricchite del profilo nella forma JSON pubblica dell'API.
 * Unica sede della "shape" esposta: se cambia un campo, cambia qui e vale per tutti gli endpoint.
 * Non espone mai campi interni (user_id, *_media_id, visibility) nella vista pubblica.
 */
final class ProfilePresenter
{
    /** Scheda sintetica (directory, liste, card). */
    public static function card(array $p): array
    {
        return [
            'handle'       => $p['handle'],
            'display_name' => $p['display_name'],
            'headline'     => $p['headline'] ?? null,
            'type'         => [
                'key'             => $p['type_key'] ?? null,
                'label'           => $p['type_label'] ?? null,
                'is_organization' => (bool) ($p['is_organization'] ?? false),
            ],
            'sport'        => ($p['sport_slug'] ?? null) ? [
                'slug' => $p['sport_slug'],
                'name' => $p['sport_name'] ?? null,
            ] : null,
            'location'     => self::locationLine($p) ?: null,
            'avatar_url'   => self::assetUrl($p['avatar_path'] ?? null),
            'verified'     => !empty($p['verified_at']),
            'url'          => Config::absoluteUrl('atleti/' . $p['handle']),
        ];
    }

    /** Vista completa (pagina profilo pubblica): scheda + bio, copertina, località, sotto-entità. */
    public static function full(array $p, array $experiences = [], array $achievements = [], array $links = []): array
    {
        // Campi descrittivi type-specific (solo whitelist dello schema, mai PII): il presenter
        // è l'unica sede della shape → l'API espone gli stessi campi della pagina web.
        $attributes = ProfileAttributes::present(
            ProfileAttributes::fields($p['attributes_schema'] ?? null),
            $p['attributes'] ?? null
        );

        return self::card($p) + [
            'bio'          => $p['bio'] ?? null,
            'cover_url'    => self::assetUrl($p['cover_path'] ?? null),
            'attributes'   => $attributes,
            'location_detail' => [
                'city'    => $p['location_city'] ?? null,
                'region'  => $p['location_region'] ?? null,
                'country' => $p['location_country'] ?? null,
            ],
            'created_at'   => $p['created_at'] ?? null,
            'experiences'  => array_map([self::class, 'experience'], $experiences),
            'achievements' => array_map([self::class, 'achievement'], $achievements),
            'links'        => array_map([self::class, 'link'], $links),
        ];
    }

    /**
     * Pagina profilo COMPLETA per l'API (parità di contenuto con la vista web `atleti/show`).
     * Riceve il read-model "core" da {@see \Spoome\Domain\Profiles\ProfilePageService::collect()} e ne
     * serializza ogni sezione. Retro-compatibile: tutti i campi di {@see full()} restano al top level;
     * le sezioni community/skills/affiliazioni/post/insight sono AGGIUNTE.
     *
     * Privacy: `insights` (chi ha visto il profilo, con PII dei visitatori) è presente SOLO se il visitatore
     * può gestire la pagina — stessa authz del web (`core['canManage']`). Mai esposto ad anonimi/visitatori.
     *
     * @param array<string,mixed> $core read-model puro (chiavi da ProfilePageService::collect())
     */
    public static function page(array $core): array
    {
        $p = $core['profile'];

        $skills = [];
        foreach ($core['skills'] as $s) {
            $skills[] = self::skill($s, $core['endorsedIds'], $core['endorsers']);
        }

        $data = self::full($p, $core['experiences'], $core['achievements'], $core['links']) + [
            'viewer' => [
                'authenticated'           => (bool) $core['follow']['authenticated'],
                'is_own'                  => (bool) $core['isOwn'],
                'can_manage'              => (bool) $core['canManage'],
                'can_manage_affiliations' => (bool) $core['canManageAff'],
                'can_endorse'             => (bool) $core['canEndorse'],
                'can_recommend'           => (bool) ($core['canRecommend'] ?? false),
            ],
            'follow' => [
                'followers'    => (int) $core['follow']['count_followers'],
                'following'    => (int) $core['follow']['count_following'],
                'is_following' => (bool) $core['follow']['is_following'],
                'can_follow'   => (bool) $core['follow']['can_follow'],
            ],
            'connection' => [
                'count'       => (int) $core['connection']['count'],
                'status'      => $core['connection']['status'],
                'can_connect' => (bool) $core['connection']['can_connect'],
            ],
            'claim' => [
                'is_unclaimed'  => (bool) $core['claim']['is_unclaimed'],
                'authenticated' => (bool) $core['claim']['authenticated'],
                'has_profile'   => (bool) $core['claim']['has_profile'],
                'can_request'   => (bool) $core['claim']['can_request'],
                'pending'       => (bool) $core['claim']['pending'],
            ],
            'skills' => $skills,
            // Raccomandazioni VISIBILI (approvate): testo libero grezzo → JSON-encode (nessun HTML). Shape
            // unica via self::recommendation() (riusata dall'endpoint /me/recommendations/pending).
            'recommendations' => array_map([self::class, 'recommendation'], $core['recommendations'] ?? []),
            // Affiliazioni type-aware: roster (org) · militanza/carriera (membro). Shape unica con
            // AffiliationController via self::affiliation().
            'roster'       => array_map([self::class, 'affiliation'], $core['roster']),
            'affiliations' => array_map([self::class, 'affiliation'], $core['militanza']),
            // Post del profilo: già in forma pubblica (FeedPresenter::item) → passthrough.
            'posts' => $core['profilePosts'],
            // M3 Verification: stato di verifica esplicito. `staff` = badge ufficiale Spoome (verified_at);
            // `club` = badge DERIVATO "verificato dalla società" (affiliazione confermata verso org verificata),
            // mostrato quando NON già staff-verified. `club_by` = provenienza (le org-ancora) per trasparenza —
            // esposta anche se staff ha precedenza sul badge. Il campo top-level `verified` (staff) resta per
            // retro-compatibilità.
            'verification' => [
                'staff'   => (bool) $core['clubVerification']['staff'],
                'club'    => (bool) $core['clubVerification']['club'],
                'club_by' => array_map(static fn (array $o): array => [
                    'handle'       => $o['org_handle'],
                    'display_name' => $o['org_name'],
                    'is_current'   => (bool) $o['is_current'],
                    'url'          => Config::absoluteUrl('atleti/' . $o['org_handle']),
                ], $core['clubVerification']['orgs']),
            ],
        ];

        // Richieste di affiliazione in ingresso: solo per chi può gestire (ruolo ≥ admin) — coerente col web.
        if (!empty($core['canManageAff'])) {
            $data['affiliations_pending'] = array_map([self::class, 'affiliation'], $core['affPending']);
        }

        // Insight proprietari (PII dei visitatori): SOLO per chi gestisce la pagina.
        if ($core['insights'] !== null) {
            $data['insights'] = [
                'views_7d'       => (int) $core['insights']['views7d'],
                'recent_viewers' => array_map([self::class, 'viewer'], $core['insights']['recentViewers']),
            ];
        }

        return $data;
    }

    /** Competenza + stato endorsement (numero, se il visitatore l'ha già confermata, endorser recenti). */
    public static function skill(array $s, array $endorsedIds = [], array $endorsers = []): array
    {
        $sid = (int) $s['id'];
        return [
            'id'                 => $sid,
            'label'              => $s['label'],
            'endorsements'       => (int) ($s['endorsements_count'] ?? 0),
            'endorsed_by_viewer' => in_array($sid, $endorsedIds, true),
            'endorsers'          => array_map(static fn (array $e): array => [
                'display_name' => (string) $e['display_name'],
                'avatar_url'   => self::assetUrl($e['avatar_path'] ?? null),
            ], $endorsers[$sid] ?? []),
        ];
    }

    /**
     * Raccomandazione (testimonial) + autore joinato → forma API pulita. Testo grezzo (nessun HTML):
     * l'escaping è a carico del client / della view. Unica sede della shape (pagina profilo + pending).
     */
    public static function recommendation(array $r): array
    {
        return [
            'id'           => (int) $r['id'],
            'body'         => $r['body'],
            'relationship' => $r['relationship'] ?? null,
            'status'       => $r['status'] ?? null,
            'created_at'   => $r['created_at'] ?? null,
            'responded_at' => $r['responded_at'] ?? null,
            'author'       => [
                'handle'       => $r['author_handle'] ?? null,
                'display_name' => $r['author_display_name'] ?? null,
                'avatar_url'   => self::assetUrl($r['author_avatar_path'] ?? null),
                'url'          => ($r['author_handle'] ?? null) ? Config::absoluteUrl('atleti/' . $r['author_handle']) : null,
            ],
        ];
    }

    /**
     * Riga affiliazione arricchita (controparte = `cp_*`) → forma API pulita. Unica sede della shape:
     * riusata da AffiliationController (roster/militanza/pending) e dalla pagina profilo API.
     */
    public static function affiliation(array $r): array
    {
        return [
            'id'          => (int) $r['id'],
            'role'        => $r['role'],
            'team'        => $r['team'],
            'jersey'      => $r['jersey'],
            'start_year'  => $r['start_year'] !== null ? (int) $r['start_year'] : null,
            'end_year'    => $r['end_year'] !== null ? (int) $r['end_year'] : null,
            'is_current'  => (bool) $r['is_current'],
            'status'      => $r['status'],
            'requested_by_profile_id' => (int) $r['requested_by_profile_id'],
            'profile'     => [
                'id'              => (int) $r['cp_id'],
                'handle'          => $r['cp_handle'],
                'display_name'    => $r['cp_display_name'],
                'headline'        => $r['cp_headline'],
                'type_key'        => $r['cp_type_key'],
                'type_label'      => $r['cp_type_label'],
                'is_organization' => (bool) $r['cp_is_organization'],
                'verified'        => !empty($r['cp_verified_at']),
                'avatar_url'      => self::assetUrl($r['cp_avatar_path'] ?? null),
                'url'             => Config::absoluteUrl('atleti/' . $r['cp_handle']),
            ],
        ];
    }

    /** Visitatore recente per l'insight proprietario "Chi ha visto il profilo" (owner-only). */
    public static function viewer(array $v): array
    {
        return [
            'handle'         => $v['handle'],
            'display_name'   => $v['display_name'],
            'headline'       => $v['headline'] ?? null,
            'type'           => [
                'key'             => $v['type_key'] ?? null,
                'label'           => $v['type_label'] ?? null,
                'is_organization' => (bool) ($v['is_organization'] ?? false),
            ],
            'sport'          => ($v['sport_name'] ?? null) ? ['name' => $v['sport_name']] : null,
            'avatar_url'     => self::assetUrl($v['avatar_path'] ?? null),
            'verified'       => !empty($v['verified_at']),
            'last_viewed_at' => $v['last_viewed_at'] ?? null,
            'view_count'     => (int) ($v['view_count'] ?? 0),
            'url'            => Config::absoluteUrl('atleti/' . $v['handle']),
        ];
    }

    public static function experience(array $e): array
    {
        return [
            'id'          => (int) $e['id'],
            'org_name'    => $e['org_name'],
            'role'        => $e['role'],
            'location'    => $e['location'] ?? null,
            'start_year'  => $e['start_year'] !== null ? (int) $e['start_year'] : null,
            'end_year'    => $e['end_year'] !== null ? (int) $e['end_year'] : null,
            'is_current'  => (bool) $e['is_current'],
            'description' => $e['description'] ?? null,
        ];
    }

    public static function achievement(array $a): array
    {
        return [
            'id'          => (int) $a['id'],
            'title'       => $a['title'],
            'year'        => $a['year'] !== null ? (int) $a['year'] : null,
            'description' => $a['description'] ?? null,
        ];
    }

    public static function link(array $l): array
    {
        return [
            'id'    => (int) $l['id'],
            'kind'  => $l['kind'],
            'label' => $l['label'] ?? null,
            'url'   => $l['url'],
        ];
    }

    private static function locationLine(array $p): string
    {
        return implode(', ', array_filter([
            $p['location_city'] ?? null,
            $p['location_region'] ?? null,
            $p['location_country'] ?? null,
        ]));
    }

    private static function assetUrl(?string $path): ?string
    {
        return ($path !== null && $path !== '') ? Config::absoluteUrl($path) : null;
    }
}
