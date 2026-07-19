---
name: mobile-overflow-check
description: Verifica che nessuna pagina di Spoome abbia scroll orizzontale su mobile, misurando l'overflow reale a 320/375/390/430px via Chrome DevTools Protocol (device emulation vera). Vincolo di progetto "mai un pixel fuori posto / mai overflow orizzontale". Trigger: consegna che tocca CSS/layout/frontend, nuovo componente (post, card, header, form), "controlla il mobile", "verifica overflow".
---

# Mobile overflow check (CDP)

Vincolo non negoziabile: **mai scroll orizzontale**, soprattutto mobile.

## Perché CDP e non Chrome normale
Chrome headless normale clampa la finestra a ~500px minimo → un test a 375 sarebbe un **falso-negativo silenzioso**. Serve l'override esplicito `Emulation.setDeviceMetricsOverride`.

## Procedura
1. Avvia Chrome headless (il `*` va quotato in zsh):
   ```
   /Applications/Google\ Chrome.app/Contents/MacOS/Google\ Chrome \
     --headless=new --remote-debugging-port=9222 "--remote-allow-origins=*" &
   ```
2. Script CDP in `$CLAUDE_JOB_DIR/tmp/` (usa `websocket-client` dal `dbvenv`):
   - `Emulation.setDeviceMetricsOverride` → `{width:375, height:812, mobile:true, deviceScaleFactor:3}`
   - `Page.navigate` → URL beta
   - `Runtime.evaluate` → conta gli elementi con `getBoundingClientRect().right > document.documentElement.clientWidth` (restituisci selettore+right dei colpevoli)
   - `Page.captureScreenshot` → prova visiva
3. Ripeti per **320 / 375 / 390 / 430**. Atteso: `over(0)` a ogni viewport.

## Guardrail
- Testa con **contenuto "cattivo" reale**: post con URL lunghissimo, handle lungo, parola non spezzabile — non una pagina vuota.
- Alternativa più semplice quando basta l'ispezione visiva: skill/tool **claude-in-chrome** (estensione, anche per screenshot).
- Se trovi overflow → identifica il colpevole (di solito `width` fissa, `white-space:nowrap`, immagine senza `max-width:100%`, `min-width` su flex item) e delega il fix a `frontend-engineer`.
