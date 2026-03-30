# WP Rocket + MAxCache Bridge

## Objectiu

Crear un plugin WordPress que permeti usar `WP Rocket` com a front principal de cache i invalidació, mentre el bridge governa una única capa `mod_maxcache` a Apache.

El producte objectiu no és “obrir una segona consola de cache”, sinó aquest flux:

1. instal·lar plugin
2. passar a `managed mode`
3. tocar `WP Rocket`
4. despreocupar-se del bridge excepte per diagnòstic o compatibilitat

## Principis de producte

- `WP Rocket` és la font principal dels canvis habituals.
- El bridge només afegeix govern sobre `mod_maxcache`.
- La configuració base ha d’assemblar-se als defaults oficials de CloudLinux / AccelerateWP.
- El bridge no ha de coexistir en silenci amb múltiples blocs `MaxCache`.
- Casos especials com Cloudflare han de ser overrides explícits, no hacks ocults.

## Context validat

- `milatalent.cat` usa el patró original d’AccelerateWP amb un únic bloc `MaxCache`.
- `reliquiaesanctorumincatalonia.cat` s’ha migrat en prova real a `managed mode`.
- A Cloudflare, `cf-cache-status: DYNAMIC` no invalida l’objectiu si l’origen respon amb patró estàtic correcte.
- `www.injecciodeplastics.com` i `www.inyecciondeplastico.es` validen el cas multidomini per idioma amb `WPML`.
- `www.injecciodeplastics.com` valida també el cas real de `cache_webp`.

## No-objectius

- No reemplaçar `WP Rocket`.
- No implementar una segona cache d’aplicació.
- No pretendre ser suport oficial de CloudLinux, WP Rocket o Apache.
- No gestionar regles de Cloudflare automàticament.

## Requisits tècnics

- WordPress 6.0+
- PHP 7.4+
- WP Rocket actiu
- Apache 2.4 amb `mod_maxcache`
- estructura de cache de WP Rocket compatible amb `MaxCachePath`

## Arquitectura

### 1) Jerarquia de configuració

L’snippet final es construeix amb aquesta prioritat:

1. baseline CloudLinux / AccelerateWP
2. exclusions i senyals de `WP Rocket`
3. overrides explícits del bridge

### 2) Modes de govern

El bridge ha de detectar un d’aquests modes:

- `managed`
- `unmanaged`
- `external`
- `conflict`
- `unreadable`

Regla:

- només en `managed` o `unmanaged` es permet `auto_apply`
- en `external` o `conflict` el bridge ha de demanar takeover explícit

### 3) Takeover

Acció pensada per instal·lacions reals amb blocs previs d’AccelerateWP/manuals.

Passos:

1. backup de `.htaccess`
2. eliminació de tots els blocs `maxcache_module`
3. escriptura d’un únic bloc WMRB
4. pas a `managed` + `in_sync`

### 4) Sync amb WP Rocket

El bridge observa `wp_rocket_settings` i recalcula l’snippet.

Fonts principals actuals:

- `cache_reject_uri`
- `cache_reject_ua`
- `cache_reject_cookies`
- `cache_webp`
- `cache_logged_user`
- `secret_cache_key`

### 5) Compatibilitat WebP

Si `WP Rocket` genera variants WebP, el bridge ho ha de reflectir al `MaxCachePath` amb `{WEBP_SUFFIX}`.

Regles:

- auto-detecció a partir de `cache_webp`
- override manual opcional via `serve_webp_variant`
- si desapareix la variant WebP a `WP Rocket`, el bridge torna al path sense `{WEBP_SUFFIX}`

### 6) User cache per usuaris logats

Objectiu de la fase actual:

- suportar el mode principal de `WP Rocket` per caché per usuari
- no suportar encara el mode avançat de caché compartida per usuaris logats

Regles:

- si `cache_logged_user=1` i hi ha `secret_cache_key`, el bridge usa `MaxCacheLoggedHash` i `{USER_SUFFIX}`
- en aquest cas `wordpress_logged_in_.+` s’ha d’eliminar de `MaxCacheExcludeCookie`
- si falta `secret_cache_key`, el bridge no ha d’intentar servir caché per usuari
- `USER_SHARED_SUFFIX` queda fora de la fase actual fins a tenir entorn real de prova

### 7) Compatibilitat Gzip

El comportament general pot seguir el patró `html{GZIP_SUFFIX}`, però s’ha de poder desactivar per entorns amb proxy/CDN on això pugui provocar descàrrega d’HTML o comportament incorrecte.

Opció:

- `serve_gzip_variant`

Intenció:

- `true` per casos compatibles
- `false` per entorns tipus Cloudflare quan convingui

## Interfície d’usuari

Pantalla única a `Tools > MAxCache Bridge` amb:

- checks d’entorn
- mode de gestió MaxCache
- resum de sync amb `WP Rocket`
- estat `in_sync / pending_apply`
- accions:
  - `Run checks`
  - `Apply snippet now`
  - `Take over MaxCache management`
  - `Rollback last backup`
- preview del bloc
- quick test sobre URL pública

## Seguretat i operació

- backup abans d’escriure `.htaccess`
- rollback accessible
- nonces a totes les accions d’admin
- capacitat mínima `manage_options`
- el bridge no autoaplica quan detecta govern extern o conflicte

## Validació

### Cas 1: patró AccelerateWP original

- `milatalent.cat`
- un sol bloc `MaxCache`
- headers correctes a origen

### Cas 2: takeover cap a managed mode

- `reliquiaesanctorumincatalonia.cat`
- existia configuració MaxCache prèvia
- takeover executat amb backup
- estat final `managed` i `in_sync`
- origen correcte després del canvi

### Cas 3: multidomini + WebP

- `www.injecciodeplastics.com`
- `www.inyecciondeplastico.es`
- validació real de `{HTTP_HOST}` per domini/idioma
- validació real de `cache_webp = 1`
- prova temporal de retorn a path sense WebP i restauració posterior

## Roadmap proper

### v0.2.1

- fix updater slug
- fingerprint de l’snippet efectiu
- baseline CloudLinux més fidel
- detecció de `managed/external/conflict`
- takeover a mode gestionat
- aclariment del quick test públic
- suport automàtic de WebP via `cache_webp`
- validació multidomini amb `WPML` per domini
- suport inicial de user cache via `MaxCacheLoggedHash` + `{USER_SUFFIX}`

### següent fase

- proves reals de login/logout per user cache
- millor representació de proves d’origen a la UI
- revisió de textos i readme públic
- paquet públic i release de GitHub
