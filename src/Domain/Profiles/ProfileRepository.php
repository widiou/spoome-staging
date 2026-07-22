<?php

namespace Spoome\Domain\Profiles;

use PDO;
use Spoome\Core\Db;
use Spoome\Core\Pagination;
use Spoome\Support\Str;

/**
 * Accesso ai dati di `profiles` e `profile_types`.
 */
final class ProfileRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Db::connection();
    }

    /** Tipi profilo attivi: [id, key, label, is_organization]. Dati di riferimento → in cache. */
    public function activeTypes(): array
    {
        return \Spoome\Core\Cache::remember('profile_types_active', 600, function (): array {
            return $this->pdo->query(
                'SELECT id, `key`, label, is_organization FROM profile_types WHERE active = 1 ORDER BY sort'
            )->fetchAll();
        });
    }

    /** @return string[] chiavi dei tipi attivi (per whitelist in validazione). Derivato dalla cache. */
    public function activeTypeKeys(): array
    {
        return array_map(static fn ($t) => (string) $t['key'], $this->activeTypes());
    }

    /**
     * Tipi profilo attivi NON-organizzazione (atleta, fan, …). Sono gli unici creabili dalla
     * self-registration: le organizzazioni (società/associazione/federazione) nascono SOLO come
     * "pagine" via PageService, che stabilisce la owner-row autoritativa in `profile_members`.
     * Iscriversi come org bypasserebbe quel roster (owner solo denormalizzato) → doppio-path.
     * @return array<int,array{id:int,key:string,label:string,is_organization:int}>
     */
    public function activePersonalTypes(): array
    {
        return array_values(array_filter(
            $this->activeTypes(),
            static fn ($t) => (int) $t['is_organization'] === 0
        ));
    }

    /** @return string[] chiavi dei tipi-persona attivi (whitelist di validazione della registrazione). */
    public function activePersonalTypeKeys(): array
    {
        return array_map(static fn ($t) => (string) $t['key'], $this->activePersonalTypes());
    }

    /** True se la chiave-tipo è di un'organizzazione (guardia server-side anti doppio-path). */
    public function isOrganizationKey(string $key): bool
    {
        foreach ($this->activeTypes() as $t) {
            if ((string) $t['key'] === $key) {
                return (int) $t['is_organization'] === 1;
            }
        }
        return false;
    }

    public function typeIdByKey(string $key): ?int
    {
        foreach ($this->activeTypes() as $t) {
            if ((string) $t['key'] === $key) {
                return (int) $t['id'];
            }
        }
        return null;
    }

    public function findByUserId(int $userId): ?Profile
    {
        $stmt = $this->pdo->prepare('SELECT * FROM profiles WHERE user_id = :uid LIMIT 1');
        $stmt->execute(['uid' => $userId]);
        $row = $stmt->fetch();
        return $row ? Profile::fromRow($row) : null;
    }

    public function findByHandle(string $handle): ?Profile
    {
        $stmt = $this->pdo->prepare('SELECT * FROM profiles WHERE handle = :h LIMIT 1');
        $stmt->execute(['h' => $handle]);
        $row = $stmt->fetch();
        return $row ? Profile::fromRow($row) : null;
    }

    public function findById(int $id): ?Profile
    {
        $stmt = $this->pdo->prepare('SELECT * FROM profiles WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ? Profile::fromRow($row) : null;
    }

    /**
     * Profilo PERSONALE dell'utente (il tipo non-organizzazione che possiede). Deterministico:
     * filtra `is_organization = 0` e prende il più vecchio (id ASC). Multi-profilo: un utente può
     * possedere personale + N pagine org, tutte con `user_id = suo`; questo isola il personale.
     * Per un utente mono-profilo persona coincide con findByUserId (zero regressione).
     */
    public function findPersonalByUserId(int $userId): ?Profile
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.* FROM profiles p
             JOIN profile_types pt ON pt.id = p.profile_type_id
             WHERE p.user_id = :uid AND pt.is_organization = 0
             ORDER BY p.id ASC LIMIT 1'
        );
        $stmt->execute(['uid' => $userId]);
        $row = $stmt->fetch();
        return $row ? Profile::fromRow($row) : null;
    }

    /**
     * Profilo PERSONALE dell'utente, con fallback su un qualsiasi profilo suo se il personale manca.
     * Incapsula l'idioma ripetuto `findPersonalByUserId ?? findByUserId`: identità = profilo personale
     * (azioni solo-personali: follow/connessione/endorse), deterministico per chi ha personale + pagine.
     */
    public function personalOrAny(int $userId): ?Profile
    {
        return $this->findPersonalByUserId($userId) ?? $this->findByUserId($userId);
    }

    private function handleExists(string $handle): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM profiles WHERE handle = :h LIMIT 1');
        $stmt->execute(['h' => $handle]);
        return (bool) $stmt->fetchColumn();
    }

    /** True se l'handle è già usato da un profilo DIVERSO da $exceptId (per la modifica). */
    public function handleTakenByOther(string $handle, int $exceptId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM profiles WHERE handle = :h AND id <> :id LIMIT 1');
        $stmt->execute(['h' => $handle, 'id' => $exceptId]);
        return (bool) $stmt->fetchColumn();
    }

    /** Genera un handle univoco partendo da una base (display name). */
    public function uniqueHandle(string $base): string
    {
        $handle = Str::handle($base);
        if (!$this->handleExists($handle)) {
            return $handle;
        }
        for ($i = 2; $i < 10000; $i++) {
            $candidate = substr($handle, 0, 24) . $i;
            if (!$this->handleExists($candidate)) {
                return $candidate;
            }
        }
        return 'u' . Str::token(6); // fallback estremo
    }

    public function create(int $userId, int $profileTypeId, string $handle, string $displayName, ?int $sportId = null): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO profiles (user_id, profile_type_id, handle, display_name, sport_id)
             VALUES (:uid, :tid, :handle, :name, :sport)'
        );
        $stmt->execute([
            'uid'    => $userId,
            'tid'    => $profileTypeId,
            'handle' => $handle,
            'name'   => $displayName,
            'sport'  => $sportId,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Crea un profilo NON rivendicato (owner NULL): seed della piattaforma, in attesa di un claim. */
    public function createUnclaimed(int $profileTypeId, string $handle, string $displayName, ?string $headline = null, ?int $sportId = null): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO profiles (user_id, claim_status, profile_type_id, handle, display_name, headline, sport_id)
             VALUES (NULL, 'unclaimed', :tid, :handle, :name, :headline, :sport)"
        );
        $stmt->execute([
            'tid'      => $profileTypeId,
            'handle'   => $handle,
            'name'     => $displayName,
            'headline' => ($headline ?? '') !== '' ? $headline : null,
            'sport'    => $sportId,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Assegna la proprietà di un profilo a un utente (rivendicazione approvata). */
    public function assignOwner(int $profileId, int $userId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE profiles SET user_id = :uid, claim_status = 'claimed' WHERE id = :id"
        );
        $stmt->execute(['uid' => $userId, 'id' => $profileId]);
    }

    /** Verifica (o annulla la verifica di) un profilo: scrive/azzera `verified_at`. */
    public function setVerified(int $profileId, bool $verified): void
    {
        $sql = $verified
            ? 'UPDATE profiles SET verified_at = NOW() WHERE id = :id'
            : 'UPDATE profiles SET verified_at = NULL WHERE id = :id';
        $this->pdo->prepare($sql)->execute(['id' => $profileId]);
    }

    /** Riga grezza per id (senza join), per i controlli del claim. */
    public function findRawById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM profiles WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** True se l'utente possiede già un profilo. */
    public function userHasProfile(int $userId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM profiles WHERE user_id = :uid LIMIT 1');
        $stmt->execute(['uid' => $userId]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Aggiorna i campi core del profilo (editor `/profilo`). I valori vuoti diventano NULL.
     * @param array{display_name:string,handle:string,headline:?string,bio:?string,sport_id:?int,location_city:?string,location_region:?string,location_country:?string,visibility:string} $d
     */
    public function updateCore(int $profileId, array $d): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE profiles SET
                display_name     = :display_name,
                handle           = :handle,
                headline         = :headline,
                bio              = :bio,
                sport_id         = :sport_id,
                location_city    = :location_city,
                location_region  = :location_region,
                location_country = :location_country,
                visibility       = :visibility
             WHERE id = :id'
        );
        $stmt->execute([
            'display_name'     => $d['display_name'],
            'handle'           => $d['handle'],
            'headline'         => $d['headline'],
            'bio'              => $d['bio'],
            'sport_id'         => $d['sport_id'],
            'location_city'    => $d['location_city'],
            'location_region'  => $d['location_region'],
            'location_country' => $d['location_country'],
            'visibility'       => $d['visibility'],
            'id'               => $profileId,
        ]);
    }

    /**
     * Scrive i campi descrittivi type-specific (`profiles.attributes`), già serializzati in JSON
     * (o null per svuotare). Il JSON è prodotto/validato a monte da ProfileAttributes::sanitize.
     */
    public function updateAttributes(int $profileId, ?string $json): void
    {
        $stmt = $this->pdo->prepare('UPDATE profiles SET attributes = :a WHERE id = :id');
        $stmt->bindValue(':a', $json, $json === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':id', $profileId, PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Definizioni dei campi type-specific per un profilo (whitelist), risolte dal suo tipo.
     * @return array<int,array> definizioni da ProfileAttributes::fields()
     */
    public function schemaFieldsForProfile(int $profileId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT pt.attributes_schema FROM profiles p
             JOIN profile_types pt ON pt.id = p.profile_type_id WHERE p.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $profileId]);
        $json = $stmt->fetchColumn();
        return ProfileAttributes::fields($json === false ? null : (string) $json);
    }

    /** Handle + data ultima modifica di tutti i profili pubblici (per la sitemap). */
    public function allPublicForSitemap(): array
    {
        return $this->pdo->query(
            "SELECT p.handle, p.updated_at, pt.`key` AS type_key
               FROM profiles p JOIN profile_types pt ON pt.id = p.profile_type_id
              WHERE p.visibility = 'public' ORDER BY p.updated_at DESC"
        )->fetchAll();
    }

    /** Imposta (o azzera) l'avatar del profilo. */
    public function setAvatarMediaId(int $profileId, ?int $mediaId): void
    {
        $this->setMediaColumn('avatar_media_id', $profileId, $mediaId);
    }

    /** Imposta (o azzera) la copertina del profilo. */
    public function setCoverMediaId(int $profileId, ?int $mediaId): void
    {
        $this->setMediaColumn('cover_media_id', $profileId, $mediaId);
    }

    private function setMediaColumn(string $column, int $profileId, ?int $mediaId): void
    {
        // $column è un identificatore whitelisted (mai input utente).
        $stmt = $this->pdo->prepare("UPDATE profiles SET {$column} = :m WHERE id = :id");
        $stmt->bindValue(':m', $mediaId, $mediaId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':id', $profileId, PDO::PARAM_INT);
        $stmt->execute();
    }

    /** SELECT arricchito (sport + tipo + avatar + cover) usato dalla pagina pubblica e dalla directory. */
    private const SELECT_ENRICHED =
        'SELECT p.id, p.user_id, p.claim_status, p.profile_type_id, p.handle, p.display_name, p.headline, p.bio,
                p.sport_id, p.avatar_media_id, p.cover_media_id, p.location_city, p.location_region, p.location_country,
                p.verified_at, p.visibility, p.created_at, p.attributes,
                s.name AS sport_name, s.slug AS sport_slug, s.category AS sport_category,
                pt.`key` AS type_key, pt.label AS type_label, pt.is_organization, pt.attributes_schema,
                am.disk_path AS avatar_path, ac.disk_path AS cover_path
         FROM profiles p
         JOIN profile_types pt ON pt.id = p.profile_type_id
         LEFT JOIN sports s ON s.id = p.sport_id
         LEFT JOIN media am ON am.id = p.avatar_media_id
         LEFT JOIN media ac ON ac.id = p.cover_media_id';

    /** Profilo pubblico per handle (visibilità public). Ritorna la riga arricchita o null. */
    public function findPublicByHandle(string $handle): ?array
    {
        $stmt = $this->pdo->prepare(self::SELECT_ENRICHED . " WHERE p.handle = :h AND p.visibility = 'public' LIMIT 1");
        $stmt->execute(['h' => $handle]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Righe arricchite per un insieme di id, indicizzate per id (idratazione feed/liste).
     * @param int[] $ids
     * @return array<int,array>
     */
    public function cardsByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($ids))));
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(self::SELECT_ENRICHED . " WHERE p.id IN ($in)");
        foreach ($ids as $k => $v) {
            $stmt->bindValue($k + 1, $v, PDO::PARAM_INT);
        }
        $stmt->execute();
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['id']] = $row;
        }
        return $out;
    }

    /**
     * Sport_id distinti dei profili indicati (per l'interesse news: sport del profilo + di chi segue/è connesso).
     * @param int[] $profileIds
     * @return int[]
     */
    public function sportIdsFor(array $profileIds): array
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($profileIds))));
        if ($ids === []) {
            return [];
        }
        $in   = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT DISTINCT sport_id FROM profiles WHERE id IN ($in) AND sport_id IS NOT NULL");
        foreach ($ids as $k => $v) {
            $stmt->bindValue($k + 1, $v, PDO::PARAM_INT);
        }
        $stmt->execute();
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Riga arricchita per user_id (per l'editor / area personale). */
    public function findEnrichedByUserId(int $userId): ?array
    {
        $stmt = $this->pdo->prepare(self::SELECT_ENRICHED . ' WHERE p.user_id = :uid LIMIT 1');
        $stmt->execute(['uid' => $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Riga arricchita per id di profilo (per l'editor multi-profilo: si modifica l'acting profile). */
    public function findEnrichedById(int $profileId): ?array
    {
        $stmt = $this->pdo->prepare(self::SELECT_ENRICHED . ' WHERE p.id = :id LIMIT 1');
        $stmt->execute(['id' => $profileId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Directory pubblica: profili visibili, filtrabili per tipo e sport, paginati.
     * @return array{items:array<int,array>, total:int}
     */
    public function listPublic(int $page = 1, int $perPage = 24, ?string $typeKey = null, ?int $sportId = null, ?string $search = null, bool $withCount = true): array
    {
        $where  = ["p.visibility = 'public'"];
        $params = [];
        if ($typeKey !== null && $typeKey !== '') {
            $where[] = 'pt.`key` = :type';
            $params[':type'] = $typeKey;
        }
        if ($sportId !== null) {
            $where[] = 'p.sport_id = :sport';
            $params[':sport'] = $sportId;
        }
        // Tie-break su p.id: created_at NON è unico → senza secondo criterio l'ordine tra righe con
        // lo stesso timestamp è indeterminato e la paginazione (OFFSET o keyset) può duplicare/saltare
        // profili al confine di pagina. L'indice idx_profiles_vis_created ha id (PK) implicitamente in coda
        // → (visibility, created_at, id) è servito dall'indice senza filesort, e resta keyset-ready.
        $orderBy = 'p.created_at DESC, p.id DESC';
        $scoreTerm = null;  // termine per lo score MATCH nell'ORDER BY (bind solo sulla SELECT, non sul COUNT)
        $searchJoin = '';   // JOIN sul set-candidato indicizzato della ricerca (vuoto se non si cerca)
        if ($search !== null && trim($search) !== '') {
            // Ricerca indicizzata: FULLTEXT (indice ft_profiles_search su display_name+headline+bio)
            // in BOOLEAN MODE, ogni termine come prefisso obbligatorio (+term*). Sul handle un prefisso
            // (LIKE 'term%', senza wildcard iniziale → indicizzabile), e sul nome dello sport (LIKE prefisso).
            $raw = trim($search);
            $cleanTerms = [];
            foreach (preg_split('/\s+/', $raw) ?: [] as $t) {
                $t = preg_replace('/[+\-><()~*"@]+/', '', (string) $t); // rimuove gli operatori boolean utente
                if ($t !== '') {
                    $cleanTerms[] = '+' . $t . '*';
                }
            }
            $boolean = implode(' ', $cleanTerms);
            $handlePrefix = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $raw) . '%';

            // I candidati sono raccolti come UNION di rami INDICIZZATI (ciascuno col proprio access path) in una
            // tabella derivata materializzata una sola volta, poi JOINata su p.id. L'OR fra MATCH e i LIKE
            // vanificava ft_profiles_search (l'ottimizzatore non combina FULLTEXT con predicati non-fulltext
            // in OR → full scan): la derived-table isola il ramo FULLTEXT così usa l'indice ed è la driving
            // table (niente più full scan di profiles). NB: `p.id IN (<union>)` NON basta — MySQL lo degrada
            // a DEPENDENT SUBQUERY probata per-riga; serve il JOIN sulla derivata. Il set risultante è identico
            // all'OR precedente. Placeholder distinti (EMULATE_PREPARES=false).
            $branches = [];
            if ($boolean !== '') {
                $branches[] = 'SELECT id FROM profiles WHERE MATCH(display_name, headline, bio) AGAINST (:q IN BOOLEAN MODE)';
                $params[':q'] = $boolean;
            }
            $branches[] = "SELECT id FROM profiles WHERE handle LIKE :qhandle ESCAPE '\\\\'";
            $params[':qhandle'] = $handlePrefix;
            $branches[] = "SELECT sp.id FROM profiles sp JOIN sports ss ON ss.id = sp.sport_id WHERE ss.name LIKE :qsport ESCAPE '\\\\'";
            $params[':qsport'] = $handlePrefix;

            $searchJoin = ' JOIN (' . implode(' UNION ', $branches) . ') sm ON sm.id = p.id';

            if ($boolean !== '') {
                // Ordina per rilevanza (score MATCH computato sul solo set candidato, non full scan) e poi
                // per data. Placeholder DISTINTO da :q perché con EMULATE_PREPARES=false un named param
                // non è riutilizzabile. Bind solo sulla SELECT (il COUNT non ha ORDER BY).
                $orderBy = 'MATCH(p.display_name, p.headline, p.bio) AGAINST (:qscore IN BOOLEAN MODE) DESC, p.created_at DESC, p.id DESC';
                $scoreTerm = $boolean;
            }
        }
        $whereSql = implode(' AND ', $where);

        // COUNT saltabile (withCount=false) per le sezioni discovery, che non usano il totale → -N query sulla landing.
        $total = 0;
        if ($withCount) {
            $countStmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM profiles p JOIN profile_types pt ON pt.id = p.profile_type_id LEFT JOIN sports s ON s.id = p.sport_id'
                . $searchJoin . ' WHERE ' . $whereSql
            );
            $countStmt->execute($params);
            $total = (int) $countStmt->fetchColumn();
        }

        $offset = Pagination::of($page, $perPage)->offset();
        $stmt = $this->pdo->prepare(
            self::SELECT_ENRICHED . $searchJoin . ' WHERE ' . $whereSql . ' ORDER BY ' . $orderBy . ' LIMIT :lim OFFSET :off'
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        if ($scoreTerm !== null) {
            $stmt->bindValue(':qscore', $scoreTerm);
        }
        $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }

    /**
     * Profili suggeriti da seguire (cold-start): pubblici, diversi da sé, non già seguiti,
     * stesso sport in cima, poi i più seguiti. Placeholder distinti (EMULATE_PREPARES=false).
     * @return array<int,array>
     */
    public function suggestedFor(int $profileId, ?int $sportId, int $limit = 6): array
    {
        $stmt = $this->pdo->prepare(
            self::SELECT_ENRICHED . "
             WHERE p.visibility = 'public' AND p.id <> :me
               AND p.id NOT IN (SELECT followee_id FROM follows WHERE follower_id = :me2)
             ORDER BY (p.sport_id = :sport) DESC, p.followers_count DESC, p.created_at DESC
             LIMIT :lim"
        );
        $stmt->bindValue(':me', $profileId, PDO::PARAM_INT);
        $stmt->bindValue(':me2', $profileId, PDO::PARAM_INT);
        $stmt->bindValue(':sport', $sportId, $sportId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** Profili pubblici che seguono $profileId (paginati, più recenti prima). */
    public function followersOf(int $profileId, int $page = 1, int $perPage = 24): array
    {
        return $this->listByFollowJoin('JOIN follows f ON f.follower_id = p.id AND f.followee_id = :pid', $profileId, $page, $perPage);
    }

    /** Profili pubblici seguiti da $profileId (paginati, più recenti prima). */
    public function followingOf(int $profileId, int $page = 1, int $perPage = 24): array
    {
        return $this->listByFollowJoin('JOIN follows f ON f.followee_id = p.id AND f.follower_id = :pid', $profileId, $page, $perPage);
    }

    /** @return array{items:array<int,array>, total:int} */
    private function listByFollowJoin(string $followJoin, int $profileId, int $page, int $perPage): array
    {
        $countStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM profiles p {$followJoin} WHERE p.visibility = 'public'"
        );
        $countStmt->bindValue(':pid', $profileId, PDO::PARAM_INT);
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();

        $offset = Pagination::of($page, $perPage)->offset();
        $stmt = $this->pdo->prepare(
            self::SELECT_ENRICHED . " {$followJoin} WHERE p.visibility = 'public' ORDER BY f.created_at DESC, p.id DESC LIMIT :lim OFFSET :off"
        );
        $stmt->bindValue(':pid', $profileId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }

    /**
     * Profili pubblici connessi (accepted) a $profileId, in entrambi i versi.
     *
     * Riscritto da `WHERE requester_id = :x OR addressee_id = :x` (non-sargable: l'OR su due colonne
     * diverse forza full-scan/index-merge) a UNION ALL di due SELECT ognuna servita da un indice
     * dedicato: il ramo "richieste inviate" colpisce idx_conn_requester (requester_id, status),
     * il ramo "richieste ricevute" idx_conn_addressee (addressee_id, status). La UNION è ristretta
     * alla sola tabella connections (proiezione leggera other_id + ord); il join a `profiles` avviene
     * poi per PRIMARY KEY. L'ORDER BY resta identico (responded_at DESC, id DESC) → parità di risultati.
     *
     * PDO EMULATE_PREPARES=false: il valore di $profileId compare in ENTRAMBI i rami → placeholder
     * DISTINTI (:me1 / :me2), entrambi bindati. Riusare lo stesso nome darebbe il 500 HY093 storico.
     *
     * UNION ALL (non DISTINCT) = parità esatta con l'OR-join precedente: requester_id <> addressee_id
     * (nessun self-connection) garantisce che una singola riga connections non possa mai soddisfare
     * entrambi i rami, quindi non nascono duplicati dallo stesso record; l'assenza di dedup evita
     * anche il sort di deduplica.
     */
    public function connectionsOf(int $profileId, int $page = 1, int $perPage = 24): array
    {
        $connUnion =
            "JOIN (
                SELECT addressee_id AS other_id, responded_at AS ord
                  FROM connections WHERE requester_id = :me1 AND status = 'accepted'
                UNION ALL
                SELECT requester_id AS other_id, responded_at AS ord
                  FROM connections WHERE addressee_id = :me2 AND status = 'accepted'
             ) cx ON cx.other_id = p.id";

        $countStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM profiles p {$connUnion} WHERE p.visibility = 'public'"
        );
        $countStmt->bindValue(':me1', $profileId, PDO::PARAM_INT);
        $countStmt->bindValue(':me2', $profileId, PDO::PARAM_INT);
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();

        $offset = Pagination::of($page, $perPage)->offset();
        $stmt = $this->pdo->prepare(
            self::SELECT_ENRICHED . " {$connUnion} WHERE p.visibility = 'public'
             ORDER BY cx.ord DESC, p.id DESC LIMIT :lim OFFSET :off"
        );
        $stmt->bindValue(':me1', $profileId, PDO::PARAM_INT);
        $stmt->bindValue(':me2', $profileId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }

    /** Profili pubblici che hanno una richiesta di connessione IN ENTRATA verso $profileId (pending). */
    public function incomingRequestsOf(int $profileId, int $page = 1, int $perPage = 24): array
    {
        $join = "JOIN connections c ON c.requester_id = p.id AND c.addressee_id = :pid AND c.status = 'pending'";
        return $this->listByConnJoin($join, [':pid' => $profileId], 'c.created_at', $page, $perPage);
    }

    /** @param array<string,int> $params @return array{items:array<int,array>, total:int} */
    private function listByConnJoin(string $connJoin, array $params, string $orderCol, int $page, int $perPage): array
    {
        $countStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM profiles p {$connJoin} WHERE p.visibility = 'public'"
        );
        foreach ($params as $k => $v) {
            $countStmt->bindValue($k, $v, PDO::PARAM_INT);
        }
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();

        $offset = Pagination::of($page, $perPage)->offset();
        $stmt = $this->pdo->prepare(
            self::SELECT_ENRICHED . " {$connJoin} WHERE p.visibility = 'public' ORDER BY {$orderCol} DESC, p.id DESC LIMIT :lim OFFSET :off"
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v, PDO::PARAM_INT);
        }
        $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }
}
