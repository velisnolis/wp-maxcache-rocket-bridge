# Handoff de la segona revisió adversarial

Data de la revisió: 2026-08-06
Branca observada: `main`
Estat: **NO-GO per publicar fins a tancar els P1**

Aquest document és autocontingut. L'objectiu és que Claude pugui aplicar els canvis, afegir proves de regressió i retornar evidència verificable sense dependre del xat on es va fer la revisió.

## Resum executiu

La segona implementació resol correctament quatre punts de la revisió anterior:

- valida l'alternança regex completa, no només cada fragment;
- ignora comentaris i literals entre cometes quan analitza `<IfModule>`;
- usa tokens diferents i una resposta PHP verificable per a les sondes;
- calcula el resum de sincronització amb el mateix pipeline que genera el snippet.

Quatre àrees continuen parcialment obertes:

- política de sondes i reintents;
- carreres després de l'escriptura;
- garantia real de no escriure mai in-place;
- metadades del fitxer i falsos positius de la validació regex.

La revisió també ha detectat dos acabats de release: catàlegs de traducció desactualitzats i publicació de tags sense gate de proves.

## Ordre recomanat

1. Corregir F1 i F2 conjuntament: una única política de sondes per a baseline i verificació.
2. Corregir F3 i F4 conjuntament: comparació final del fitxer i restauració sempre atòmica.
3. Tancar F5 i F6 amb decisions explícites i proves.
4. Regenerar traduccions i posar proves al workflow de release.
5. Executar tota la matriu de verificació del final.

No s'hauria de publicar una release parcial entre els passos 1 i 2.

---

## F1 — P1: la baseline continua sent single-shot

### Codi afectat

- `includes/class-wmrb-sync-manager.php`
- `WMRB_Sync_Manager::write_managed_block()`
- La línia rellevant és la crida directa:

```php
$before = $this->probe_site();
```

### Problema

La sonda posterior té reintents, però la baseline anterior a l'escriptura només fa un intent. Si aquest intent rep una `502` o `503` transitòria, `verify_write()` considera que el web ja fallava i omet tota verificació posterior.

El canvi queda escrit, l'estat queda `in_sync` i `last_error` queda buit. Per tant, una sola fallada transitòria abans d'escriure desactiva el mecanisme que havia de detectar una configuració trencada.

### Reproductor observat

Executat des de l'arrel del repo:

```bash
php -r '
require "tests/bootstrap.php";
add_filter("wmrb_probe_retry_delay_ms", static fn() => 0);
$options = WMRB_Plugin::default_options();
file_put_contents(ABSPATH . ".htaccess", "# baseline\n");
WMRB_Test_State::queue_response(503);
WMRB_Test_State::queue_response(500);
WMRB_Test_State::queue_response(500);
WMRB_Test_State::queue_response(500);
$manager = new WMRB_Sync_Manager(
    new WMRB_Snippet_Service($options),
    $options
);
$state = $manager->apply_snippet_to_htaccess();
echo "calls=", count(WMRB_Test_State::$remote_calls),
     "; status=", $state["status"],
     "; changed=",
     ((string) file_get_contents(ABSPATH . ".htaccess") === "# baseline\n" ? "no" : "yes"),
     "; error=", var_export($state["last_error"], true), "\n";
'
```

Resultat observat:

```text
calls=1; status=in_sync; changed=yes; error=''
```

### Canvi esperat

- La baseline ha d'usar la mateixa política de reintents que la sonda posterior.
- Una baseline no concloent no ha de convertir-se silenciosament en `in_sync`.
- Cal distingir:
  - `ok`: hi ha baseline fiable;
  - `fail` persistent: el web ja fallava abans;
  - `unknown`: no hi ha prou evidència.
- Si la baseline és `fail` o `unknown` i s'escriu igualment, l'estat ha de conservar un avís explícit que demani verificació manual.

### Prova de regressió obligatòria

Afegir una prova que encoli:

1. `503` a la primera baseline;
2. `200` a la segona baseline;
3. `500` persistents després d'escriure.

La prova ha de demostrar que la `503` inicial no desactiva la verificació posterior i que l'error persistent provoca el resultat definit per la política.

---

## F2 — P1: el resultat dels reintents depèn només de l'últim intent

### Codi afectat

- `includes/class-wmrb-sync-manager.php`
- `WMRB_Sync_Manager::probe_site_repeatedly()`

La funció sobreescriu `$result` a cada iteració i, si no hi ha cap `ok`, retorna només l'últim valor.

### Problema

Dos conjunts idèntics d'evidències donen decisions diferents només per l'ordre:

```text
error,error,500 => rollback
500,500,error => canvi conservat com in_sync, amb avís
```

Això també incompleix la intenció documentada que una sola `502` o `503` transitòria no provoqui rollback: la seqüència `unknown,unknown,fail` sí que en provoca.

### Reproductor observat

```bash
php -r '
require "tests/bootstrap.php";
add_filter("wmrb_probe_retry_delay_ms", static fn() => 0);
$options = WMRB_Plugin::default_options();
foreach ([["error","error",500], [500,500,"error"]] as $case) {
    WMRB_Test_State::reset();
    add_filter("wmrb_probe_retry_delay_ms", static fn() => 0);
    file_put_contents(ABSPATH . ".htaccess", "# baseline\n");
    WMRB_Test_State::queue_response(200);
    foreach ($case as $code) {
        WMRB_Test_State::queue_response($code);
    }
    $manager = new WMRB_Sync_Manager(
        new WMRB_Snippet_Service($options),
        $options
    );
    $state = $manager->apply_snippet_to_htaccess();
    echo implode(",", $case),
         " => status=", $state["status"],
         "; changed=",
         ((string) file_get_contents(ABSPATH . ".htaccess") === "# baseline\n" ? "no" : "yes"),
         "; error=", $state["last_error"], "\n";
}
'
```

### Canvi esperat

Definir una política d'agregació independent de l'ordre. La proposta més conservadora és:

| Resultats dels intents | Resultat agregat |
| --- | --- |
| almenys un `ok` | `ok` |
| tots `fail` | `fail` |
| cap `ok` i almenys un `unknown` | `unknown` |

Amb aquesta política, només hi ha rollback quan tots els intents indiquen una fallada HTTP o de contingut verificable. Una fallada de transport deixa el canvi `unverified` i exigeix revisió manual.

### Proves de regressió obligatòries

- `error,error,500` i `500,500,error` han de donar el mateix resultat.
- Totes les permutacions de `fail,fail,unknown` han de donar el mateix resultat.
- `503,200` ha d'acabar en `ok`.
- `500,500,500` ha de continuar provocant rollback quan la baseline era `ok`.

---

## F3 — P1: una carrera posterior reeixida deixa un fals `in_sync`

### Codi afectat

- `includes/class-wmrb-sync-manager.php`
- `WMRB_Sync_Manager::verify_write()`
- `WMRB_Sync_Manager::write_managed_block()`

### Problema

Quan la sonda posterior retorna `ok`, `verify_write()` retorna `verified` sense tornar a llegir `.htaccess`. Si WordPress, cPanel o un altre procés substitueix el fitxer durant la petició, el procés aliè guanya, però el bridge marca el fingerprint com aplicat.

El resultat és `in_sync` encara que el bloc WMRB ja no existeixi. Les actualitzacions automàtiques futures poden no reintentar-ho fins que canviïn les opcions de WP Rocket.

### Reproductor observat

```bash
php -r '
require "tests/bootstrap.php";
add_filter("wmrb_probe_retry_delay_ms", static fn() => 0);
$options = WMRB_Plugin::default_options();
file_put_contents(ABSPATH . ".htaccess", "# original\n");
$foreign = "# foreign-after-write\n";
WMRB_Test_State::$on_remote_get = static function () use ($foreign) {
    if (2 === count(WMRB_Test_State::$remote_calls)) {
        file_put_contents(ABSPATH . ".htaccess", $foreign);
        WMRB_Test_State::$on_remote_get = null;
    }
};
$manager = new WMRB_Sync_Manager(
    new WMRB_Snippet_Service($options),
    $options
);
$state = $manager->apply_snippet_to_htaccess();
echo "status=", $state["status"],
     "; file=", json_encode((string) file_get_contents(ABSPATH . ".htaccess")),
     "; error=", var_export($state["last_error"], true), "\n";
'
```

Resultat observat:

```text
status=in_sync; file="# foreign-after-write\n"; error=''
```

### Canvi esperat

Després d'una sonda posterior `ok`:

1. executar `clearstatcache(true, $htaccess_path)`;
2. tornar a llegir el fitxer;
3. comparar-lo byte a byte amb `$written`;
4. retornar `verified` només si encara coincideix.

Si ha canviat, no s'ha de fer rollback ni sobreescriure el tercer. L'estat ha de quedar `pending_apply` o equivalent, amb un missatge explícit.

### Prova de regressió obligatòria

Simular un canvi estranger durant la segona petició amb una resposta saludable. Verificar:

- el canvi estranger sobreviu;
- l'estat no és `in_sync`;
- `last_error` explica que el fitxer ha canviat després de l'escriptura.

---

## F4 — P1: queda una restauració in-place després del `rename()`

### Codi afectat

- `includes/class-wmrb-sync-manager.php`
- `WMRB_Sync_Manager::write_file_atomically()`

Camí actual:

```php
if ((string) file_get_contents($path) === $content) {
    return true;
}

file_put_contents($path, $restore_on_failure);
return false;
```

### Problema

Aquest `file_put_contents()`:

- trunca el fitxer abans d'acabar d'escriure;
- pot deixar Apache llegint un `.htaccess` buit o parcial;
- pot sobreescriure el canvi concurrent que ha provocat la discrepància;
- contradiu README i SPEC, que prometen que no hi ha cap fallback in-place.

El lock del bridge no protegeix contra WordPress, cPanel o altres processos.

### Canvi esperat

No hi ha d'haver cap `file_put_contents($path, ...)` sobre el `.htaccess` final.

Opcions acceptables:

1. restaurar amb un segon temporal al mateix directori, preservar metadades, verificar-lo i fer `rename()`;
2. si no es pot garantir que el fitxer encara sigui el que acaba d'escriure el bridge, no restaurar-lo i retornar un error crític sense tocar el canvi aliè.

La restauració també necessita una comparació condicional: només s'ha d'aplicar si el fitxer encara coincideix amb la versió que aquesta operació havia escrit.

### Proves de regressió obligatòries

- Una inspecció estàtica del codi o test dedicat ha de garantir que no existeix cap escriptura directa sobre `$htaccess_path`.
- Simular una discrepància posterior al `rename()` i verificar que un contingut estranger no és substituït.
- Verificar que la restauració atòmica conserva el contingut complet sota error d'escriptura.

---

## F5 — P2: mode i grup es preserven, propietari i ACL no

### Codi afectat

- `includes/class-wmrb-sync-manager.php`
- `WMRB_Sync_Manager::write_file_atomically()`

### Problema

El codi captura `fileperms()` i `filegroup()`. No captura `fileowner()` ni ACL o atributs estesos. `tempnam()` crea un inode propietat de l'usuari PHP i el `rename()` substitueix l'inode original.

En el hosting compartit habitual, l'usuari PHP i el propietari del `.htaccess` probablement coincideixen, però no és una garantia universal. Un `.htaccess` escrivible per grup amb un propietari diferent és un cas possible.

### Canvi esperat

- Capturar l'UID original.
- Verificar que l'UID del temporal coincideix abans del `rename()`.
- Si no coincideix i `chown()` no és possible, refusar l'operació.
- Decidir explícitament si ACL/xattrs queden fora de suport. Si queden fora, documentar-ho i refusar entorns on es detectin metadades incompatibles, si la plataforma permet detectar-les.

### Proves de regressió

- Mantenir les proves actuals de mode i grup.
- Afegir una prova d'UID quan l'entorn de CI ho permeti.
- Com a mínim, provar el camí de refús quan UID original i UID del temporal no coincideixen mitjançant una abstracció o filtre injectable.

---

## F6 — P2: fer match amb buit no equival sempre a fer match amb tot

### Codi afectat

- `includes/class-wmrb-snippet-service.php`
- `WMRB_Snippet_Service::is_usable_regex_fragment()`
- `WMRB_Snippet_Service::matches_empty_string()`

### Problema

La premissa actual és massa àmplia:

> Un patró que coincideix amb la cadena buida coincideix amb totes les peticions.

És cert per a `.*`, `a*` o `(foo)?` amb cerca no ancorada. No és cert per a `^$` o `\A\z`: coincideixen amb la cadena buida, però no amb una cadena no buida.

Reproductor:

```bash
php -r '
require "tests/bootstrap.php";
WMRB_Test_State::$options["wp_rocket_settings"] = [
    "cache_reject_ua" => ["^$"],
];
$options = WMRB_Plugin::default_options();
$service = new WMRB_Snippet_Service($options);
echo "preg_nonempty=", preg_match("~^$~", "Mozilla/5.0"),
     "; rejected=", json_encode($service->get_rejected_patterns()), "\n";
'
```

Resultat observat:

```text
preg_nonempty=0; rejected=[{"setting":"cache_reject_ua","pattern":"^$"}]
```

`^$` pot ser una exclusió legítima per a peticions sense User-Agent. Cal confirmar la semàntica exacta de `MaxCacheExcludeUA` abans de decidir si aquest cas s'ha d'admetre.

### Canvi esperat

No usar només la cadena buida com a prova que el patró exclourà tot el trànsit. La validació ha de ser específica per directiva:

- URI: provar com a mínim `/` i una URI normal;
- UA: provar User-Agent buit i no buit;
- Cookie: provar cap cookie i una capçalera normal.

La política final ha d'estar documentada i tenir proves per a:

- `.*`, `a*` i `(foo)?`: rebutjats;
- `^$`: admès o rebutjat segons una decisió explícita sobre el cas sense capçalera;
- patrons ancorats que només coincideixen amb un subconjunt: no considerar-los universals.

---

## F7 — P2: els catàlegs de traducció no contenen els textos nous

### Fitxers afectats

- `includes/class-wmrb-admin-page.php`
- `includes/class-wmrb-sync-manager.php`
- `languages/wp-maxcache-rocket-bridge.pot`
- `languages/wp-maxcache-rocket-bridge-ca.po`
- `languages/wp-maxcache-rocket-bridge-en_US.po`
- fitxers `.mo` corresponents

### Evidència

No apareixen als catàlegs, entre d'altres:

- `Exclusions de WP Rocket descartades`;
- `El directori arrel no és escrivible...`;
- `Snippet aplicat, però no s'ha pogut verificar...`;
- `El web ha deixat de respondre...`;
- `Ja hi ha una operació del bridge en curs...`.

Com que els originals estan en català, una instal·lació anglesa mostrarà català quan falti la traducció.

### Canvi esperat

1. Regenerar el POT des del codi actual.
2. Actualitzar els PO català i anglès.
3. Compilar de nou els MO.
4. Verificar que no desapareixen traduccions existents.

### Prova/verificació

```bash
rg -n -F "Snippet aplicat, però no s’ha pogut verificar" languages
rg -n -F "Exclusions de WP Rocket descartades" languages
```

Tots els missatges nous han d'aparèixer al POT i als PO. Els MO han de tenir una data de generació posterior als canvis.

---

## F8 — P2: el workflow de release publica sense executar les proves

### Fitxer afectat

- `.github/workflows/release.yml`

### Problema

El workflow activat pels tags:

1. comprova que el tag coincideixi amb la versió;
2. construeix el ZIP;
3. publica la release.

No executa lint ni PHPUnit. El workflow `ci.yml` només escolta pushes a `main` i pull requests, no tags. Per tant, un tag pot apuntar a un commit que no hagi superat CI.

### Canvi esperat

Abans de `gh release create`, executar com a mínim:

```yaml
- uses: shivammathur/setup-php@v2
  with:
    php-version: '8.3'
    coverage: none

- run: composer install --no-interaction --no-progress
- run: composer validate --strict
- run: composer test
- run: find . -path ./vendor -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
```

Alternativament, convertir el CI en un workflow reutilitzable i fer que release en depengui.

També cal actualitzar el comentari de `release.yml` que encara afirma que l'updater fa fallback al source archive: el codi actual ja el refusa.

### Criteri d'acceptació

Cap pas de publicació pot executar-se si fallen Composer, PHPUnit, lint o el build.

---

## Matriu final de verificació

Executar des de l'arrel del repo:

```bash
composer validate --strict
composer test
bash -n build.sh
find includes tests -name '*.php' -print0 | xargs -0 -n1 php -l
git diff --check
```

Construir l'artefacte en un directori temporal i inspeccionar-lo:

```bash
review_tmp="$(mktemp -d)"
./build.sh "$review_tmp"
unzip -t "$review_tmp/wp-maxcache-rocket-bridge.zip"
unzip -Z1 "$review_tmp/wp-maxcache-rocket-bridge.zip" | sort
```

El ZIP no ha de contenir:

- `vendor/`;
- `tests/`;
- `composer.json` o `composer.lock`;
- `phpunit.xml*`;
- `SPEC.md`;
- scripts `.sh`;
- `.github/`, `.git/`, `.remember/`, `release/` o `dist/`.

El grup `batch2` és feina planificada i està exclòs de la suite per defecte:

```bash
vendor/bin/phpunit --group batch2
```

No s'ha de presentar el verd de `composer test` com si `batch2` també fos verd. A la revisió del 2026-08-06, `batch2` tenia 22 proves i 17 fallades conegudes.

## Evidència de la fotografia revisada

La darrera passada estable va donar:

```text
composer validate --strict: OK
composer test: 99 tests, 810 assertions, OK
PHP lint: OK
git diff --check: OK
ZIP temporal: 19 entries, integrity OK
```

Hashes dels fitxers centrals revisats:

```text
e04100baea882d93d8959bc4be95b60c72c15dbe2aff780c1d8202c3a2bfe041  includes/class-wmrb-snippet-service.php
014d43d34364ac406230f7077093c68fcaa13c385f9e9bd5855f43e7e22cfb49  includes/class-wmrb-sync-manager.php
963b4b4883c7077c4425df7ab1974f3a3c09677deafbee96e46fb4bf8db825d5  includes/class-wmrb-github-updater.php
ed0aba275bebc3f01e497d921b96adc75614f2889040de697666d293bb86ef3f  tests/ReviewFindingsTest.php
7654aa0ed6dacc6e4ec2948bcb6e3e9c8b40a572fa6b35992845031ff5d78acf  tests/SyncManagerTest.php
7ae7e9cad8861c56e665eb71b833f339d856255fd67bb70e5d1c08b268e59ba3  tests/GithubUpdaterTest.php
```

Si algun hash ja no coincideix, cal considerar aquest document una guia de troballes i tornar a executar els reproductors sobre el codi nou.

## Definició de fet

La revisió es pot donar per tancada quan:

- F1–F4 tenen implementació i proves de regressió verdes;
- F5 té una política explícita sobre UID/ACL i un camí segur;
- F6 ja no rebutja patrons legítims per una equivalència incorrecta;
- els catàlegs de traducció estan regenerats;
- la release queda bloquejada per les proves;
- la suite per defecte, lint, Composer i build són verds;
- els tres reproductors d'aquest document ja no produeixen els resultats defectuosos;
- no queda cap escriptura directa in-place sobre `.htaccess`.
