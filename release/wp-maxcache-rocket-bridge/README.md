# WP Rocket + MAxCache Bridge

Plugin WordPress per governar `mod_maxcache` a partir de `WP Rocket`, amb un model simple: actives el bridge, el passes a `managed mode`, i després els canvis habituals es fan a `WP Rocket`.

El bridge no pretén substituir `WP Rocket` ni `AccelerateWP`. L’objectiu és tenir una sola configuració `MaxCache` gestionada, alineada amb els defaults de CloudLinux/AccelerateWP i amb compatibilitat per casos reals com Cloudflare.

## Què fa

- Diagnostica si l’entorn és compatible amb `WP Rocket` + Apache `mod_maxcache`.
- Genera un únic bloc `MaxCache` basat en:
  - baseline CloudLinux / AccelerateWP
  - exclusions de `WP Rocket`
  - overrides del bridge
- Detecta variants especials de `WP Rocket` i adapta el `MaxCachePath` automàticament:
  - `cache_webp` -> `{WEBP_SUFFIX}`
  - `cache_logged_user` + `secret_cache_key` -> `MaxCacheLoggedHash` + `{USER_SUFFIX}`
- Detecta si `.htaccess` està en mode:
  - `managed`
  - `unmanaged`
  - `external`
  - `conflict`
- Pot fer takeover d’una configuració MaxCache existent i passar-la a `managed mode`.
- Manté backup i rollback de `.htaccess`.
- Observa canvis a `wp_rocket_settings` i pot auto-reaplicar el bloc si el bridge és el propietari.

## Requisits

- WordPress 6.0+
- PHP 7.4+
- WP Rocket actiu
- Apache 2.4 amb `mod_maxcache`

## Flux recomanat

1. Instal·lar i activar el plugin.
2. Obrir `Tools > MAxCache Bridge`.
3. Executar `Run checks`.
4. Si hi ha un bloc MaxCache extern o duplicat, executar `Take over MaxCache management`.
5. Verificar que l’estat passi a `managed` i `in_sync`.
6. A partir d’aquí, fer els canvis habituals a `WP Rocket`.

## Com funciona el model

El bridge tracta `WP Rocket` com a font principal dels canvis habituals i reserva les opcions pròpies només per casos de compatibilitat o diagnòstic.

La jerarquia real és:

1. baseline oficial CloudLinux / AccelerateWP
2. exclusions i senyals de `WP Rocket`
3. overrides del bridge

## Opcions del bridge

Guardades a `wmrb_options`:

- `bridge_enabled`
- `debug_mode`
- `auto_sync_enabled`
- `auto_apply_htaccess`
- `serve_gzip_variant`
- `serve_webp_variant`
- `custom_cache_path_template`

## Gzip Variant

`serve_gzip_variant` controla el `MaxCachePath`.

- `false`
  - usa `.../index{MOBILE_SUFFIX}{SSL_SUFFIX}.html`
- `true`
  - usa `.../index{MOBILE_SUFFIX}{SSL_SUFFIX}.html{GZIP_SUFFIX}`

Recomanació pràctica:

- darrere de Cloudflare o altres proxys: sovint convé `false`
- origen directe sense problemes de capçaleres: es pot usar `true`

## WebP Variant

El bridge detecta `cache_webp` de `WP Rocket` i adapta automàticament el `MaxCachePath` perquè apunti a fitxers tipus `index-https-webp.html`.

També existeix l’override `serve_webp_variant` per forçar la variant des del bridge si cal diagnosticar o compensar un entorn especial.

Observació pràctica:

- si `WP Rocket` deixa de generar fitxers WebP, el bridge torna a la ruta sense `{WEBP_SUFFIX}`
- la web pot continuar servint bé, però el patró de servei estàtic de `mod_maxcache` pot desaparèixer fins que existeixin fitxers compatibles amb el path nou

## User Cache

Quan `WP Rocket` activa `cache_logged_user`, el bridge intenta seguir aquest comportament automàticament.

Si també hi ha `secret_cache_key`, el bloc WMRB passa a:

- afegir `MaxCacheLoggedHash`
- usar `{USER_SUFFIX}` al `MaxCachePath`
- deixar d’excloure `wordpress_logged_in_.+`

Si `cache_logged_user` és actiu però no hi ha `secret_cache_key` usable, el bridge no intenta servir caché per usuari i es manté en mode prudent amb exclusions de cookies.

## Modes de gestió

La UI mostra el mode detectat a `.htaccess`:

- `managed`: el bridge governa l’únic bloc `MaxCache`
- `unmanaged`: no hi ha cap bloc `MaxCache`
- `external`: hi ha un bloc `MaxCache` extern/manual
- `conflict`: hi ha més d’un bloc `MaxCache` actiu

L’`auto_apply` només actua en `managed` o `unmanaged`.

## Takeover

Quan el plugin detecta `external` o `conflict`, es pot executar `Take over MaxCache management`.

Aquesta acció:

1. crea backup de `.htaccess`
2. elimina els blocs `MaxCache` existents
3. escriu un únic bloc WMRB gestionat
4. deixa l’estat en `managed` i `in_sync`

## Validació real

Entorns provats:

- `milatalent.cat`
  - patró equivalent a AccelerateWP original
  - origen amb `last-modified`, `accept-ranges` i `gzip`
- `reliquiaesanctorumincatalonia.cat`
  - takeover real a `managed mode`
  - darrere de Cloudflare amb `serve_gzip_variant = false`
  - origen correcte després del takeover
- `www.injecciodeplastics.com` + `www.inyecciondeplastico.es`
  - WordPress + WPML amb domini per idioma
  - validació real de `{HTTP_HOST}` per multidomini
  - validació real de `cache_webp = 1`
  - prova de canvi temporal a mode sense WebP i restauració posterior

## Quick test

La prova ràpida actual usa la URL pública del WordPress.

Això vol dir que:

- pot passar per Cloudflare o altres proxys
- és útil com a senyal general
- no substitueix una validació directa contra l’origen amb `curl --resolve`

## Rollback

El bridge guarda backups a `wp-content/wmrb-backups/`.

Si alguna aplicació falla:

1. fer `Rollback last backup`
2. purgar `WP Rocket`
3. revalidar headers a origen

## Actualitzacions via GitHub

El plugin consulta GitHub Releases a:

`https://api.github.com/repos/velisnolis/wp-maxcache-rocket-bridge/releases/latest`

Flux previst:

1. tag `vX.Y.Z`
2. release a GitHub
3. asset `wp-maxcache-rocket-bridge.zip`

## Descàrrec

Aquest plugin és una utilitat independent creada per operar amb WordPress, WP Rocket, Apache `mod_maxcache` i patrons de configuració documentats per CloudLinux / AccelerateWP.

No tenim cap relació comercial, suport oficial ni afiliació amb WP Rocket, CloudLinux, AccelerateWP, Apache ni cap altre proveïdor esmentat. El plugin es distribueix tal com és, sense garanties, i l’ús en cada entorn és responsabilitat de qui el desplega.
