# Font TTF per l'og:image (M5)

Il renderer della card social (`src/Domain/Og/OgImageRenderer.php`) usa **GD + FreeType**, che richiede
font **TTF/OTF** (GD non sa leggere i WOFF2 che serviamo al browser in `public/assets/vendor/fonts/`).

Per attivare la resa **ricca** (tipografia on-brand) drop questi tre file in questa cartella:

| File atteso                     | Famiglia (già usata come WOFF2) | Peso |
|--------------------------------|----------------------------------|------|
| `BarlowCondensed-Bold.ttf`     | Barlow Condensed                 | 700  |
| `Barlow-SemiBold.ttf`          | Barlow                           | 600  |
| `Barlow-Medium.ttf`            | Barlow                           | 500  |

- **Licenza:** Barlow è **SIL Open Font License 1.1** → redistribuibile. Sono gli stessi caratteri già in
  uso sul sito, solo nel formato TTF che GD sa rasterizzare.
- **Fonte:** Google Fonts (repo `googlefonts/barlow`) o pacchetto ufficiale. Nessun binario è committato qui
  di proposito: vanno aggiunti da chi ha gli strumenti/licenza a portata, poi deployati.
- **Vanno deployati** insieme al codice: sono letti a runtime sul server (percorso `resources/fonts/`, fuori
  dalla docroot `public/`).

## Degradazione (nessun font TTF presente o FreeType assente)
Il renderer **non si rompe mai**: senza TTF/FreeType disegna comunque una card on-brand (colori, anello di
verifica, badge, avatar/iniziali) usando il font bitmap integrato di GD ingrandito — più sobria ma leggibile.
Nessuna anteprima rotta viene mai condivisa.

## Dopo aver aggiunto i font
Incrementa `OgCardData::RENDER_VERSION` (di +1): cambia tutte le firme `?v=` degli og:image → i crawler
rigenerano le anteprime già cachate passando dalla resa degradata a quella ricca.
