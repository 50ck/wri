# blogs.

A small multi-user blog engine: PHP 8.4/8.5, PDO SQLite, native forms and CSS. All interface text is English. No JavaScript, framework, build process, uploads or external runtime services.

Users register and immediately receive `/{username}`. Each account has one blog with two built-in sections: `inicio` at `/{username}` holds one editable Markdown text, and `blogs` at `/{username}/blogs` lists its posts. The homepage text is editable from its pencil link or `/manage/sections/home`. These permanent pages do not count toward the ten additional custom sections. Each blog also has drafts and published posts, one global stylesheet, private/public visibility and password-confirmed account deletion. Owner actions appear inside their blog; `/manage` opens settings; navigation follows settings, sections, appearance and addons. Section management uses one plain list with edit links beside titles. Section removal and post moves preserve old article URLs through redirects.

## Install

Requirements: PHP **8.4 or 8.5**, `pdo_sqlite`, `mbstring`, `dom`, PHP-FPM and Nginx. Argon2id is preferred when compiled into PHP. Python 3 and PHP `pcntl` are needed only for tests. SQLite must support WAL, expression indexes and UPSERT (3.24+).

```sh
git clone <your-repository-url> blog
cd blog
php install.php
# Enable broad Mermaid diagram support (Linux x86_64, curl + tar):
php extensions/mermaid/install.php
```

The installer creates private storage and runs versioned migrations transactionally. It is safe to run again. Do not expose the repository root through the web server. Only `public/` is a document root. Parsedown 1.8.0 and a pinned PHP LaTeX-to-MathML converter are included under `libraries/` with their MIT licenses; Composer is unnecessary. The separate renderer installer downloads the official mmdr 0.3.1 Linux x86_64 GNU executable, verifies a fixed SHA-256 digest and atomically installs it in `extensions/mermaid/bin/mmdr`. That machine-specific binary is excluded from source control. No diagram source is sent to a service. On other platforms (including FreeBSD or musl Linux), install a compatible [mermaid-rs-renderer](https://github.com/1jehuang/mermaid-rs-renderer/tree/v0.3.1) executable, e.g. `cargo install mermaid-rs-renderer --version 0.3.1 --locked`, and set `BLOG_MERMAID_BIN`/`mermaid_bin` to its absolute path. Rust is needed only if building that executable yourself. Without the executable, Mermaid blocks retain their escaped source.

On Void, install the versioned PHP packages (or the distribution's `php`, `php-fpm`, `php-sqlite` meta-packages), enable `pdo_sqlite` in PHP's configuration, and check both CLI and FPM extension configuration. See the [Void PHP handbook](https://docs.voidlinux.org/config/php.html). With the extension installed but disabled, use `php -d extension=pdo_sqlite install.php`.

```sh
doas xbps-install -S php8.5 php8.5-fpm php8.5-sqlite nginx
```

Use the installed service names under `/etc/sv/` to enable PHP-FPM and Nginx with runit after configuring them. Package/service names depend on the selected PHP branch.

On FreeBSD, choose a supported PHP branch using `pkg search php85` or `pkg search php84`; install that branch's PHP, PDO SQLite, mbstring and DOM packages plus Nginx. Enable `php_fpm` and `nginx` using `sysrc`, then start their services. FPM configuration conventionally lives under `/usr/local/etc/`; adapt the pool and socket to `/var/run/` and Nginx's service group. See [FreeBSD PHP-FPM](https://man.freebsd.org/cgi/man.cgi?query=php-fpm&sektion=8).

## Run locally

```sh
php -d extension=pdo_sqlite install.php
php -d extension=pdo_sqlite -S localhost:8080 -t public bin/test-server.php
```

Open `http://localhost:8080`. If SQLite is already enabled, omit `-d extension=pdo_sqlite`. The development router exposes only the two platform CSS assets and routes everything else through the application. The PHP development server is for local use only.

## Production

1. Create a dedicated service account, e.g. `blog`. Source files should be owned by the deployer/root and readable, but **not writable**, by this account. Nginx needs read access only to `public/`.
2. Copy `config/private.php.example` to `/etc/blog/config.php`. Set your real HTTPS origin, database and session paths. Protect the file with service-only read permission, e.g. root:blog `0640` and directory `0750`.
3. Create `/var/lib/blog`, owned by `blog`, mode `0700`. Run `BLOG_CONFIG=/etc/blog/config.php php install.php` as that service account. The database is `0600`; the session directory is `0700`. SQLite needs directory write permission for its WAL/SHM files.
4. Install `config/php-fpm.conf.example` as a dedicated pool. Adapt account names, socket path and Nginx group. Ensure the FPM PHP configuration enables the required extensions. The pool passes the private configuration path despite `clear_env=yes`.
5. Adapt `config/nginx.conf.example`, obtain TLS certificates, and run `nginx -t` and your branch's FPM configuration check before restarting services. The Nginx example executes **only** the internal front controller. It does not serve arbitrary files from the repository. FastCGI behavior follows the [Nginx documentation](https://nginx.org/en/docs/http/ngx_http_fastcgi_module.html).
6. Set `base_url` to the exact HTTPS origin. This controls CSP source URLs and Secure cookie behavior; it must match the hostname and port visitors use. Path-prefix installations are unsupported.

All HTML, redirects and blog CSS responses use `Cache-Control: no-store, private`, including public pages. Do not enable reverse-proxy/CDN/FastCGI caching. This avoids session-dependent owner controls and privacy-change leaks. The two platform assets can be cached for an hour. Public assets carry no private content. HSTS is emitted under HTTPS; enable subdomain coverage/preload only after evaluating your deployment.

Do not trust arbitrary forwarded headers. Rate limits use `REMOTE_ADDR`; if running behind a proxy, configure Nginx real-IP handling with a specific trusted proxy allowlist. Do not expose PHP-FPM's socket/port publicly.

## Configuration

Environment variables or a private PHP file specified by `BLOG_CONFIG` configure the application. FPM clears environment variables by default; configure them explicitly in the pool or use the private file.

| Environment | Private key | Default |
| --- | --- | --- |
| `BLOG_SITE_NAME` | `site_name` | `blogs.` |
| `BLOG_SITE_DESCRIPTION` | `site_description` | `a little place to write on the internet` |
| `BLOG_BASE_URL` | `base_url` | `http://localhost:8080` |
| `BLOG_DATABASE` | `database` | `storage/blog.sqlite` |
| `BLOG_SESSION_PATH` | `session_path` | `storage/sessions` |
| `BLOG_REGISTRATION` | `registration` | enabled; `0` disables |
| `BLOG_DEBUG` | `debug` | false; responses always hide diagnostics |
| `BLOG_REQUEST_LIMIT` | `request_limit` | 262144 bytes |
| `BLOG_BODY_LIMIT` | `body_limit` | 131072 bytes of Markdown |
| `BLOG_CSS_LIMIT` | `css_limit` | 65536 bytes |
| `BLOG_MERMAID_BIN` | `mermaid_bin` | `extensions/mermaid/bin/mmdr`; optional native diagram renderer |
| `BLOG_POST_LIMIT` | `post_limit` | 10000 stored posts per blog, including deleted posts |
| `BLOG_SESSION_LIFETIME` | `session_lifetime` | 86400 seconds, absolute login lifetime |
| `BLOG_ARGON_MEMORY` | `argon_memory` | 65536 KiB |
| `BLOG_ARGON_TIME` | `argon_time` | 3 iterations |
| `BLOG_BCRYPT_COST` | `bcrypt_cost` | 12 |

Keep Nginx `client_max_body_size`, PHP `post_max_size` and the application request limit aligned. URL encoding consumes part of the request budget: a large Unicode body may hit the request cap before the Markdown byte cap. Password errors distinguish minimum length, null characters and maximum length. The maximum message counts the characters in the submitted password prefix that fit within the 1024-byte UTF-8 cap. Do not lower password hashing parameters without measuring the deployment's capacity and security needs. Sessions use strict PHP file sessions, HttpOnly, SameSite=Lax and Secure under the configured HTTPS origin. HTTPS cookies use the `__Host-` prefix, with no domain attribute.

Fixed v1 rate limits: registration 5 attempts/IP/hour; login 30 attempts/IP/15 minutes and 10 attempts/normalized username/15 minutes; post creation 30 attempts/account/hour; deletion password reauthentication 10 attempts/account/15 minutes. Failed attempts count. Counters store hashed identities, expire after their window, and are pruned after a day. This is a small local abuse control, not distributed denial-of-service protection.

## CSS security and selectors

Only public blog HTML loads custom CSS. Authentication, post editors, deletion confirmations and management use the platform stylesheet. Blog HTML contains no CSRF tokens, password fields or hidden administrative forms. Owner controls are navigation links.

The preview is the **actual blog renderer**, without owner controls, at `/{username}/preview`. It requires ownership even when the blog is public. The iframe and the preview response are sandboxed with only `allow-same-origin`, which permits authenticated private CSS loading. Scripts, forms and privileged navigation are disabled inside it. Public blog documents are also sandboxed, permitting user-initiated navigation. Private CSS endpoints perform the same visibility check as private articles.

CSS is arbitrary UTF-8 CSS up to the configured byte limit. There is deliberately **no homemade CSS sanitizer**. CSP permits stylesheet requests only to the exact platform CSS and that blog's stylesheet URLs. It blocks external imports, fonts, media, connections, scripts, objects and inline styles. Blog pages and isolated previews allow HTTPS images, including external CSS backgrounds independently of Markdown. Platform, authentication and management pages retain `img-src none` and never include user CSS. Unsupported requests in `url()` or `@import` are blocked by the browser, not stripped from storage. Authors opt into the privacy implications of external images: CSS requests can disclose visitor IP addresses and conditionally signal information exposed in customizable HTML. The HTTPS image policy also permits requests to the platform origin; all application GET routes must remain read-only, with no secret-bearing content in the blog DOM. External CSS images are not validated by the Markdown URL filter and can target local-network hosts or follow redirects; CSP is not a network firewall. This is not complete CSS isolation; no privileged fields or secrets belong in blog HTML. Restoring default requires a separate confirmation and POST.

**This is a single-origin v1 with compensating isolation, not a claim that CSS is harmless.** Correct browser CSP/sandbox enforcement is a production security assumption. Custom CSS can hide or imitate public blog controls and create misleading presentation. Do not place secrets or privileged form fields in customizable HTML; keep scripts, connections and external styles blocked. A compromised/obsolete browser can bypass browser-side resource restrictions. For a stronger trust boundary, deploy a separately engineered content origin before accepting higher-risk customization; simply adding a second DNS name will not safely transfer private authentication or preview sessions. Cross-origin authentication bridging is not implemented in v1.

The customizable stylesheet is served as `text/css` with `nosniff`, never embedded into HTML. All blog pages share the same CSS endpoint. An empty stylesheet restores the packaged default. Stable selectors:

```css
.blog
.blog-header
.blog-title
.blog-description
.blog-nav
.blog-main
.blog-post-list
.blog-post
.blog-post-title
.blog-post-date
.blog-post-body
.blog-section
.blog-footer
.blog-owner
.blog-settings
.blog-table
.markdown-align-left
.markdown-align-center
.markdown-align-right
.markdown-alert
.markdown-alert-title
.markdown-alert-note
.markdown-alert-tip
.markdown-alert-warning
.hljs-*
.blog-diagram
.mermaid-diagram
.mermaid-node
.mermaid-edge
.mermaid-arrow
.mermaid-label
.mermaid-label-background
.mermaid-native
.math-block
```

## Data and routes

All variable SQL uses prepared statements. Foreign keys enforce ownership of section/post associations; unique indexes enforce account and URL namespaces. SQLite is initialized in WAL mode with foreign keys and a 5000 ms busy timeout on connections. Writes that allocate names or move content use `BEGIN IMMEDIATE`. A database trigger independently enforces the ten-section maximum. Timestamps are UTC ISO 8601.

Usernames are trimmed and converted to lowercase ASCII before validation; accepted format is 3–24 ASCII letters/digits/hyphens, without consecutive or edge hyphens. Operational and application names are centrally reserved in `config.php`. The `inicio` and `blogs` section paths are reserved. Migration 002 adds the singleton homepage field; existing sections using those paths receive the first available numeric suffix, retaining their text, posts and redirects from old post URLs. Section and post slugs use lowercase ASCII with single hyphens, a maximum 60-character initial base and numeric collision suffixes. Spanish accents are transliterated; other characters are discarded, with `post` as a fallback. Slugs remain stable on title changes. Moving posts may add a collision suffix, while old URLs redirect to their current location. Deleted post slugs are retained to avoid silently reusing their URLs.

Markdown uses [Parsedown 1.8.0](https://github.com/erusev/parsedown/tree/1.8.0) safe mode with raw HTML escaped and an additional DOM allowlist. Embeds and active attributes are disabled. Markdown images accept external HTTPS URLs with public-style DNS hostnames, no credentials, no nonstandard ports and no same-host URLs. HTTP, protocol-relative, local paths, literal IPs, common local/reserved names, data/blob URLs and malformed URLs retain escaped alternative text. Each document permits at most 16 images and 2 KiB total image URL bytes, with a 512-byte per-URL cap. Only src, alt and title survive; no-referrer, lazy loading and asynchronous decoding are generated by the platform. Images fit the text column without enlarging small images. The server does not fetch or inspect remote image bytes: no image proxy, upload feature or server-side SSRF surface is introduced. Browser decoding and SVG-as-image isolation are relied on, so this does not guarantee protection against browser decoder vulnerabilities, huge images, changing remote content, DNS rebinding or redirects to local networks. Image providers can see visitor IP addresses and may receive their own cookies; no-referrer does not prevent tracking. Private blog content remains protected locally, but its external image requests are visible to providers. Use trusted image hosts and maintained browsers; byte-level validation/re-encoding would require a separate hardened ingestion/proxy service. Markdown tables retain their contents and alignment using trusted CSS classes, with keyboard-accessible horizontal scroll containers. Heading IDs keep Unicode letters and use lowercase text with hyphenated whitespace and numeric suffixes for duplicates, so internal index links work. Long words wrap; code blocks scroll within their column. Links are restricted to HTTP(S), mailto, root-relative paths and fragments. Code blocks have a distinct dark background and server-side syntax highlighting using vendored [highlight.php](https://github.com/scrivo/highlight.php/tree/v9.18.1.10), with no browser scripts. Label fences with Bash, CSS, diff, INI, JavaScript, JSON, Markdown, PHP, Python, SQL, TypeScript, XML/HTML or YAML (common short aliases are accepted). Unknown/unlabelled languages remain plain text. Highlighting is limited to 16 KiB per block and 64 KiB per document; larger blocks remain readable escaped text. GitHub-style blockquote markers `[!NOTE]`, `[!TIP]` and `[!WARNING]` render English-labelled notices. Raw HTML is displayed as text. Mermaid fenced blocks render locally to SVG. With mmdr installed, the stress corpus covers flowcharts (including subgraphs, dotted links and dense graphs), sequence, state, class, ER, Gantt, pie and mind-map diagrams. Output is parsed with a strict SVG element/attribute allowlist: scripts, HTML, links, images, styles and external resource references are removed; local marker IDs are isolated per diagram. Inline presentation attributes preserve the native dark theme without weakening CSP. Diagram canvases are transparent. Diagrams retain their natural dimensions and font sizes; only diagrams wider than the content column shrink proportionally to fit, without horizontal scrolling. Small diagrams are never enlarged. Limits: 8 KiB source, 512 KiB SVG, 50,000-unit dimensions, sixteen diagrams per document, two seconds per native render and six seconds shared across the document. Render failures preserve escaped source. Native rendering requires PHP `proc_open`; no rendered-content files or caches are created. Keep the executable owned by the deployer and unreadable for writing by PHP-FPM. If it is absent, the source remains readable as a code block.

Mathematics is converted locally to native MathML using pinned [latex2mathml-php](https://github.com/snorky22/latex2mathml-php/tree/10c2aed0bec21f3e34715ec9d10a7d0e5dae41a3). Delimiters: `$…$`, `\(…\)`, `$$…$$`, `\[…\]`, and fenced `math` blocks. TeX is protected from Markdown's escaping/emphasis parser; ordinary code, escaped dollars and unpaired currency remain text. Fractions, radicals, scripts, matrices, aligned systems, labelled arrows and Unicode annotations in the 44-formula stress corpus render without JavaScript, external fonts or styles. A strict MathML allowlist removes active/resource attributes. Limits: 4 KiB per formula, 48 brace levels, 128 formulas and 64 KiB of formula source per document. Errors or unsupported expressions retain escaped source. Two corrections to the vendored matrix converter preserve grouped content in the final cell and the actual position of horizontal rules (documented in its vendored README). Wide formulas scroll within their own accessible block instead of overflowing the page. Modern native MathML support is a browser requirement; Firefox was tested. This is math-mode TeX, not a complete LaTeX document engine, and does not support arbitrary packages/TikZ. Task lists and footnotes are not interpreted; backslash hard breaks are unsupported by this parser (use two trailing spaces). Ordinary text is escaped with UTF-8 context-safe HTML escaping.

Primary routes are `/`, `/register`, `/login`, POST `/logout`, `/{username}`, `/{username}/blogs`, `/{username}/style.css`, `/{username}/new`, `/{username}/edit/{post-id}`, `/{username}/delete/{post-id}`, `/{username}/{section}`, `/{username}/posts/{slug}`, and `/{username}/{section}/{slug}`. Management routes are `/manage/sections`, `/manage/appearance`, `/manage/settings`, with explicit edit/delete/reorder/restore suffixes. Mutations require POST and CSRF; successful mutations redirect with 303. Wrong methods return 405. Private or missing content returns 404; protected routes return 401 and cross-owner attempts return 403/404. Validation failures preserve non-password fields. HTTPS sessions are regenerated on registration/login.

## Tests

```sh
./bin/test.sh
# On a machine where local sockets are permitted:
./bin/test.sh --http
```

Optional browser audit (requires Firefox and a running local server):

```sh
python3 tests/browser.py http://localhost:8080/avz
```

It opens an isolated temporary Firefox profile with website JavaScript disabled, checks column overflow and internal anchors at three widths, performs a native link click, and writes a screenshot to `/tmp/blog-browser-audit.png`. Marionette is used only for test instrumentation, following the [Mozilla protocol](https://firefox-source-docs.mozilla.org/remote/marionette/Protocol.html). The temporary browser/profile are removed afterward.

The default suite runs 184 core checks when the native renderer is installed (native-corpus checks are explicitly skipped otherwise) and 32 integration scenarios through the real front controller, PDO database and file sessions using a socket-free CLI harness. The concurrency check starts twelve independent PHP processes against one database and requires exactly ten section creations to succeed. The HTTP mode runs the same integration scenarios against the PHP development server using native Python HTTP/cookie handling. Neither mode requires a test framework or frontend dependencies. Test storage is isolated under temporary directories. Test-only Argon2 parameters are reduced for speed.

Core/request tests, local HTTP integration tests and a Firefox layout/anchor audit with website JavaScript disabled have been executed. Local sockets require execution outside the restricted sandbox. Working `doas`, Nginx and PHP-FPM were unavailable in that environment. The browser audit checked /avz and both stress posts at 500, 768 and 1280 pixels, with all 44 formulas rendered and all ten native diagrams fitting their containers without horizontal scrolling: no page-wide horizontal overflow, all internal heading links resolved, and a native click reached its heading. Complete CSP enforcement and Nginx/FPM deployment still need verification on the target server; no production-server test result is claimed.

## Backups, deletion and recovery

Back up the SQLite database with SQLite's online backup mechanism, not by copying only `blog.sqlite` while writers are active. For example, with the SQLite CLI installed:

```sh
sqlite3 /var/lib/blog/blog.sqlite ".backup '/secure-backups/blog.sqlite'"
```

Keep backups private, encrypted where appropriate, and test restoration. Stop FPM before restoring a backup, restore permissions, rerun migrations, and clear the session directory to force new logins. Do not restore obsolete WAL/SHM files alongside a newer database backup.

Post deletion is soft deletion: the application exposes no deleted articles, but the records remain for trusted administrative recovery and count toward the post cap. There is no recovery UI. Account/blog deletion checks the password and exact typed username, then cascades through all content and styles in one transaction. No generated content files exist. Every authenticated request resolves the current database user ID; deleted IDs are never reused, so other outstanding sessions immediately lose authority, even if the username is registered again. The current session is destroyed and its cookie expired. Other stale session files are garbage-collected normally; keep session storage private and configure regular cleanup.

Deletion does **not** erase already taken backups. Set and communicate a finite backup retention period for your service, purge expired backups, and handle deletion requests if restoring older snapshots. There is no email, password reset, security question or automatic account recovery. A separate trusted administrator can reset a password using `password_make()` in a controlled CLI procedure after independently verifying ownership, then remove file sessions to revoke existing logins. Never log or place passwords in shell arguments or command history.

## Deliberate limits

One blog/account; ten sections; no self-service username changes, custom HTML/JavaScript, media, comments, RSS, API or email recovery. No CSS revision history, static export or shared page cache. Interface strings are centralized in `strings.txt` (UTF-8, `key = "text"` format). Edit values while preserving keys and quotes; changes apply on the next request. Preserve placeholders such as `{n}`. Site name and description remain configurable in `config.php`; addon labels live in their descriptors, and user content remains in SQLite. Private blogs are owner-only and share the same backend authorization on all endpoints. Previously viewed or downloaded public content cannot be recalled from visitors when privacy changes. SQLite serializes writes; tune PHP-FPM capacity and hash cost for the actual machine before opening public registration.

## Addons and compact layout

Third-party code lives in `libraries/` and each extension’s `libraries/` directory. Runtime code is eight PHP files: `public/index.php`, `public/blog.php`, `public/manage.php`, `app.php`, `config.php`, `render.php`, `markdown.php` and `addons.php`. The internal front controller dispatches to the blog and management controllers; arbitrary PHP URLs remain blocked. `install.php` contains the versioned schema migrations; there is no separate migration directory. `tests/`, `bin/test.sh` and the deployment examples under `config/` are development/deployment material and can be omitted from a production release. Keep `bin/test-server.php` only for the local development server.

`extensions/math`, `extensions/mermaid` and `extensions/highlight` each contain their descriptor, rendering code, stylesheet and dependencies. All three are user addons. Without the native executable, Mermaid retains escaped source. Disabled math is ordinary Markdown; disabled highlighting is plain code. Source is always retained. Upgrading with `php install.php` enables the shipped user addons for existing blogs; new blogs use each user descriptor's `default` flag. Later user additions do not silently activate themselves on existing blogs.

### Types and activation

Place `addon.php` inside `extensions/<id>/`. IDs match `[a-z][a-z0-9-]{0,31}`. A descriptor returns a PHP array:

```php
<?php
return [
    'api' => 1,
    'type' => 'user', // 'user' or 'admin'; required, no scope alias
    'label' => ['en' => 'addon name'],
    'default' => false, // user addons only
    // 'style' => __DIR__.'/style.css',
    // Optional callbacks documented below.
];
```

* **user:** enabled independently at `/manage/addons` through native checkboxes and a CSRF-protected POST. Selection lives in `blog_addons(blog_id, addon_id)`, with an account-deletion cascade. It affects that blog's homepage, sections, posts and preview.
* **admin:** automatically active when its folder is installed, independently of blog selections. It does not appear in the checklist, cannot be selected through that form, and does not create activation rows. `default` has no effect. Its callbacks determine where it intervenes; global activation does not inject every addon into every page.

`addons('user')` and `addons('admin')` filter the common registry. `active_addons($blog)` combines selected user addons and all admin addons; `active_addons()` returns only admin addons. Discovery and validation happen once per PHP request in filesystem order. Keep descriptors cheap; load libraries with `require_once` inside callbacks. Namespace or prefix functions/classes to prevent collisions. Invalid API/type/callback descriptors are skipped with a short server-side diagnostic. There is no package manager, remote discovery, upload interface or client-side engine.

Removing an admin folder immediately removes its hooks, routes and styles. Removing a user folder also removes it from the checklist and prevents execution; saved activation rows remain until the owner saves another selection, allowing folder restoration to recover activation. Neither removal nor disabling deletes extension content. The type describes activation, not a PHP security sandbox.

### Integration points

* **Markdown (both types):** `parser(AddonMarkdown $parser): void` registers rules through `add_inline(array $markers, callable $callback)` and `add_block(array $markers, callable $start, ?callable $continue, ?callable $complete)`. Return native Parsedown rule arrays, or null for no match. `render(DOMDocument $doc, array $context): void` transforms the sanitized document afterward. Context contains `blog`, or null for platform Markdown. User rules run only for selected blogs; admin rules apply globally. The shipped math addon demonstrates pre-parsing rules. Safe `language-*` fence classes and `addon-*` code markers survive until rendering callbacks finish, then are removed. Each addon must sanitize newly generated markup and bound its resource use.
* **Blog slots (both types):** `slots['nav'](array $context): void` adds navigation content; `slots['post_after'](...)` adds content after an article. Context contains `blog` and `post` (null for navigation). These also run in owner previews. Escape output, respect draft/privacy checks, and never place secrets or privileged forms inside customizable blog HTML. Blog sandbox still blocks forms and scripts.
* **Homepage slot (admin only):** `slots['home_after'](array $context): void` runs after the platform homepage's main introduction/navigation, inside its main element. Both `blog` and `post` are null. It never runs on login, registration or management pages. Native forms can submit here, with `token_field()` and the core POST protections. This supports a future global guestbook without altering the blog UI.
* **Routes:** callbacks under `routes['public']['GET'|'POST']` serve `/{username}/_addons/<id>/<path>`; `routes['manage'][...]` serve `/manage/addons/<id>/<path>`. Both types may declare these blog routes. Admin addons alone may also declare `routes['platform'][...]`, served at `/_addons/<id>/<path>`. Context is `['addon'=>$id, 'blog'=>$blogOrNull, 'path'=>$segments]`. Return true after handling a path or false for 404. Core enforces activation, methods, blog privacy, management ownership, POST CSRF and thirty POST attempts per hour per addon/blog/IP (platform uses its own namespace). Callbacks must validate fields and referenced posts/sections themselves, including draft access, and redirect successful mutations with `redirect()` (303). Use `page()` for forms outside customizable blog content.
* **Request completion (admin only):** `request_finished(array $context): void` observes dynamic PHP requests at shutdown, including redirects and handled errors. Context contains only `path` (query removed, bounded to 256 bytes), `method`, `status` and `duration_ms` measured from observer registration. No passwords, form fields, cookies, session identifiers, IP addresses, response bodies or query strings are supplied. Static assets served directly by Nginx/test-server do not trigger it. Observers must not alter responses; accidental buffered output is discarded, exceptions are logged by addon ID and remaining observers continue. This is a hook, not an implemented statistics service or a sandbox for malicious PHP.

### Styles and access

Each addon may declare exactly its own `style.css`. User CSS is prepended to its enabled blog's protected stylesheet response. Admin CSS joins blog styles only when it declares a Markdown or blog-rendering hook. Admin addons with `home_after` get an explicit stylesheet link on the platform homepage; authentication and normal management pages do not inherit it. An admin route can opt its page into its stylesheet using `page(..., addon: $context['addon'])`. The core serves admin CSS at `/_addons/<id>/style.css` (GET only, reserved from callbacks) and adds only the exact requested stylesheet URLs to CSP. User stylesheet endpoints continue to require blog privacy checks and are unavailable under this global path. Scripts, inline styles and other external resources remain blocked; blog and preview images follow the HTTPS-only image policy; platform and management images remain blocked.

**Admin type is global activation, not an access-control role.** Platform addon routes are public unless the callback applies additional authorization. There is no platform-administrator account role. Do not expose privileged reports/moderation merely because a descriptor says admin; those require an explicit operator authentication policy. Existing manage routes authorize only the current blog's owner, even for admin addons.

A future guestbook, comments addon, player or statistics service is not implemented. Persistent addons need an explicit CLI setup/migration procedure when they first introduce storage; never migrate on page views. Data belonging to blogs must reference `blogs.id` with cascading deletion and queries scoped to that blog. Platform-wide data needs a deliberate retention/cleanup policy. Automatic discovery activates callbacks; it is not permission to modify schemas on GET. No extension rewrites source files or replaces PHP functions; new behavior uses explicit hooks.

**Trust boundary:** both types are trusted server-side PHP installed by the operator, not blog owners. Keep all code, styles and executables owned by the deployer and non-writable by PHP-FPM. The checklist grants use of installed functionality, never permission to install executable code. Addons have the process's full PHP/database privileges; type filtering is not isolation between extensions.

The first successful registration becomes the platform administrator, assigned inside the account transaction. On upgrade, migration 004 assigns the earliest surviving user by internal ID. `/manage/admin` is visible and accessible only to that account; admin PHP addons remain a separate concept and do not grant account privileges. The screen lists users in pages of 50 and lets the administrator use a public blog as `/`, or restore the original platform homepage. The selected blog retains its canonical URLs, stylesheet, owner checks and privacy; making it private makes `/` unavailable to visitors, while deleting it restores the platform homepage. Admin status does not grant access to private blogs or other owners' editors. Mutations require POST and CSRF. Deleting the admin account does not promote another account or a later registration: a trusted operator must recover admin access by setting `platform.admin_user_id` to an existing user ID through SQLite, keeping `admin_initialized=1`. Back up the database before administrative recovery. Re-run `php install.php` when upgrading.

Administrators can edit account usernames, optionally replace passwords and change blog visibility at `/manage/admin/users/{id}/edit`, or delete accounts at `/manage/admin/users/{id}/delete`. Deletion requires their own administrator password and the target username, with CSRF and reauthentication rate limiting; deleting oneself logs out and does not promote another account. Migration 005 adds authentication versions and username aliases. Password replacement revokes previous sessions; changing one's own admin password regenerates the current session and retains access. Renaming preserves stable user/blog IDs and redirects previous username paths on GET after privacy checks; old names remain reserved to that account until deletion. Old-username POST routes are rejected, not replayed. These admin screens always use platform CSS. Blog titles and existing section/post slugs are not changed by account renaming.
