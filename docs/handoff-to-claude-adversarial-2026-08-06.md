# Handoff a Claude per a revisió adversarial — 2026-08-06 18:30

Estat capturat: **2026-08-06 17:45 CEST**. Branca: `main`, worktree deliberadament brut i sense commit. S'ha fet una prova reversible en producció amb backup verificat i rollback complet; no s'ha fet cap bump de versió, commit, tag ni release.

## Objectiu de la revisió

Ataca els canvis F1–F8 com si haguessis de donar un **GO/NO-GO** abans d'integrar-los. No arreglis res durant aquesta passada: identifica regressions, casos límit no coberts i discrepàncies entre el codi, les proves, `README.md`, `SPEC.md` i aquest document.

Documents d'entrada:

- `docs/adversarial-review-handoff-2026-08-06.md`: troballes F1–F8 originals.
- `docs/handoff-to-codex-2026-08-06.md`: estat i restriccions rebudes a les 16:15.
- aquest document: resolució aplicada i dubtes residuals.

## Resolució per troballa

### F1 — baseline amb reintents

- La sonda prèvia ara usa `probe_site_repeatedly()`, igual que la posterior.
- Una fallada transitòria seguida d'un `200` ja no desactiva la verificació posterior.
- Si els tres intents de baseline continuen sent `fail` o `unknown`, el bridge pot escriure però conserva un `last_error` explícit que demana comprovació manual.
- Proves noves: `test_a_transient_baseline_failure_does_not_disable_post_write_verification()`, `test_an_unverifiable_baseline_is_reported_if_the_write_continues()` i `test_a_persistently_failing_baseline_is_reported_if_the_write_continues()`.

### F2 — agregació independent de l'ordre

- Política aplicada als tres intents: qualsevol `ok` domina; sense cap `ok`, qualsevol `unknown` produeix `unknown`; només tots els resultats `fail` produeixen `fail` i poden activar rollback.
- Això evita que `error,error,500` i `500,500,error` tinguin decisions diferents.
- Prova nova: `test_mixed_fail_and_unknown_probe_results_are_order_independent()`.

### F3 — canvi aliè durant una sonda correcta

- Després d'una sonda posterior `ok`, es torna a llegir `.htaccess` i es compara byte a byte amb allò escrit.
- Si un tercer l'ha substituït, no s'actualitza el fingerprint com a aplicat: l'estat queda `pending_apply` amb error i el canvi aliè es conserva.
- Prova nova: `test_a_successful_probe_does_not_mark_a_later_foreign_change_as_applied()`.

### F4 — eliminació del fallback in-place

- `write_file_atomically()` ja no té cap camí `file_put_contents($path, ...)` sobre el destí final.
- Si `rename()` falla, s'elimina el temporal i l'operació falla; no es restaura de manera no atòmica.
- Si el fitxer canvia just després del `rename`, el tercer guanya i el bridge informa d'error en lloc de sobreescriure'l.
- Prova invariant nova: `test_atomic_writer_never_writes_in_place_to_the_final_path()`.

### F5 — política d'UID aprovada per l'Àlex

- Decisió explícita: **refusar l'escriptura quan l'UID del temporal no coincideix amb el propietari del `.htaccess` existent**.
- Es continuen restaurant i comprovant mode i grup abans del `rename`.
- `get_file_owner()` és un seam protegit exclusivament per provar aquesta política sense `chown` privilegiat.
- Prova nova: `test_write_is_refused_when_temporary_file_owner_differs()`; també continuen verdes les proves de mode i grup.

### F6 — patrons ancorats que només coincideixen amb buit

- Decisió explícita: admetre `^$` i `\A\z` sense provar contra producció.
- Ja no es rebutja un patró només perquè coincideixi amb la cadena buida. Es rebutja quan coincideix amb tots els subjectes representatius **de la directiva concreta**; URI, user-agent i cookie tenen bateries separades.
- Una passada adversarial posterior ha detectat i corregit el fals negatiu `^/` a `MaxCacheExcludeURI`: amb la bateria barrejada anterior s'admetia perquè no coincidia amb UA/cookies, tot i coincidir amb totes les URI normals.
- Es continuen rebutjant patrons universals no ancorats com `.*`, `a*`, `(foo)?` o una alternativa buida.
- Proves noves: `test_anchored_patterns_that_only_match_empty_input_are_usable()` i `test_a_pattern_universal_for_request_uris_is_rejected_as_an_uri_exclusion()`.
- La documentació ja parla de “universal match”, no de qualsevol match amb buit.

### F7 — catàlegs

- POT regenerat des del PHP actual amb català mantingut com a idioma font (`msgid`); no s'ha fet el gir d'i18n pendent.
- PO català i anglès actualitzats: 0 entrades vigents buides i 0 fuzzy.
- MO recompilats després dels PO.
- `msgcmp`, `msgfmt --check --check-format` i les cerques dels textos indicats al handoff passen.

### F8 — gate de release

- El workflow de tags té ara un job `verify` amb matrix PHP 7.4/8.3.
- Tots dos fan lint; només PHP 8.3 instal·la PHPUnit 11 i executa `composer test`.
- El job `release` declara `needs: verify`, de manera que no construeix ni publica si la verificació falla.
- S'ha corregit també el comentari obsolet que deia que l'updater acceptava el source archive.
- Proves noves a `tests/ReleaseWorkflowTest.php` comproven la distinció 7.4/8.3, la dependència i l'ordre tests-abans-de-publicació.

## Evidència TDD i verificació final

Les proves F1–F6 i F8 es van executar individualment abans del canvi corresponent i van fallar pel motiu esperat: baseline amb 1 crida; decisió dependent de l'últim resultat; `in_sync` després del canvi aliè; presència de l'escriptura in-place; UID diferent acceptat; `^$` rebutjat; i workflow sense tests.

### Proposta 12 — un sol formulari de configuració

- Els cinc booleans configurables que existeixen realment al codi (`auto_sync_enabled`, `auto_apply_htaccess`, `serve_bot_user_agents`, `serve_gzip_variant`, `serve_webp_variant`) comparteixen ara un sol formulari, nonce, `admin_post_wmrb_save_settings`, handler, escriptura d'opció i redirect.
- Els formularis d'ordres (`Run checks`, `Apply`, `Take over`, `Rollback`, etc.) continuen separats expressament: no són toggles ni han de compartir una submissió de configuració.
- `handle_save_settings()` conserva claus alienes de l'array d'opcions i refresca el fingerprint amb serveis nous quan canvia una opció que afecta l'snippet o quan es reactiva l'auto-sync.
- Proves noves a `tests/AdminSettingsTest.php`: submissió única i preservació d'opcions, estructura HTML amb cinc checkboxes i cap action antiga, i refresh amb el fingerprint nou.

Estat final local:

```text
composer test
OK (113 tests, 868 assertions)

vendor/bin/phpunit --group batch2
22 tests, 25 assertions, 17 failures

PHP lint local: OK amb PHP 8.5.9
release.yml i ci.yml: YAML parsejable
msgcmp + msgfmt --check --check-format: OK
build: OK, versió 0.2.3, ZIP de 19 entrades, unzip -t sense errors
git diff --check: OK
```

No hi ha PHP 7.4 instal·lat localment. El lint 7.4 queda pendent de l'execució real del matrix de GitHub; el workflow conserva la mateixa distinció que `ci.yml`.

## Prova real reversible a umatic.cat

Backup verificat abans de tocar res: `/home/umatic/wmrb-test-backups/20260806-172417`. Conté el plugin, `.htaccess`, opcions i estat previs, més `SHA256SUMS`. El candidat provat era la versió 0.2.3, ZIP de 19 entrades, SHA-256 `261769b57dd81b083fab7d790ff9677becf9e8f8a1347ae419a817e241579111`.

Resultat funcional del formulari:

- La pàgina real va mostrar exactament cinc checkboxes dins un sol formulari i un sol botó `Guardar configuració`.
- La submissió auto-sync ON→OFF va redirigir a `wmrb=settings-updated` i va persistir l'opció.
- No es va completar la segona submissió OFF→ON perquè es va aturar la prova davant el canvi de `.htaccess` descrit a continuació.

Troballa amb preocupació:

- Només obrir l'admin, abans de clicar `Apply` o de sotmetre el formulari, `.htaccess` ja havia canviat. L'estat inicial era `pending_apply` amb auto-sync i auto-apply actius.
- L'evidència temporal encaixa amb una actualització de `wp_rocket_settings` durant la càrrega de l'admin: `WMRB_Sync_Manager::handle_rocket_settings_update()` aplica qualsevol `pending_apply` gestionable quan rep aquest hook. No hi ha instrumentació suficient per demostrar quin component va actualitzar l'opció ni quina diferència contenia; tracta aquesta causalitat com una inferència forta, no com un fet provat.
- El bridge va crear backup i el web va continuar responent 200, però la propietat “obrir aquesta pantalla és read-only” no es compleix quan un tercer dispara el hook i ja hi havia drift pendent.
- Es va aturar la prova, destruir la sessió temporal, restaurar el plugin original, `.htaccess`, `wmrb_options` i `wmrb_sync_state`, i revocar només la sessió d'Arc que havia quedat exposada. Les altres sessions d'usuari es van preservar.

Verificació final del rollback:

```text
plugin: 0.2.3 active (original restaurat)
.htaccess: SHA-256 3fb0bec559e8b38d87f9a545640b682bc86e8ad14b146fb6f73e117bde4438b3
.htaccess: mode 0644, uid 1072, gid 1077
wmrb_options: coincidència exacta amb el backup
wmrb_sync_state: coincidència exacta amb el backup (pending_apply)
HTTP públic: 200
HTTP origen amb Host umatic.cat: 200
```

El backup que va crear l'autoaplicació s'ha conservat com a evidència fora del webroot a `/home/umatic/wmrb-test-backups/20260806-172417/unexpected-autoapply-htaccess-backup.bak`. El candidat provat també queda fora del webroot a `plugin-dir-tested`.

## No fet deliberadament

- Cap prova real dels patrons F6 contra Apache de producció; F6 s'ha resolt amb la política conservadora aprovada.
- Cap correcció dels 17 vermells de `batch2`.
- Cap bump de versió, commit, tag, push o publicació.
- Cap gir d'i18n cap a `msgid` anglès.
- Cap reescriptura àmplia dels canvis que ja havia deixat Claude abans del handoff.

## Dubtes i punts que cal atacar especialment

1. **F1 / estat:** quan el baseline no és fiable, el resultat queda `in_sync` però amb `last_error`. Ho considero compatible amb “no silenciosament”, però revisa si semànticament hauria de quedar `pending_apply` malgrat que el fitxer coincideixi amb el fingerprint.
2. **F2 / lattice:** `unknown` domina `fail` si no hi ha cap `ok`, per evitar rollback amb evidència ambigua. Revisa si algun tipus concret de `WP_Error` hauria de classificar-se com a `fail` en lloc d'`unknown`.
3. **F3 / finestra residual:** queda una finestra inevitable entre l'última lectura correcta de `.htaccess` i `update_option()`. No hi ha transacció comuna entre filesystem i opcions de WordPress. Busca si podem reduir-la sense sobreescriure canvis aliens.
4. **F5 / metadades:** es preserven UID (per igualtat), GID i mode POSIX, però no ACL, xattrs ni flags de filesystem. Valora si el refús actual és prou conservador en hostings compartits.
5. **F6 / heurística:** les bateries ara són específiques per directiva, però un conjunt finit de subjectes no és una prova formal que un PCRE sigui universal. Busca falsos negatius que puguin excloure tot el domini real d'una directiva, i falsos positius legítims.
6. **F7 / eina:** el POT s'ha regenerat amb GNU `xgettext` perquè WP-CLI i `wp i18n make-pot` no són disponibles localment. Comprova que no s'hagin perdut metadades específiques de WordPress; els dos únics helpers presents al codi són `__` i `esc_html__`.
7. **F8 / deriva:** `release.yml` duplica part de `ci.yml`. Revisa si això és acceptable o si obre una deriva futura; no s'ha refactoritzat a reusable workflow per mantenir el canvi petit.
8. **Proposta 12 / atomicitat lògica:** comprova que desmarcar checkboxes absents, preservar opcions alienes i refrescar amb instàncies noves sigui correcte. Ataca especialment la construcció d'un `WMRB_Sync_Manager` addicional dins el POST, que registra hooks duplicats durant aquella petició.
9. **Producció / auto-apply sorprenent:** decideix si és acceptable que qualsevol canvi de `wp_rocket_settings` apliqui tot el drift pendent, encara que el canvi sigui aliè o es produeixi només en obrir una pantalla. Busca una política estreta i testejable basada en diferències efectives entre `$old_value` i `$value`, i separa clarament comportament esperat de regressió del formulari consolidat.

## SHA-256 de l'estat revisat

El handoff mateix no s'inclou perquè el seu hash seria autoreferencial.

```text
a2417b9657d844ce44aeab6b895d944d0b3cf79469f4999c31cd98375a1ec371  .github/workflows/release.yml
4ee1ee402f307839c3b924e8cd1087c2f5015f59f8c2c09a3c931572efb2a00f  README.md
3c8c8b783a63614a6b237a621e601ac8d20e024573e6544703f8ccd9be3fceff  SPEC.md
316b5ce96531c2fcde446c11612c118ce8f78ae649664635858b99b7a2b7b1b9  includes/class-wmrb-admin-page.php
963b4b4883c7077c4425df7ab1974f3a3c09677deafbee96e46fb4bf8db825d5  includes/class-wmrb-github-updater.php
da93330bd623a87dbcfda21224298f9b90ec1160333641918edf2245bbda6102  includes/class-wmrb-snippet-service.php
f797ce351d1e72832f61813bb9232451110cfd036dcee002f18bda4fa576ec52  includes/class-wmrb-sync-manager.php
224dbc689c45d8ee40d371a68d199d914fefac04ed630202818104ed3d0d8074  languages/wp-maxcache-rocket-bridge.pot
fee2dfdabc0ebc86d8f84e27c6b27780a5f0758550904441cb97dd52b91518cc  languages/wp-maxcache-rocket-bridge-ca.po
9ae2537a43438ad6353dc8921f5841ee5bfc0ac858c991d59d4b11ebe048605e  languages/wp-maxcache-rocket-bridge-ca.mo
bd782413b59255487fc9f00a45c974d38f77057ef352c4347e412cf9d1be04c2  languages/wp-maxcache-rocket-bridge-en_US.po
53656aefcb5b1dfadfe96a1b02f18446c6a55fe6ccb16088e6c633a6056ee720  languages/wp-maxcache-rocket-bridge-en_US.mo
f802688554e9ff1b57e4d4d472b90b9788c4be9fdb7c265662edc4d3619909c4  tests/ReviewFindingsTest.php
26896c6a5aeae171fa0d6b6d413c247b3f50c3f6957351904c8214cff7bae8d9  tests/ReleaseWorkflowTest.php
655dd7ba91444c6859d1855457e6edc5abef35e1c89f09f6aa78dac871e63976  tests/AdminSettingsTest.php
38b283a91a9370fc8e30d71e8e6281bd8c3ccc4e6cd916b9040f946dc4d5e33a  tests/bootstrap.php
16d451340f9db41e7ff7e3122a7e3879942c4e2f2d65a5e20a968727b22e8bb4  tests/wp-stubs.php
```

## Format de retorn esperat

1. `GO` o `NO-GO`.
2. Troballes ordenades per severitat, amb fitxer i línia.
3. Casos límit o proves que falten.
4. Confirmació separada que `batch2` continua sent deute conegut i no regressió d'aquest lot.
