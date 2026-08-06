# Verificació de les correccions a la revisió adversarial — 2026-08-06 19:00

Revisió de `docs/resolution-of-adversarial-review-2026-08-06.md`, que respon a
`docs/adversarial-review-of-f1-f8-2026-08-06.md`.

Estat revisat: worktree brut, sense commit, versió 0.2.3.

## Veredicte

**GO.**

Les cinc troballes de la passada anterior estan resoltes. Dues ho estan millor
del que demanava la revisió. Queda una troballa nova de severitat baixa, no
bloquejant.

Estat de verificació:

```text
composer test: OK (118 tests, 887 assertions)
phpunit --group batch2: 22 tests, 17 failures  (idèntic, cap regressió)
PHP lint: OK
build.sh: OK, versió 0.2.3, ZIP de 19 entrades
git diff --check: OK
```

---

## Verificació per troballa

### P1 — patrons universals admesos com a exclusions ✅ RESOLT

Reproductor de la revisió anterior, tornat a executar sense modificar:

```text
patró         URI       UA        COOKIE
.+            rebutjat  rebutjat  rebutjat
.             rebutjat  rebutjat  rebutjat
\S            rebutjat  rebutjat  rebutjat
[^\x00]+      rebutjat  rebutjat  rebutjat
.*            rebutjat  rebutjat  rebutjat
^$            ADMES     ADMES     ADMES
\A\z          ADMES     ADMES     ADMES
/checkout(.*) ADMES     ADMES     ADMES
```

Els patrons universals cauen a les tres directives i la decisió aprovada sobre
`^$` i `\A\z` es conserva intacta.

### P2a — la invariant de F4 protegia un nom de variable ✅ RESOLT, per sobre del demanat

La revisió proposava ampliar el grep perquè cobrís `$htaccess_path`. La
resolució ha anat més enllà i ha substituït la comprovació estàtica per una
**prova de comportament**: `WMRB_Rename_Failure_Sync_Manager` força el fallo de
`rename()` i la prova verifica que el `.htaccess` no es toca, que l'estat queda
`pending_apply`, que hi ha error i que el temporal es neteja
(`tests/ReviewFindingsTest.php:283`).

Això protegeix la propietat en lloc d'un identificador, que era el fons de
l'objecció. Millor solució que la suggerida.

### P2b — l'estat no podia expressar «escrit però no verificat» ✅ RESOLT, per sobre del demanat

La revisió demanava un tercer estat. La resolució n'afegeix un
(`applied_unverified`) **i** hi associa re-verificació: en aquest estat,
`refresh_state_from_current_fingerprint()` torna a sondar i només neteja
l'avís si el web respon (`includes/class-wmrb-sync-manager.php:210`).

Comportament comprovat:

```text
1r apply (web trencat)  -> applied_unverified
refresh (web trencat)   -> applied_unverified    persisteix
refresh (web sa)        -> in_sync               s'auto-neteja
```

Respon millor que la proposta original de la revisió. El problema real no era
reaplicar — l'escriptura ja hi és i coincideix amb el fingerprint — sinó
re-verificar. La resolució ho va identificar correctament.

### P2c — obrir la pantalla aplicava el drift pendent ✅ RESOLT

`handle_rocket_settings_update()` deriva ara els fingerprints de `$old_value` i
de `$value` i surt aviat si coincideixen
(`includes/class-wmrb-sync-manager.php`). És exactament la política que
responia al comportament observat en producció, i és testejable sense dependre
de quin component va escriure l'opció.

### P3 — registre de hooks duplicat dins del POST ✅ RESOLT

Ja no es construeix cap `WMRB_Sync_Manager` addicional a
`includes/class-wmrb-admin-page.php`.

---

## Troballa nova

### P3 — la re-verificació bloqueja el render de l'administració

**Fitxer**: `includes/class-wmrb-sync-manager.php:210`, consumit des de
`includes/class-wmrb-admin-page.php:185`.

Amb l'estat `applied_unverified`, `refresh_state_from_current_fingerprint()`
consumeix **3 sondes HTTP** (verificat comptant les crides). `render_page()` el
crida a cada càrrega de pàgina quan l'auto-sync és actiu.

Amb `timeout => 10` per sonda més els retards entre intents, una web trencada o
lenta pot deixar la pantalla d'administració penjada al voltant de 30 segons
—precisament quan l'usuari hi va a mirar què passa.

És un efecte lateral de la solució de P2b, que per la resta és bona.

**Suggeriment**: limitar la re-verificació a un sol intent en el camí de render,
o posar-hi un throttle per transient perquè no es repeteixi a cada càrrega.
No bloquejant.

---

## Residus de P1 que no compten com a defecte

- `\w` continua admès com a exclusió d'URI: casa amb tots els subjectes
  representatius excepte `/`, de manera que exclouria tot el trànsit menys la
  portada. El paper que abans feia la cadena buida ara el fa `/`.
- `/` continua admès com a exclusió de cookie. És correcte: no és universal per
  a capçaleres de cookie.

El primer és la limitació que la resolució ja declarava — una bateria finita no
és una prova formal d'universalitat — i la superfície s'ha reduït molt: `.+`
era «tot», `\w` és «tot menys una URL» i cal escriure'l expressament. Queda com
a nota, no com a troballa.

---

## Confirmació sobre `batch2`

`vendor/bin/phpunit --group batch2` continua donant **22 proves i 17 fallades**,
xifra idèntica a la de totes les passades anteriors. És deute conegut i
planificat, no regressió d'aquest lot ni de l'anterior.

## Què queda obert abans de publicar

Res d'aquesta cadena de revisions. Els punts pendents són els que ja estaven
catalogats i no formen part d'aquest treball:

- P1 #4, #6, #7 del catàleg original (uninstall, `in_sync` que no llegeix el
  fitxer, escriptura d'opció per render) — amb especificacions a `batch2`
- fidelitat amb AccelerateWP: `MaxCacheOptions` derivat, dynamic i mandatory
  cookies, accessors de WP Rocket — amb especificacions a `batch2`
- P3 #11 (opcions mortes) i #14 (quick test contra origen)
- P3 #13: gir d'i18n cap a `msgid` en anglès, que segueix pendent de fer-se
  després del merge
- bump de versió, commit i release
