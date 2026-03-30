# WP Rocket + MAxCache Bridge

Plugin WordPress per integrar WP Rocket amb `mod_maxcache` (Apache), mantenint WP Rocket com a motor de generació/purga i afegint un flux segur per sincronitzar i aplicar snippet a `.htaccess`.

## Objectiu

- Diagnosticar compatibilitat de l'entorn.
- Generar snippet `mod_maxcache` basat en WP Rocket + defaults segurs.
- Sincronitzar exclusions de WP Rocket (URI, UA, cookies).
- Aplicar canvis a `.htaccess` amb backup i rollback.

## Requisits

- WordPress 6.0+
- PHP 7.4+
- WP Rocket actiu
- Apache 2.4 amb `mod_maxcache`

## Funcionalitats

- Diagnòstic d'entorn (`OK/WARN/ERROR`):
  - WP Rocket actiu
  - Apache detectable
  - bloc MaxCache a `.htaccess`
  - fitxers de cache WP Rocket presents
- Snippet assistant:
  - preview
  - copy
  - download
- Quick tests HTTP:
  - request identity/gzip
  - headers orientatius (`last-modified`, `accept-ranges`, `content-encoding`)
- Purge observer (debug mode):
  - log de hooks de purga
- Sync manager:
  - monitorització de canvis a `wp_rocket_settings`
  - estat `in_sync` / `pending_apply`
  - `Apply snippet now`
  - `Rollback last backup`
- GitHub updater:
  - detecció d’updates des de GitHub Releases
  - suport `Update URI` per fora del directori oficial de WordPress

## Opcions de configuració

Guardades a `wmrb_options`:

- `bridge_enabled` (bool)
- `debug_mode` (bool)
- `auto_sync_enabled` (bool)
- `auto_apply_htaccess` (bool)
- `serve_gzip_variant` (bool)
- `custom_cache_path_template` (string)

## UI (`Tools > MAxCache Bridge`)

- Estat de l'entorn
- Botons:
  - `Run checks`
  - `Apply snippet now`
  - `Rollback last backup`
- Toggles:
  - `auto_sync_enabled`
  - `auto_apply_htaccess`
  - `serve_gzip_variant`
- Indicadors:
  - resum de sincronització des de WP Rocket
  - estat de sync i timestamps
  - últim backup/error
  - retenció backups: 5

## Gzip Variant: quan activar-la

`serve_gzip_variant` controla el `MaxCachePath`:

- OFF (recomanat per defecte):
  - `.../index{MOBILE_SUFFIX}{SSL_SUFFIX}.html`
- ON:
  - `.../index{MOBILE_SUFFIX}{SSL_SUFFIX}.html{GZIP_SUFFIX}`

### Recomanació pràctica

- Amb Cloudflare/proxy davant d'Apache: **OFF** (evita casos de resposta binària sense `Content-Type`).
- Sense Cloudflare (origen directe): pots usar **ON** si les capçaleres són correctes.

## Resultat real validat

- `reliquiaesanctorumincatalonia.cat` (amb Cloudflare):
  - millor amb `serve_gzip_variant = false`
- `milatalent.cat` (sense Cloudflare):
  - `HTTP 200`
  - `content-type: text/html; charset=UTF-8`
  - `content-encoding: gzip` quan es demana `Accept-Encoding: gzip`
  - cos HTML correcte (no download binari)

## Auto-apply segur de `.htaccess`

Abans de cada aplicació:

1. crea backup a `wp-content/wmrb-backups/`
2. reemplaça/insereix bloc delimitat:
   - `# BEGIN WMRB suggested MaxCache snippet`
   - `# END WMRB suggested MaxCache snippet`
3. actualitza estat de sync

Rollback:

- `Rollback last backup` restaura l'últim backup.

Retenció:

- es conserven només els últims 5 backups.

## Troubleshooting ràpid

- Error 500 després d'aplicar snippet:
  - fes `Rollback last backup`
  - revisa directives compatibles amb el teu `mod_maxcache`
- La pàgina es descarrega en lloc de renderitzar:
  - posa `serve_gzip_variant = false`
  - reaplica snippet
  - comprova `content-type: text/html`

## Flux recomanat de rollout

1. Instal·lar/activar plugin.
2. `Run checks`.
3. Confirmar toggles segons entorn:
   - Cloudflare: gzip variant OFF
   - sense Cloudflare: valorar ON
4. `Apply snippet now`.
5. Verificar headers amb `curl -I`.
6. Si cal, rollback des de la UI.

## Actualitzacions via GitHub

El plugin consulta `https://api.github.com/repos/velisnolis/wp-maxcache-rocket-bridge/releases/latest`.

Per cada release nova:

1. Crear tag (`vX.Y.Z` recomanat).
2. Crear GitHub Release.
3. Adjuntar asset `wp-maxcache-rocket-bridge.zip` (zip amb carpeta arrel `wp-maxcache-rocket-bridge/`).

Si no hi ha asset, el plugin fa fallback al zip del tag de GitHub.
