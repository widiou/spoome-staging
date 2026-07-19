<?php

namespace Spoome\Http\Controllers\Web\Admin;

use Spoome\Core\I18n;
use Spoome\Core\Request;
use Spoome\Core\Response;
use Spoome\Core\Session;
use Spoome\Domain\News\NewsIngestionService;
use Spoome\Domain\News\NewsSourceRepository;
use Spoome\Domain\Profiles\ProfileRepository;
use Spoome\Domain\Sports\SportRepository;

/**
 * Amministrazione delle fonti news (RSS/Atom): elenco, creazione/modifica/eliminazione,
 * attivazione, intervallo di aggiornamento per-fonte, sport di match, e trigger manuale di ingestione.
 * Area riservata: auth → admin (404-cloak) → step-up → CSRF (come il resto di /admin).
 */
final class NewsController extends AdminController
{
    public function index(Request $request): void
    {
        $repo = new NewsSourceRepository();
        $this->renderAdmin('admin/news/index', [
            'title'   => $this->title('admin.news.title'),
            'active'  => 'news',
            'sources' => $repo->all(),
            'sports'  => (new SportRepository())->all(),
            'notice'  => Session::takeFlash(),
        ]);
    }

    public function create(Request $request): void
    {
        $d = $request->body();
        $res = $this->persist(null, $d);
        Session::flash($res, $res === '' ? '' : 'error');
        Response::redirect('admin/news');
    }

    public function update(Request $request): void
    {
        $id  = (int) ($request->params['id'] ?? 0);
        $d   = $request->body();
        $res = $this->persist($id, $d);
        Session::flash($res === '' ? I18n::t('admin.news.flash.saved') : $res, $res === '' ? 'success' : 'error');
        Response::redirect('admin/news');
    }

    public function delete(Request $request): void
    {
        $id = (int) ($request->params['id'] ?? 0);
        (new NewsSourceRepository())->delete($id);
        Session::flash(I18n::t('admin.news.flash.deleted'), 'success');
        Response::redirect('admin/news');
    }

    public function toggle(Request $request): void
    {
        $id = (int) ($request->params['id'] ?? 0);
        (new NewsSourceRepository())->toggleActive($id);
        Response::redirect('admin/news');
    }

    /** Trigger manuale dell'ingestione (tutte le fonti dovute, o una specifica). */
    public function fetch(Request $request): void
    {
        $only = (int) ($request->body()['source_id'] ?? 0) ?: null;
        // Trigger globale: cap a 6 fonti per richiesta (il resto lo prende il cron); per-fonte: nessun cap.
        $r = (new NewsIngestionService())->run($only, $only === null ? 6 : 0);
        Session::flash(
            I18n::t('admin.news.flash.fetched', [
                'sources' => (string) $r['sources'],
                'added'   => (string) $r['added'],
                'errors'  => (string) count($r['errors']),
            ]),
            count($r['errors']) > 0 ? 'info' : 'success'
        );
        Response::redirect('admin/news');
    }

    /* ------------------------------------------------------------ helpers ---- */

    /** Crea o aggiorna una fonte dai dati del form. @return string '' se ok, altrimenti messaggio d'errore. */
    private function persist(?int $id, array $d): string
    {
        $name = trim((string) ($d['name'] ?? ''));
        $url  = trim((string) ($d['feed_url'] ?? ''));
        if ($name === '' || !preg_match('#^https?://#i', $url)) {
            return I18n::t('admin.news.error.invalid');
        }
        $refresh = max(5, min(1440, (int) ($d['refresh_minutes'] ?? 60)));
        $active  = !empty($d['active']);

        // Attribuzione opzionale a una pagina org (federazione) via handle; vuoto = fonte terza.
        $orgId  = null;
        $handle = trim((string) ($d['org_handle'] ?? ''));
        if ($handle !== '') {
            $org = (new ProfileRepository())->findPublicByHandle(ltrim($handle, '@'));
            if ($org === null) {
                return I18n::t('admin.news.error.org_missing');
            }
            $orgId = (int) $org['id'];
        }

        $sportIds = array_map('intval', (array) ($d['sports'] ?? []));

        $repo = new NewsSourceRepository();
        if ($id === null) {
            $repo->create($orgId, $name, $url, $refresh, $active, $sportIds);
        } else {
            $repo->update($id, $orgId, $name, $url, $refresh, $active, $sportIds);
        }
        return '';
    }
}
