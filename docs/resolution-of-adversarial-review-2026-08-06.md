# Resolució de la revisió adversarial F1–F8 — 2026-08-06

Document d'entrada: `docs/adversarial-review-of-f1-f8-2026-08-06.md`.

Estat: implementació local completada sobre `main`, worktree deliberadament brut, sense commit, tag, release ni desplegament. Cal una nova passada adversarial abans de substituir el `NO-GO` de Claude per un `GO`.

S'ha intentat aquesta nova passada amb Claude Opus en mode `plan` i només lectura, però el procés no ha retornat cap resultat i s'ha aturat després de diversos minuts amb `Execution error`. Per tant, el veredicte independent continua pendent; no s'ha substituït per una revisió pròpia presentada com si fos de Claude.

## Resolucions

### P1 — exclusions universals sobre valors no buits

- `matches_every_representative_subject()` elimina els subjectes buits abans d'avaluar universalitat.
- Això rebutja `.`, `.+`, `\S` i `[^x]+` quan cobreixen tots els valors reals d'una directiva.
- `^$` i `\A\z` continuen admesos perquè no coincideixen amb cap subjecte no buit.
- La regla continua sent específica per URI, user-agent i cookie.
- Reproductor TDD: `test_patterns_universal_for_non_empty_inputs_are_rejected_for_every_directive()` va fallar amb `ua_synced=5`; després del canvi queden sincronitzats només els patrons de buit.

### P2 — invariant d'escriptura atòmica

- S'ha eliminat la prova grep que només protegia el nom `$path`.
- `rename()` queda darrere del seam protegit `rename_file()`, un límit de filesystem substituïble en proves.
- `test_a_failed_atomic_rename_never_writes_in_place_to_htaccess()` força una fallada real del rename a través de `apply_snippet_to_htaccess()` i comprova quatre propietats observables: `.htaccess` intacte, estat `pending_apply`, error explícit i temporal eliminat.
- El reproductor va fallar abans del seam perquè el rename real tenia èxit i el fitxer final canviava.

### P2 — estat `applied_unverified`

- Una escriptura amb baseline no fiable o verificació posterior inconcloent ja no queda `in_sync`.
- El nou estat conserva `current_hash === last_applied_hash`, perquè el contingut esperat sí que s'ha escrit, però manté `last_error` i no presenta verificació verda.
- `refresh_state_from_current_fingerprint()` torna a executar la sonda quan troba aquest estat. Només un resultat `ok` el converteix en `in_sync` i neteja l'error; `fail` o `unknown` el mantenen `applied_unverified`.
- No es fa rollback tardà: sense baseline contemporani no es pot atribuir una fallada posterior a `.htaccess`.
- Reproductor TDD: `test_an_applied_unverified_state_is_retried_before_becoming_in_sync()` va demostrar que abans del canvi refresh no feia cap nova petició.

### P2 — auto-apply només davant canvi efectiu

- `handle_rocket_settings_update()` ja no descarta `$old_value` i `$value`.
- Calcula el snippet amb cadascun dels dos valors mitjançant una instància de `WMRB_Snippet_Service` amb settings explícits.
- Si els fingerprints coincideixen, retorna abans de refrescar estat, inspeccionar o escriure `.htaccess`.
- Si difereixen, conserva la política anterior d'auto-sync/auto-apply.
- `test_an_irrelevant_rocket_settings_update_does_not_apply_existing_drift()` reprodueix el cas de producció amb drift pendent i demostra zero escriptures i zero sondes per un canvi de `minify_css`.
- `test_a_rocket_settings_update_that_changes_the_snippet_can_auto_apply()` fixa el cas positiu amb una nova exclusió URI.

### P3 — hooks duplicats al POST

- `handle_save_settings()` reutilitza el `WMRB_Sync_Manager` injectat.
- `refresh_state_for_options()` actualitza opcions i snippet service dins la mateixa instància, sense tornar a registrar `init`, `admin_init` ni `update_option_wp_rocket_settings`.
- `test_saving_settings_does_not_register_a_second_sync_manager()` comprova que el POST manté una sola subscripció al hook de WP Rocket.

## Verificació final

```text
composer test
OK (118 tests, 887 assertions)

vendor/bin/phpunit --group batch2
22 tests, 25 assertions, 17 failures

PHP lint: OK (PHP 8.5.9)
ci.yml + release.yml: YAML parsejable
msgcmp + msgfmt --check --check-format: OK
git diff --check: OK
build: 0.2.3, 19 entrades, unzip -t OK
ZIP SHA-256: 626565d3495beef511bfe6ce077cc78e69454bad8467984cd0a8ca9924fcc8fc
```

Les 17 fallades `batch2` són exactament les mateixes especificacions pendents; no han augmentat.

## Punts per a la següent passada adversarial

1. Atacar falsos positius i falsos negatius de la universalitat sobre subjectes no buits; continua sent una heurística finita, no una demostració formal de PCRE.
2. Confirmar que el constructor opcional de `WMRB_Snippet_Service` no pot confondre settings explícits buits amb lectura live.
3. Atacar la transició `applied_unverified` → `in_sync`, especialment reintents repetits, cost de tres sondes i persistència de `last_error` quan no hi ha `ok`.
4. Confirmar que `last_applied_hash=current_hash` és la semàntica correcta per un fitxer escrit però no verificat.
5. Atacar la nova seam `rename_file()` i comprovar que cap altre camí pot escriure el destí final in-place.
6. Confirmar que reutilitzar el manager al POST actualitza tant les opcions com el snippet sense conservar dependències obsoletes.

## SHA-256 de la fotografia

```text
86220c659e84ede442305b57fb868d1b32ea8f31958e5cca30e06797eafde71b  includes/class-wmrb-snippet-service.php
1afbfb5522284f693ffe7c0e782390627fac03b34605c6e412fbd2ba743f8d58  includes/class-wmrb-sync-manager.php
0c90b69ac210223131901983ba9b3bfd0fd01e98a02e823c6eb04f719dbe0a63  includes/class-wmrb-admin-page.php
9691144875fca2dca35988411a98749082b579dee74dc3ee9848bf20f021c926  tests/ReviewFindingsTest.php
e868ee115ae41cdfb4c6610c46e377712015e1d2a20a9bbcadcbcee33f76762b  tests/AdminSettingsTest.php
5f6144a25ab79372c2e88981189caa359ab4b26ba8313d94db2079cbe573d50c  tests/SyncManagerTest.php
ee0b031a4f48737b0b5ddada39b891aef1d6c4da1b8fa4637692fe6376b848d8  tests/wp-stubs.php
92655c9060dac4882b297c5446c6b292dae7ff8a2982df3013b9d4d8072ae1dc  README.md
7eb9a1c9d1666daa0e53732fed434a6514f9a02ed1d2da456ea04d3e6f584eb2  SPEC.md
8228cba03e5c9c18659e3e607c86fec2cb1751ad0dc449fc68aafec7cab043f7  languages/wp-maxcache-rocket-bridge.pot
2db5252de912e453ce3b5fcb7f6c51c95bee8fc65d794bd81f6565b1c629bf7b  languages/wp-maxcache-rocket-bridge-ca.po
bed1a1861a8a51949c4ebe4cb23fd06e6e6f03461df699e35f8b61a60fc32f53  languages/wp-maxcache-rocket-bridge-ca.mo
a1f2143c940c74a88d6e7e855ec2247600b737fed4928f3d2695becfa822bb93  languages/wp-maxcache-rocket-bridge-en_US.po
40fd344b4cc9ff70e94eab2c733c95dc88d7e063bf35dd685acbfa01000f4687  languages/wp-maxcache-rocket-bridge-en_US.mo
```
