# Handoff a Codex — 2026-08-06, 16:15 CEST

Continuació del treball descrit a `docs/adversarial-review-handoff-2026-08-06.md`.
Aquest document assumeix que has llegit aquell; no en repeteix les troballes.

**Estat**: NO-GO per publicar. F1–F8 obertes. Versió sense bump (0.2.3), res commitejat.

**Pla acordat amb l'Àlex**: tu apliques F1–F8; a les 18:30 (reinici de sessió)
Claude fa la revisió adversarial de la teva feina.

---

## 1. Confirmació de la revisió

He reproduït els vuit punts abans d'escriure això. **Tots es confirmen.** No cal
que els tornis a validar; ves directe a la correcció.

```text
F1  calls=1; status=in_sync; changed=yes; error=''      <- baseline single-shot
F2  error,error,500 => pending_apply / 500,500,error => in_sync
F3  status=in_sync; file="# foreign\n"                   <- carrera amb sonda ok
F4  class-wmrb-sync-manager.php:634  file_put_contents( $path, $restore_on_failure )
F6  ^$ rebutjat com si fos universal
F7  0 coincidències dels textos nous al POT
```

F4 és el més greu i és culpa d'una afirmació meva: el docblock de
`write_file_atomically()` diu que no hi ha fallback in-place, i el README i el
SPEC ho prometen. La línia 634 el desmenteix. Quan el corregeixis, verifica que
la documentació torna a dir la veritat, no només el codi.

---

## 2. Convencions establertes en aquesta sessió

Respecta-les o digues explícitament que les canvies.

**Test-first.** Cada correcció ha d'anar precedida d'una prova que falli contra
el codi actual. S'ha verificat sistemàticament fent `git stash push includes/` i
comprovant que les proves noves passen a vermell.

**Infraestructura de proves** (`tests/`, sense WordPress):
- `tests/wp-stubs.php` — stubs de l'API de WP. `WMRB_Test_State::reset()` entre proves.
- `WMRB_Test_State::queue_response($code, $body = null)` — cua de respostes HTTP.
  `'error'` produeix un `WP_Error`. Amb `$body` a null, una petició de sonda rep
  automàticament el token correcte.
- `WMRB_Test_State::$on_remote_get` — callable que s'executa a cada
  `wp_remote_get()`. És com se simulen les carreres.
- `WMRB_Test_State::$now` + `advance_clock()` — rellotge controlable. Existeix
  perquè una prova passava per casualitat en caure dues crides al mateix segon.
- `WMRB_Test_State::writes($option)` — comptador d'escriptures per opció.
- Filtre `wmrb_probe_retry_delay_ms` a 0 al `setUp()` per no pagar els retards.

**Grup `batch2`** (`tests/Batch2*.php`): especificacions executables de feina
**planificada i no implementada**. 22 proves, 17 en vermell **a propòsit**.
Estan excloses de la suite per defecte via `phpunit.xml.dist`.
**No les arreglis.** No són regressions.

**Build per allowlist** (`build.sh`): el ZIP es munta des d'una llista del que
ha d'entrar, no excloent el que sabem avui. Si afegeixes fitxers nous al repo,
no cal tocar res; si afegeixes fitxers nous *al plugin*, afegeix-los a `ALLOW`.

**Cap escriptura no atòmica sobre `.htaccess`.** És una invariant del disseny,
no una preferència. Vegeu la taula de la secció 3.

---

## 3. Veritat empírica sobre Apache + mod_maxcache

Mesurat el 2026-08-06 contra un host CloudLinux real amb `mod_maxcache` actiu,
escrivint cada condició al `.htaccess` i demanant l'origen. **No ho tornis a
mesurar** (implica provocar 500 en un web viu) i no contradiguis aquesta taula
sense evidència nova.

| Condició | Resultat | Implicació |
| --- | --- | --- |
| regex d'exclusió que no compila | `200`, servit estàtic | **No és fatal.** Les exclusions es perden en silenci |
| regex que fa match amb la cadena buida | `200`, servit per PHP | Cache desactivada en silenci |
| directiva desconeguda dins del bloc | **`500`** | Fatal |
| `</IfModule>` orfe | **`500`** | Fatal |
| fitxer truncat a mitja secció | `200`, servit per PHP | Cache morta |

**Cap dels casos silenciosos escriu res a l'`error_log`.** Aquesta és la raó de
fons per validar dins del plugin: el servidor no es queixa mai.

Conseqüència per prioritzar: els modes fatals són **estructurals** (noms de
directiva, etiquetes desbalancejades, truncament), no de regex. Per això F4
(escriptura in-place que pot truncar) pesa més que F6 (falsos positius de la
validació regex).

També mesurat: el bloc real que genera AccelerateWP (`clsop`) és **pla**, sense
`IfModule` imbricat. El cas imbricat existeix però ve de configuracions editades
a mà, no d'AccelerateWP.

---

## 4. Notes per troballa

Només afegeixo el que no és al document de revisió.

**F1 + F2** — Fes-les juntes, com diu la revisió. La taula d'agregació que
proposa (`un ok => ok`, `tots fail => fail`, altrament `unknown`) em sembla
correcta. Compte amb un detall: si canvies la política, les proves
`test_a_transient_error_is_retried_before_rolling_back` i
`test_a_persistent_error_still_rolls_back` (a `ReviewFindingsTest`) encuen
respostes assumint 3 intents. Actualitza-les conscientment, no per fer-les
passar.

**F3** — La comprovació post-`ok` és simètrica de la que ja existeix abans del
rollback. Val la pena extreure-la a un sol lloc en comptes de duplicar-la.

**F4** — L'opció 2 de la revisió (no restaurar i retornar error crític) és més
senzilla i més segura que muntar un segon temporal. La discrepància post-`rename`
només pot passar si un tercer ha escrit entremig; en aquest cas sobreescriure és
precisament el que no volem. Recomano l'opció 2, però és decisió teva.
Afegeix la prova estàtica que demana la revisió: cap `file_put_contents` amb
`$htaccess_path` com a destí.

**F5** — Necessita decisió de producte, no només codi. Vegeu secció 5.

**F6** — Necessita confirmar la semàntica de `MaxCacheExcludeUA` contra
`mod_maxcache` real. Vegeu secció 5. **No facis proves contra producció.**
Mentre no hi hagi resposta, una opció defensable és restringir la regla actual
als patrons **no ancorats** (rebutjar `.*`, `a*`, `(foo)?`; admetre `^$`, `\A\z`),
que resol el fals positiu sense perdre la protecció real.

**F7** — Els textos font són en **català** (msgid en català, anglès com a
traducció a `en_US.po`). És al revés de la convenció de WP i està identificat
com a deute (P3 #13), però **no ho canviïs ara**: tocaria tots els fitxers.
Regenera els catàlegs mantenint el català com a idioma font.

**F8** — Correcte. Nota que `ci.yml` fa lint a PHP 7.4 i 8.3 però només executa
PHPUnit a 8.3 (PHPUnit 11 necessita 8.2+). Mantén aquesta distinció si
refactoritzes a workflow reutilitzable.

---

## 5. Decisions que necessiten l'Àlex

No les resolguis pel teu compte; deixa-les marcades.

1. **F5 — política d'UID/ACL.** Refusar l'operació quan l'UID no coincideix
   pot deixar el plugin inoperant en hostings on PHP corre com un usuari
   diferent del propietari del `.htaccess`. Cal decidir si es refusa o s'avisa.
   Context: ja hi ha una decisió equivalent presa (refusar quan el directori no
   és escrivible), o sigui que refusar seria coherent.

2. **F6 — semàntica de `MaxCacheExcludeUA` amb `^$`.** Per respondre-ho cal
   provar-ho contra un host amb `mod_maxcache`. Hi ha accés (l'Àlex el pot
   proporcionar) però qualsevol prova implica escriure al `.htaccess` d'un web
   real i s'ha de demanar. Alternativa sense risc: la restricció a patrons no
   ancorats descrita a F6.

---

## 6. Fora d'abast

- **No toquis servidors.** Hi ha accés SSH a hosts de producció des d'aquesta
  màquina. Qualsevol prova contra un web viu s'ha de demanar explícitament.
- **No arreglis `batch2`.**
- **No facis bump de versió** ni publiquis release.
- **No reintrodueixis noms de clients.** `SPEC.md` s'acaba d'anonimitzar a
  `site A/B/C` deliberadament. Segueixen a l'historial de git; és conegut i
  acceptat.
- **No facis el gir d'i18n** (msgid a anglès). És P3 #13, després del merge.

## 7. Deute conegut que queda obert (no és feina teva ara)

Per si topes amb alguna cosa i et preguntes si ja està vista:

- P1 #4: desinstal·lar deixa el bloc MaxCache viu al `.htaccess` (spec a `batch2`)
- P1 #6: `in_sync` no llegeix mai el `.htaccess` (spec a `batch2`)
- P1 #7: `update_option` a cada render de la pàgina d'admin (spec a `batch2`)
- P3 #11: `bridge_enabled` documentat però no llegit enlloc; `debug_mode` es
  mostra a la UI sense cap manera d'activar-lo
- P3 #12: vuit formularis per vuit toggles, ~150 línies duplicades
- P3 #14: el quick test usa la URL pública, no l'origen
- Fidelitat amb AccelerateWP: `MaxCacheOptions` hardcodejat (hauria de derivar de
  `cache_mobile` / `do_caching_mobile_files` / filtre de tablet),
  `MaxCacheDynamicCookies` i `MaxCacheMandatoryCookies` no implementades, i el
  bridge llegeix `wp_rocket_settings` en cru en comptes dels accessors de WP
  Rocket — cosa que li impedeix veure res afegit per filtres. Tot això té
  especificacions a `batch2`.

---

## 8. Verificació abans de tornar-ho

A més de la matriu del document de revisió:

```bash
composer test                        # ha de ser verd
vendor/bin/phpunit --group batch2    # ha de seguir en 17 vermells, ni més ni menys
```

Per a cada troballa corregida, deixa constància de:

1. la prova nova que falla contra el codi anterior (`git stash push includes/`);
2. la sortida del reproductor corresponent del document de revisió, ara correcta.

Si canvies el comportament d'una prova existent, digues **per què** el
comportament antic era incorrecte. Diverses proves d'aquesta sessió es van haver
d'actualitzar en afegir els reintents; totes les actualitzacions han d'estar
justificades, no ajustades fins que passin.

## 9. Per a la revisió de les 18:30

Deixa un document germà a `docs/` amb:

- què has canviat i per què, per troballa;
- què has decidit deliberadament **no** fer, i el motiu;
- qualsevol punt on discrepis d'aquest handoff o de la revisió;
- els hashes dels fitxers que hagis tocat.

El que més ajuda a la revisió no és la llista de correccions, sinó on has dubtat.
