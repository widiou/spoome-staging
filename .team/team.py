#!/usr/bin/env python3
"""team.py — CLI del tracker lavoro agenti Spoome (GitHub Issues).

Ogni operazione degli agenti passa da qui: aprire un work item, caricare un
risultato, chiedere aiuto, cambiare stato, chiudere. Il token GitHub è letto a
runtime da `git credential` (osxkeychain), MAI hardcoded. NON viene deployato
(vedi IGNORE_DIRS in jobs/deploy.py).

Uso:
  ./.team/team.py list [--mine Chiara] [--train R1]
  ./.team/team.py open --agent Sara --type bug --train R2 "Titolo" "Corpo markdown"
  ./.team/team.py comment 12 "Fatto il subset, -240KB confermati" --as Filippo
  ./.team/team.py state 12 in-review        # in-corso|in-review|bloccato|aiuto|clear
  ./.team/team.py assign 12 Filippo
  ./.team/team.py close 12 --comment "Verificato live, smoke verde"
"""
import json, subprocess, sys, urllib.request, urllib.error, urllib.parse, argparse

REPO = "widiou/spoome-staging"
API = f"https://api.github.com/repos/{REPO}"

ROSTER = {
    "Regia":"Orchestratore","Matteo":"Architetto backend","Dario":"DB & performance",
    "Sara":"Sicurezza","Filippo":"Frontend","Chiara":"QA & test","Giorgio":"Release & ops",
    "Elena":"Prodotto & mercato","Paolo":"Code review","Bianca":"UX",
}
STATES = {"in-corso","in-review","bloccato","aiuto"}

def token():
    out = subprocess.run(["git","credential","fill"],
        input="protocol=https\nhost=github.com\n\n", capture_output=True, text=True).stdout
    for line in out.splitlines():
        if line.startswith("password="): return line[9:]
    sys.exit("Nessun token GitHub nel keychain")

TOKEN = token()

def api(method, path, body=None):
    url = path if path.startswith("http") else API + path
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(url, data=data, method=method)
    req.add_header("Authorization", f"Bearer {TOKEN}")
    req.add_header("Accept", "application/vnd.github+json")
    if data: req.add_header("Content-Type","application/json")
    try:
        with urllib.request.urlopen(req) as r: return r.status, json.loads(r.read() or "null")
    except urllib.error.HTTPError as e: return e.code, json.loads(e.read() or "null")

def milestone_num(train):
    st, ms = api("GET","/milestones?state=all&per_page=100")
    for m in ms or []:
        if m["title"].startswith(train): return m["number"]
    return None

def cmd_list(a):
    st, issues = api("GET","/issues?state=open&per_page=100")
    rows = []
    for i in issues or []:
        if "pull_request" in i: continue
        labels = [l["name"] for l in i["labels"]]
        agent = next((l[7:] for l in labels if l.startswith("agente:")), "-")
        state = next((l[6:] for l in labels if l.startswith("stato:")), "backlog")
        ms = (i.get("milestone") or {}).get("title","")
        if a.mine and agent != a.mine: continue
        if a.train and not ms.startswith(a.train): continue
        rows.append((i["number"], agent, state, ms.split(" · ")[0] if ms else "-", i["title"]))
    for n,ag,stt,tr,t in sorted(rows):
        print(f"#{n:<3} [{ag:<8}] {stt:<9} {tr:<3} {t}")
    print(f"\n{len(rows)} work item aperti · {API.replace('api.github.com/repos','github.com')}/issues")

def cmd_open(a):
    payload = {"title": a.title, "body": (a.body or "") + f"\n\n---\n_Aperto via team.py_",
               "labels": [f"agente:{a.agent}", f"tipo:{a.type}"]}
    mn = milestone_num(a.train) if a.train else None
    if mn: payload["milestone"] = mn
    st, i = api("POST","/issues",payload)
    print(f"#{i['number']} creata → {i['html_url']}" if st==201 else f"ERRORE {st}: {i}")

def cmd_comment(a):
    who = a.as_ or "Regia"
    role = ROSTER.get(who,"")
    prefix = f"**[{who} · {role}]** " if role else f"**[{who}]** "
    st, c = api("POST", f"/issues/{a.issue}/comments", {"body": prefix + a.text})
    print("commento aggiunto" if st==201 else f"ERRORE {st}: {c}")

def set_state(issue, state):
    st, i = api("GET", f"/issues/{issue}")
    labels = [l["name"] for l in i["labels"] if not l["name"].startswith("stato:")]
    if state != "clear": labels.append(f"stato:{state}")
    api("PATCH", f"/issues/{issue}", {"labels": labels})

def cmd_state(a):
    if a.value not in STATES and a.value != "clear":
        sys.exit(f"stato non valido: {a.value} (usa {', '.join(STATES)} | clear)")
    set_state(a.issue, a.value); print(f"#{a.issue} → stato:{a.value}")

def cmd_assign(a):
    st, i = api("GET", f"/issues/{a.issue}")
    labels = [l["name"] for l in i["labels"] if not l["name"].startswith("agente:")]
    labels.append(f"agente:{a.agent}")
    api("PATCH", f"/issues/{a.issue}", {"labels": labels}); print(f"#{a.issue} → agente:{a.agent}")

def cmd_close(a):
    if a.comment: api("POST", f"/issues/{a.issue}/comments", {"body": a.comment})
    set_state(a.issue, "clear")
    st, i = api("PATCH", f"/issues/{a.issue}", {"state":"closed"})
    print(f"#{a.issue} chiusa" if st==200 else f"ERRORE {st}")

p = argparse.ArgumentParser(description="Tracker lavoro agenti Spoome (GitHub Issues)")
sub = p.add_subparsers(dest="cmd", required=True)
s = sub.add_parser("list"); s.add_argument("--mine"); s.add_argument("--train"); s.set_defaults(f=cmd_list)
s = sub.add_parser("open"); s.add_argument("--agent",required=True); s.add_argument("--type",default="feature")
s.add_argument("--train"); s.add_argument("title"); s.add_argument("body",nargs="?",default=""); s.set_defaults(f=cmd_open)
s = sub.add_parser("comment"); s.add_argument("issue"); s.add_argument("text"); s.add_argument("--as",dest="as_"); s.set_defaults(f=cmd_comment)
s = sub.add_parser("state"); s.add_argument("issue"); s.add_argument("value"); s.set_defaults(f=cmd_state)
s = sub.add_parser("assign"); s.add_argument("issue"); s.add_argument("agent"); s.set_defaults(f=cmd_assign)
s = sub.add_parser("close"); s.add_argument("issue"); s.add_argument("--comment"); s.set_defaults(f=cmd_close)
a = p.parse_args(); a.f(a)
