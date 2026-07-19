<?php

namespace Spoome\Domain\Profiles;

use Spoome\Core\Request;
use Spoome\Core\Session;
use Spoome\Domain\Users\User;

/**
 * Punto di ingresso unico dell'AUTHZ multi-profilo: "questo utente può agire come questo profilo?"
 * e "come quale profilo sto agendo adesso?".
 *
 * Gerarchia ruoli: owner (3) > admin (2) > editor (1). `canActAs($u,$pid,$required)` è true se il
 * ruolo effettivo dell'utente sul profilo è >= alla soglia richiesta.
 *
 * DUAL-READ (zero-regressione durante il rollout): la sorgente di verità è `profile_members`; ma se
 * per quel profilo NON esiste ancora una riga membro (dati/sessioni pre-deploy), si ricade su
 * `profiles.user_id === user->id`, trattandolo come `owner`. Così il comportamento 1:1 resta identico
 * anche prima che ogni call-site sia cablato. Il backfill R0 rende questo ramo quasi mai usato.
 *
 * STATO R1: costruito ma NON cablato. Nessun controller lo usa ancora; `profiles.user_id` resta
 * autoritativo. R5 sposterà le write su `canActAs(...) + acting id`; questa classe è già pronta.
 */
final class ActingContext
{
    /** Peso gerarchico dei ruoli (più alto = più potente). */
    private const RANK = ['editor' => 1, 'admin' => 2, 'owner' => 3];

    private ProfileMemberRepository $members;
    private ProfileRepository $profiles;

    public function __construct(?ProfileMemberRepository $members = null, ?ProfileRepository $profiles = null)
    {
        $this->members  = $members  ?? new ProfileMemberRepository();
        $this->profiles = $profiles ?? new ProfileRepository();
    }

    /**
     * True se $user può agire come $profileId con almeno il ruolo $requiredRole.
     * Legge `profile_members`; in assenza di riga membro, dual-read fallback su `profiles.user_id`.
     */
    public function canActAs(int $userId, int $profileId, string $requiredRole = 'editor'): bool
    {
        $required = self::RANK[$requiredRole] ?? self::RANK['editor'];
        $role = $this->roleFor($userId, $profileId);
        if ($role === null) {
            return false;
        }
        return (self::RANK[$role] ?? 0) >= $required;
    }

    /**
     * Ruolo effettivo dell'utente sul profilo (owner|admin|editor) o null se non può agire.
     * Dual-read: riga `profile_members` → altrimenti fallback owner se `profiles.user_id === userId`.
     */
    public function roleFor(int $userId, int $profileId): ?string
    {
        $role = $this->members->roleOf($userId, $profileId);
        if ($role !== null) {
            return $role;
        }
        // Fallback dual-read: lecito SOLO per un profilo ancora privo di roster (pre-backfill). Se il
        // profilo ha QUALSIASI riga membro, quel roster è autoritativo → niente ripiego su
        // `profiles.user_id` (altrimenti un ex-membro rimosso che combacia ancora col primary owner
        // denormalizzato rientrerebbe silenziosamente come owner: privilege escalation).
        if ($this->members->hasAnyMember($profileId)) {
            return null;
        }
        $profile = $this->profiles->findRawById($profileId);
        if ($profile !== null && $profile['user_id'] !== null && (int) $profile['user_id'] === $userId) {
            return 'owner';
        }
        return null;
    }

    /**
     * Profilo PERSONALE dell'utente (default dell'acting context) come entità, o null. Multi-profilo-safe:
     * il personale è il profilo non-org; per un utente mono-profilo (persona o org self-registrata)
     * ricade su findByUserId → identico al comportamento 1:1. Incapsula il pattern ripetuto
     * `findPersonalByUserId ?? findByUserId` usato dalle azioni solo-personali (follow/connessione/endorse).
     */
    public function personalProfile(User $user): ?Profile
    {
        return $this->profiles->personalOrAny($user->id);
    }

    /** Id del profilo PERSONALE dell'utente (default dell'acting context), o null. */
    public function personalProfileId(User $user): ?int
    {
        return $this->personalProfile($user)?->id;
    }

    /**
     * Profilo per cui l'utente sta agendo adesso (acting profile id) o null — per le LETTURE.
     *
     * Ordine: dichiarazione del client (header `X-Acting-Profile` per Bearer/native, oppure
     * `Session acting_profile_id` per il web) → SEMPRE ri-validata via canActAs('editor'); se non
     * valida (o assente) → fallback silenzioso al profilo personale. Mai un 403 su una lettura.
     * Per un utente mono-profilo il claim è sempre = personale → identico a prima.
     */
    public function resolve(Request $request, User $user): ?int
    {
        $personalId = $this->personalProfileId($user);
        $claim = $this->claimedProfileId($request);
        if ($claim !== null && $claim !== $personalId && $this->canActAs($user->id, $claim, 'editor')) {
            return $claim;
        }
        return $personalId;
    }

    /**
     * Acting id per le SCRITTURE. A differenza di resolve(), se il client dichiara ESPLICITAMENTE
     * un profilo-pagina che NON può gestire con il ruolo richiesto → ritorna null (il chiamante
     * emette 403), senza fallback silenzioso al personale. Se non c'è dichiarazione (o è il
     * personale) → il personale, autorizzato dalla proprietà. Il client non è mai fidato:
     * canActAs ri-valida contro `profile_members` ad ogni richiesta.
     */
    public function resolveForWrite(Request $request, User $user, string $requiredRole = 'editor'): ?int
    {
        $personalId = $this->personalProfileId($user);
        $claim = $this->claimedProfileId($request);
        if ($claim !== null && $claim !== $personalId) {
            return $this->canActAs($user->id, $claim, $requiredRole) ? $claim : null;
        }
        return $personalId;
    }

    /** Le pagine (profile_members) gestite dall'utente — hot path dello switcher "agisci come". */
    public function managedPages(int $userId): array
    {
        return $this->members->pagesFor($userId);
    }

    /**
     * Profilo dichiarato dal client (mai fidato di per sé): header `X-Acting-Profile` (Bearer/native)
     * ha precedenza, poi `Session acting_profile_id` (web). Solo interi positivi; null se assente.
     */
    private function claimedProfileId(Request $request): ?int
    {
        $hdr = $request->header('X-Acting-Profile');
        if ($hdr !== null && ctype_digit($hdr)) {
            return (int) $hdr > 0 ? (int) $hdr : null;
        }
        $sess = Session::get('acting_profile_id');
        if ($sess !== null && (int) $sess > 0) {
            return (int) $sess;
        }
        return null;
    }
}
