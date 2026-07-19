# Spoome Beta — Night QA Test Report

- **Target:** `https://spoome.it/beta` (env=staging)
- **Date:** 2026-07-04 (overnight sweep)
- **Method:** live curl (cookie-jar web logins + CSRF, Bearer API tokens) + direct DB reads/writes (PyMySQL → c32237.sgvps.net)
- **Demo users:** marco.rossi (user3/pid3, admin), giulia.bianchi (user4/pid4), luca.verdi (user5/pid5) — password `SpoomeBeta25!`
- **Result:** All 14 areas PASS. No P0/P1. No hot-path 500s. No security holes. Demo baseline restored exactly (verified).

---

## PASS/FAIL matrix

| # | Area | Result | Notes |
|---|------|--------|-------|
| 1 | Auth | PASS | login 302 / invalid re-render / API 401; throttle→429 after 2 fails; generic reset; no enumeration; step-up gate + 30min |
| 2 | Profile edit | PASS | async JSON 200 `{saved:true}`, form 302, no-CSRF 419, invalid 422 per-field; ownership enforced |
| 3 | Skills + endorsements | PASS | add/dup(422)/empty(422)/delete(204); endorse self→422, non-connected→403, valid+idempotent no drift, notif+24h dedup, remove-endorse; counts correct |
| 4 | Connections | PASS | request→notif, accept→notif+count+1 both, disconnect→count-1 GREATEST(0), row removed |
| 5 | Follows | PASS | follow/unfollow, counts both sides, notif `follow`, idempotent no drift |
| 6 | Suggestions (/rete) | PASS | 2nd-degree + mutual "in comune", city fallback, excludes self/connected/pending, dismiss removes + cleanup, no SQL error |
| 7 | Profile views | PASS | non-owner upsert + increment-on-repeat, self-view NOT recorded, widget (recent + 7d), page always 200 |
| 8 | Feed | PASS | post create async(201, XSS escaped)/empty(422)/no-CSRF(419); delete-own 204; like toggle+count+notif+6h dedup; comment count+XSS-escaped(server render); IDOR blocked |
| 9 | Links | PASS | unfurl link+YouTube envelope; **SSRF fully blocked (422 fast)**; image-proxy valid 200 / tampered·unsigned 403 / no-token 400 |
| 10 | DM | PASS | send 201, poll `?after=` once (no dup), unread++ , notif `new_message`, participant-only authz 403 |
| 11 | Notifications | PASS | event rows to right recipient; mark-all-read on `/notifiche` resets counter; **zero denorm drift** across all events |
| 12 | Search | PASS | `/atleti?q=` FULLTEXT relevance; empty ok; no HY093; boolean/SQLi payloads safe 200 |
| 13 | Admin | PASS | dashboard/stats(4 SVG)/utenti/claims/mod/log 200; suspend/reactivate round-trip; anti-self + last-admin safeguards; 404-cloak; claim approve→ownership transfer+notif, reject→notif; audit rows written |
| 14 | Cross-cutting security | PASS | web write=CSRF (419), API write=Bearer (401×9 endpoints), no IDOR (web+API), nav helpers never 500, public pages 200, hidden profile 404 |

---

## Security highlights (all PASS)

- **SSRF:** every probe returned **422 within <0.75s** — `169.254.169.254` (http+https), `127.0.0.1(:3306)`, `localhost`, `[::1]`, `0.0.0.0`, `10.0.0.1`, `192.168.1.1`, decimal `2130706433`, octal `0177.0.0.1`, `[fd00::1]`, `metadata.google.internal`, `ftp://` scheme. Blocked URLs are negative-cached as `status=failed` (fine).
- **Image proxy:** HMAC-signed token `u` — valid→200 real JPEG; last-char-flip signature→403; payload-without-sig→403; garbage→403; missing→400. Tokens are server-signed so internal-URL forgery is impossible.
- **IDOR:** verified at data layer, NO mutation/leak — marco deleting achievement/link of pid11 (302/422, rows unchanged), luca deleting marco post5 (web 302 / API 404, post intact), luca deleting marco's API post (404).
- **AuthZ:** DM participant-only (luca poll/send to marco→403), API writes 401 without Bearer, 404-cloak for non-admin on `/admin/*` and even `/admin/verifica`, step-up wrong-password denied.
- **Anti-enumeration:** login + password-forgot return identical generic messages for existing vs non-existent email.
- **XSS:** stored payloads escaped in every server render checked — skills (`&lt;i&gt;`), experiences (`&lt;b&gt;`), posts (`&lt;script&gt;`), DM thread (`&lt;b&gt;`).

---

## Bugs / observations (none block release)

### P3 — DM send returns `id: 0`
- **Endpoint:** `POST /messaggi/{handle}` (WebMessages::send) and API equivalent.
- **Repro:** send DM → response `{"data":{"id":0,"conversation_id":4}}` while the row actually inserted was id=10.
- **Expected:** returned `id` = inserted message id. **Actual:** `0`.
- **Impact:** cosmetic — poll uses the `after=` cursor so delivery still works and shows no duplicate. Likely `lastInsertId` not threaded back from the send service.

### P3 — Link IDOR returns 422 instead of 403/404
- **Endpoint:** `POST /profilo/link/{id}` (ProfileDetailsController::updateLink), async.
- **Repro:** marco updates link id=1 (owned by pid11) with valid data → **422** (looks like a validation error). Row verified unchanged (no mutation).
- **Expected:** 403/404 (as the delete-experience path returns for cross-owner). **Actual:** 422.
- **Impact:** low — no data exposure, just an inconsistent status code / confusing error surface.

### P3 — Rate-limit not observed on two endpoints
- **Image proxy `GET /link-image`:** 40 rapid requests to a valid cached token all returned 200, no 429. Likely rate-limits only cache-miss fetches; confirm the limiter also covers cached hits if that is the intended abuse control.
- **Skill add `POST /profilo/competenze` (skill:{pid}):** 8 rapid adds all 201, no 429. Likely a generous per-minute window; not confirmed triggering.

### Info — DM poll returns raw unescaped body in JSON
- `GET /messaggi/{handle}/nuovi` returns `"body":"QA DM <b>hi</b>"` unescaped (normal for a JSON API). Server-rendered thread page escapes correctly, so XSS-safety here depends on the client inserting via `textContent`. Recommend a quick client-side check; not a confirmed vuln.

### Not exhaustively tested
- Skills max-20 cap (would require 16+ churn adds on marco); dup rejection + rate-path verified instead.
- Profile-view soft-fail on insert error (can't force the error from outside); profile page returned 200 under all view operations.
- Owner-less profile notification skip: verified by schema/design (`notifications.user_id` non-null; unclaimed pid14 has `user_id NULL` so cannot be a recipient).
- Note: commenting DOES emit a `post_comment` notification (works; not in the original spec list).

---

## Demo baseline — restored & verified

| Item | Baseline | Final |
|------|----------|-------|
| marco (pid3) posts | 5, 12 | 5, 12 ✓ |
| all posts + counts | 3,4,5,12 (likes 1/1/1/0, comments 0) | identical ✓ |
| skills | pid3: 7,8,9,10 (endo 1 each); pid4: 2,3,4,5,6 (2/2/1/1/2) | identical ✓ |
| skill_endorsements | 12 rows | 12 ✓ |
| connections | id5 (3→4 acc), id6 (5→4 acc) | identical ✓ |
| follows | 6,8,9,10,11,13 | identical ✓ |
| profile_views | 7 rows | 7 ✓ |
| connection_dismissals | 0 | 0 ✓ |
| claim_requests | 6 rows (id6 pending) | identical ✓ |
| profiles counts (3/4/5) | 2·3·1 / 2·1·2 / 1·2·1, giulia unread_msg=3 | identical ✓ |
| federica-pellegrini (pid14) | unclaimed, user_id NULL | unclaimed ✓ |
| link_previews | 5 demo (bbc/wiki/repubblica/sky/youtube), status ok | 5 ✓ (9 SSRF-probe rows + 2 unfurl rows I created were deleted) |
| notification denorm drift | 0 | **0 (verified counter == actual unread for every user)** ✓ |
| notifications beyond id 17 | none | none ✓ |
| messages max id | 9 | 9 ✓ |

All ephemeral rows created during testing (posts, skills, experiences, endorsements, connections, follows, messages, notifications, dismissals, profile_views, link_previews, claim state) were deleted or reverted; every curated counter restored exactly.
