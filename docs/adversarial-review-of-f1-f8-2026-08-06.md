# Revisió adversarial de F1–F8 — 2026-08-06 18:30

Revisió de `docs/handoff-to-claude-adversarial-2026-08-06.md`.
Estat revisat: worktree brut, sense commit, versió 0.2.3.

## Veredicte

**NO-GO.**

F1, F2, F3, F4, F5, F7 i F8 estan correctament resolts i verificats. El bloqueig
és **F6**: la correcció ha obert un forat de la mateixa classe que tancava.

Baseline confirmat abans de revisar:

```text
SHA-256 dels fitxers centrals: coincideixen amb el handoff
composer test: OK (113 tests, 868 assertions)
phpunit --group batch2: 22 tests, 17 failures
```

Verificacions positives fetes explícitament:

- **F3** està ben resolt: `verify_write()` rellegeix i compara byte a byte
  també després d'una sonda `ok` (`class-wmrb-sync-manager.php`, branca
  `'ok' === $after`), no només abans del rollback.
- **F4** està ben resolt: les úniques escriptures a `class-wmrb-sync-manager.php`
  són al temporal (:604) i al fitxer de backup (:788). No queda cap escriptura
  in-place sobre el `.htaccess` final.

---

## Troballes

### P1 — `.+` i `.` s'admeten com a exclusions de UA i de cookie

**Fitxer**: `includes/class-wmrb-snippet-service.php:400`
(`matches_every_representative_subject()`), bateria a `:427`.

Reproductor:

```bash
php -r '
require "tests/bootstrap.php";
$o = WMRB_Plugin::default_options();
foreach ([".+", ".", "/", ".*"] as $p) {
  WMRB_Test_State::reset();
  WMRB_Test_State::$options["wp_rocket_settings"] = ["cache_reject_ua" => [$p]];
  $s = new WMRB_Snippet_Service($o);
  printf("%-6s ua_synced=%d\n", $p, $s->get_sync_summary()["ua_synced"]);
}'
```

Resultat observat:

```text
.+     ua_synced=1     <- admès
.      ua_synced=1     <- admès
/      ua_synced=1     <- admès
.*     ua_synced=0     <- rebutjat
```

`MaxCacheExcludeUA ".+"` exclou tota petició que porti User-Agent, és a dir tot
el trànsit real. Segons la taula empírica mesurada contra Apache el 2026-08-06,
això **desactiva la cache en silenci i no escriu res a cap log**. És exactament
el mode de fallada que F6 havia de prevenir, i el germà directe de `.*`, que sí
que es rebutja.

**Causa**: és estructural, no un cas de prova que falti. La bateria de UA és
`['', 'Mozilla/5.0', 'Googlebot/2.1', 'curl/8.0']`. La cadena buida hi és perquè
`^$` sigui admès, però la regla és *«coincideix amb tots els subjectes»*, de
manera que qualsevol patró que casi amb tot el no buit queda immunitzat
precisament per aquell `''`. Els dos objectius de F6 es contradiuen sota la
regla actual. `/` cau igual: casa amb els tres UA de mostra i falla només contra
el subjecte buit.

Afegir `.+` a la bateria de proves no ho resol; caldria enumerar `.`, `\w`,
`\S`, `[^x]+` i companyia.

**Correcció suggerida**: avaluar la universalitat **només sobre els subjectes no
buits**. Un patró que casa amb tots els subjectes no buits és universal, casi
amb el buit o no. Comprovació de la regla proposada:

| Patró | Casa amb tots els no buits | Decisió |
| --- | --- | --- |
| `.+`, `.`, `/` (UA) | sí | rebutjat |
| `.*`, `a*`, `(foo)?` | sí | rebutjat |
| `^$`, `\A\z` | no (cap) | admès |
| `/checkout(.*)` | no (alguns) | admès |

Això preserva la decisió aprovada sobre `^$` sense l'efecte lateral.

---

### P2 — La invariant de F4 protegeix un nom de variable, no la propietat

**Fitxer**: `tests/ReviewFindingsTest.php:275`

El guardià és un grep del codi font amb
`/file_put_contents\s*\(\s*\$path\s*,/`. La variable que designa el `.htaccess`
a `write_managed_block()` i `rollback_last_backup()` és `$htaccess_path`, no
`$path`. Una reintroducció en aquests dos mètodes — els llocs més probables —
passaria la prova sense avisar.

El comportament actual és correcte; el que és feble és la xarxa que l'ha de
mantenir.

---

### P2 — L'estat no pot expressar «escrit però no verificat»

**Fitxer**: `includes/class-wmrb-sync-manager.php`, `verify_write()` /
`write_managed_block()`

Amb baseline no fiable el resultat és `status=in_sync`, canvi escrit i avís únic
a `last_error`.

Respon al vostre dubte 1, i la resposta és que el problema no és l'etiqueta sinó
que falta un estat. `handle_rocket_settings_update()` només auto-aplica quan
l'estat és `pending_apply`; per tant `in_sync` no vol dir només «aplicat», vol
dir també **«no es reintentarà»**. Un canvi que podria haver trencat el web queda
amb l'indicador principal en verd i tota la reserva en un camp de text que
comparteix amb errors d'altra naturalesa.

Marcar-ho `pending_apply` seria enganyós en l'altre sentit (el fitxer sí que
coincideix amb el fingerprint). Cal un tercer valor, per exemple
`applied_unverified`, amb política pròpia de reintent.

---

### P2 — Obrir la pantalla d'admin aplica el drift pendent

**Fitxer**: `includes/class-wmrb-sync-manager.php:70`

```php
public function handle_rocket_settings_update( $old_value, $value, $option ) {
    unset( $old_value, $value, $option );
```

Confirmo la troballa de producció del vostre punt 9, i la causa és aquesta
línia: el handler descarta els dos valors que li permetrien decidir si el canvi
és rellevant. Qualsevol escriptura a `wp_rocket_settings`, encara que no alteri
res que afecti l'snippet, dispara l'aplicació de tot el drift acumulat.

La guarda `$updated === $original` de `write_managed_block()` protegeix del cas
en què l'snippet no canvia, no d'aquest: a umatic.cat hi havia drift pendent
real i l'escriptura era legítima en contingut, però no en moment ni en
consentiment.

La política que apuntàveu és la correcta: derivar el fingerprint de `$old_value`
i de `$value` i actuar només si difereixen. Això fa la condició testejable sense
dependre de qui va escriure l'opció.

---

### P3 — Registre de hooks duplicat dins del POST

**Fitxer**: `includes/class-wmrb-admin-page.php:141`

El `WMRB_Sync_Manager` addicional que es construeix a `handle_save_settings()`
torna a registrar `init`, `admin_init` i `update_option_wp_rocket_settings`.

En aquesta petició és inofensiu: `init` i `admin_init` ja han disparat quan
s'executa `admin_post_*`, i l'opció que s'escriu és `wmrb_options`, no
`wp_rocket_settings`. Però la inocuïtat depèn d'un ordre d'execució que cap
prova fixa. Refrescar amb els serveis ja injectats evita el problema sense cost.

---

### Nota d'abast — Proposta 12

El handoff de les 16:15 llistava P3 #12 sota «deute conegut que queda obert (no
és feina teva ara)». Si l'Àlex la va demanar explícitament, cap objecció de
fons; deixo constància només perquè barreja un canvi d'interfície amb un lot de
correccions de seguretat, i això fa el conjunt més difícil de revisar i
d'esbandir per separat si calgués revertir-ne una part.

---

## Casos límit i proves que falten

1. **Universalitat per directiva**: `.`, `.+`, `\S`, `[^x]+` a URI, UA i cookie.
   Escriure-les després del canvi estructural, no com a llista de casos.
2. **Invariant de F4**: convertir-la en prova de comportament, o com a mínim
   cobrir `$htaccess_path` i qualsevol identificador que designi el destí final.
3. **F1**: que un `in_sync` amb baseline no fiable no impedeixi un reintent
   posterior.
4. **Auto-apply**: que una escriptura de `wp_rocket_settings` sense canvi
   efectiu del fingerprint **no** toqui `.htaccess`. És la prova que hauria
   detectat el comportament observat a umatic.cat.

## Dubtes vostres que no considero bloquejants

- **2 (lattice `unknown` domina `fail`)**: defensable. Preferir no fer rollback
  amb evidència ambigua és coherent amb la resta del disseny.
- **3 (finestra residual de F3)**: no hi ha transacció entre filesystem i opcions
  de WordPress. La finestra és inherent; documentar-la és suficient.
- **7 (duplicació entre `ci.yml` i `release.yml`)**: acceptable mentre siguin
  petits. Refactoritzar a reusable workflow ara afegiria risc sense benefici.
- **4 (ACL/xattrs)** i **6 (xgettext en lloc de WP-CLI)**: correctes tal com
  estan, amb la limitació documentada.

## Confirmació sobre `batch2`

`vendor/bin/phpunit --group batch2` dona **22 proves i 17 fallades**, xifra
idèntica a la d'abans d'aquest lot. És **deute conegut i planificat, no una
regressió d'aquest treball**. El verd de `composer test` no l'inclou i no s'ha
de presentar com si l'inclogués.

## Definició de fet per tancar aquesta passada

- La universalitat s'avalua sobre subjectes no buits i `.+`, `.` i `/` (com a UA)
  queden rebutjats mentre `^$` continua admès.
- La invariant de F4 cobreix el destí final sigui quin sigui el nom de variable.
- Existeix un estat per a «aplicat sense verificar», o una justificació escrita
  de per què `in_sync` és correcte tot i bloquejar el reintent.
- `handle_rocket_settings_update()` decideix a partir de `$old_value` i `$value`.
- `composer test` verd i `batch2` en 17 fallades, ni més ni menys.
