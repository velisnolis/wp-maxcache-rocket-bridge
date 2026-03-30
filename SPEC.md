# WP Rocket + MAxCache Bridge

## Objectiu

Crear un plugin WordPress lleuger que integri WP Rocket amb `mod_maxcache` per servir HTML estàtic des d'Apache quan hi ha fitxer de cache, mantenint WP Rocket com a motor principal de generació i purga.

## Context validat

- `milatalent.cat` mostra patró de resposta estàtica a origen (`accept-ranges`, `last-modified`, mides estables, gzip correcte).
- `reliquiaesanctorumincatalonia.cat` no ho mostrava inicialment; després d'afegir bloc `MaxCache` equivalent al de `milatalent`, l'origen ja mostra patró estàtic.
- A Cloudflare, `cf-cache-status` pot continuar en `DYNAMIC` i no és un blocker si el TTFB és raonable.

## No-objectius

- No reemplaçar WP Rocket.
- No implementar una segona cache de pàgina.
- No gestionar regles de Cloudflare automàticament en v0.
- No editar `.htaccess` automàticament sense acció explícita d'admin.

## Requisits tècnics

- WordPress 6.0+.
- WP Rocket actiu.
- Apache 2.4 amb `mod_maxcache` instal·lat i habilitat.
- Estructura de cache WP Rocket compatible amb:
  - `/wp-content/cache/wp-rocket/{HTTP_HOST}{REQUEST_URI}{QS_SUFFIX}/index{MOBILE_SUFFIX}{SSL_SUFFIX}.html{GZIP_SUFFIX}`

## Arquitectura (MVP)

### 1) Diagnòstic de compatibilitat

Pantalla d'admin (`Tools > MAxCache Bridge`) amb checks:

- WP Rocket actiu.
- Entorn Apache detectat.
- Presència del bloc `maxcache_module` a `.htaccess` (només lectura).
- Presència de fitxers sota `wp-content/cache/wp-rocket`.

Sortida amb estat:
- `OK`: compatible.
- `WARN`: parcial (faltaria snippet o no hi ha fitxers encara).
- `ERROR`: no compatible.

### 2) Assistant de snippet (preview/còpia)

Generar snippet recomanat `mod_maxcache` (sense aplicar automàticament):

- `MaxCache On`
- `MaxCacheOptions -SkipCacheOnMobile -TabletAsMobile`
- `MaxCacheQSAllowedParams` i `MaxCacheQSIgnoredParams` base
- exclusions URI/UA/cookies segures
- `MaxCachePath` de WP Rocket

Accions:
- `Copy snippet`
- `Download snippet.txt`

### 3) Verificació ràpida

Botó "Run checks" que executa tests HTTP locals (`wp_remote_get`) sobre la home:

- Request identity vs gzip.
- Comprovar capçaleres esperades a origen:
  - `accept-ranges: bytes` (si disponible)
  - `last-modified`
  - `content-encoding: gzip` quan pertoca

Mostrar resultat orientatiu (no hard-fail si headers depenen de stack).

### 4) Integració amb purga (informativa)

Connectar-se a hooks de purga de WP Rocket (si presents) només per:
- registrar esdeveniments en log intern (`debug mode`),
- confirmar que la invalidació passa pel flux de WP Rocket.

No esborrem fitxers manualment en v0.

## Interfície d'usuari (admin)

- Pestanya única:
  - Estat entorn
  - Snippet recomanat
  - Resultat proves
  - Guia curta de rollout + rollback

## Configuració

Opcions mínimes:

- `bridge_enabled` (bool, default true)
- `debug_mode` (bool, default false)
- `custom_cache_path_template` (string, opcional)

## Seguretat

- No escriure `.htaccess` automàticament.
- Escapat i validació estricta de qualsevol template/path.
- Nonces en totes les accions d'admin.
- Capacitats mínimes: `manage_options`.

## Compatibilitat i riscos

- Variacions de layout de WP Rocket segons versió/config.
- Servidors amb NGINX davant Apache poden canviar semàntica de capçaleres.
- Cloudflare pot mantenir `DYNAMIC`; no afecta l'objectiu de millora a origen.

## Pla de versions

### v0.1 (MVP)

- Pantalla de diagnòstic.
- Generador de snippet (preview/còpia/descarrega).
- Proves bàsiques de capçaleres.
- Log informatiu de hooks de purga.

### v0.2

- Assistant opcional d'aplicació de snippet amb backup i rollback.
- Matriu de compatibilitat per versions WP Rocket.
- Export d'informe diagnòstic.

### v0.3

- QA automàtica multi-URL.
- Suggeriments dinàmics d'exclusions segons plugins actius.

## Definició de "Done" del MVP

- Plugin instal·lable en WP net amb WP Rocket.
- Mostra diagnòstic coherent en escenaris OK/WARN/ERROR.
- Genera snippet vàlid i copiable.
- Proves internes donen evidència que origen serveix resposta cachejable quan el servidor està ben configurat.
- Documentació curta de deploy i rollback.

## Checklist de rollout

1. Instal·lar plugin en staging.
2. Validar diagnòstic `OK`.
3. Aplicar snippet manualment al servidor (ops).
4. Purga WP Rocket.
5. Verificar amb `curl --resolve` a origen.
6. Passar a producció.

## Checklist de rollback

1. Restaurar `.htaccess` backup.
2. Purga WP Rocket.
3. Revalidar headers amb `curl`.
4. Deixar plugin en mode diagnòstic (sense canvis).
