# blogs.

A small multi-user blog engine: PHP 8.4/8.5, PDO SQLite, native forms and CSS. All interface text is Spanish. No JavaScript, framework, build process, uploads or external runtime services.

Users register and immediately receive `/{username}`. Each account has one blog with two built-in sections: `inicio` at `/{username}` holds one editable Markdown text, and `blogs` at `/{username}/blogs` lists its posts. The homepage text is editable from its pencil link or `/manage/sections/home`. These permanent pages do not count toward the ten additional custom sections. Each blog also has drafts and published posts, one global stylesheet, private/public visibility and password-confirmed account deletion. Owner actions appear inside their blog; `/manage` has sections, appearance, settings and addons. Section removal and post moves preserve old article URLs through redirects.

## Install

Requirements: PHP **8.4 or 8.5**, `pdo_sqlite`, `mbstring`, `dom`, PHP-FPM and Nginx. Argon2id is preferred when compiled into PHP. Python 3 and PHP `pcntl` are needed only for tests. SQLite must support WAL, expression indexes and UPSERT (3.24+).

```sh
git clone <your-repository-url> blog
cd blog
php install.php
# Enable broad Mermaid diagram support (Linux x86_64, curl + tar):
php extensions/mermaid/install.php
```

The installer creates private storage and runs versioned migrations transactionally. It is safe to run again. Do not expose the repository root through the web server. Only `public/` is a document root. Parsedown 1.8.0 and a pinned PHP LaTeX-to-MathML converter are vendored with their MIT licenses; Composer is unnecessary. The separate renderer installer downloads the official mmdr 0.3.1 Linux x86_64 GNU executable, verifies a fixed SHA-256 digest and atomically installs it in `extensions/mermaid/bin/mmdr`. That machine-specific binary is excluded from source control. No diagram source is sent to a service. On other platforms (including FreeBSD or musl Linux), install a compatible [mermaid-rs-renderer](https://github.com/1jehuang/mermaid-rs-renderer/tree/v0.3.1) executable, e.g. `cargo install mermaid-rs-renderer --version 0.3.1 --locked`, and set `BLOG_MERMAID_BIN`/`mermaid_bin` to its absolute path. Rust is needed only if building that executable yourself. Without the executable, Mermaid blocks retain their escaped source.

On Void, install the versioned PHP packages (or the distribution's `php`, `php-fpm`, `php-sqlite` meta-packages), enable `pdo_sqlite` in PHP's configuration, and check both CLI and FPM extension configuration. See the [Void PHP handbook](https://docs.voidlinux.org/config/php.html). With the extension installed but disabled, use `php -d extension=pdo_sqlite install.php`.

```sh
doas xbps-install -S php8.5 php8.5-fpm php8.5-sqlite nginx
```

Use the installed service names under `/etc/sv/` to enable PHP-FPM and Nginx with runit after configuring them. Package/service names depend on the selected PHP branch.

On FreeBSD, choose a supported PHP branch using `pkg search php85` or `pkg search php84`; install that branch's PHP, PDO SQLite, mbstring and DOM packages plus Nginx. Enable `php_fpm` and `nginx` using `sysrc`, then start their services. FPM configuration conventionally lives under `/usr/local/etc/`; adapt the pool and socket to `/var/run/` and Nginx's service group. See [FreeBSD PHP-FPM](https://man.freebsd.org/cgi/man.cgi?query=php-fpm&sektion=8).

## Run locally

```sh
php -d extension=pdo_sqlite install.php
php -d extension=pdo_sqlite -S localhost:8080 -t public bin/dev-router.php
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

CSS is arbitrary UTF-8 CSS up to the configured byte limit. There is deliberately **no homemade CSS sanitizer**. CSP permits stylesheet requests only to the exact platform CSS and that blog's stylesheet URLs. It blocks external imports, images, fonts, media, connections, scripts, objects and inline styles. Unsupported requests in `url()` or `@import` are blocked by the browser, not stripped from storage. V1 does not support images or font resources. Restoring default requires a separate confirmation and POST.

**This is a single-origin v1 with compensating isolation, not a claim that CSS is harmless.** Correct browser CSP/sandbox enforcement is a production security assumption. Custom CSS can hide or imitate public blog controls and create misleading presentation. Do not place secrets or privileged form fields in customizable HTML or relax the CSP. A compromised/obsolete browser can bypass browser-side resource restrictions. For a stronger trust boundary, deploy a separately engineered content origin before accepting higher-risk customization; simply adding a second DNS name will not safely transfer private authentication or preview sessions. Cross-origin authentication bridging is not implemented in v1.

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

Usernames are trimmed and converted to lowercase ASCII before validation; accepted format is 3–24 ASCII letters/digits/hyphens, without consecutive or edge hyphens. Operational and application names are centrally reserved in `config.php`. The `inicio` and `blogs` section paths are reserved. Migration 002 adds the singleton homepage field; existing sections using those paths receive the first available numeric suffix, retaining their text, posts and redirects from old post URLs. Section and post slugs use lowercase ASCII with single hyphens, a maximum 60-character initial base and numeric collision suffixes. Spanish accents are transliterated; other characters are discarded, with `entrada` as a fallback. Slugs remain stable on title changes. Moving posts may add a collision suffix, while old URLs redirect to their current location. Deleted post slugs are retained to avoid silently reusing their URLs.

Markdown uses [Parsedown 1.8.0](https://github.com/erusev/parsedown/tree/1.8.0) safe mode with raw HTML escaped and an additional DOM allowlist. Embeds, image requests and active attributes are disabled. Image syntax renders only its escaped alternative text. Markdown tables retain their contents and alignment using trusted CSS classes, with keyboard-accessible horizontal scroll containers. Heading IDs keep Unicode letters and use lowercase text with hyphenated whitespace and numeric suffixes for duplicates, so internal index links work. Long words wrap; code blocks scroll within their column. Links are restricted to HTTP(S), mailto, root-relative paths and fragments. Code blocks have a distinct dark background and server-side syntax highlighting using vendored [highlight.php](https://github.com/scrivo/highlight.php/tree/v9.18.1.10), with no browser scripts. Label fences with Bash, CSS, diff, INI, JavaScript, JSON, Markdown, PHP, Python, SQL, TypeScript, XML/HTML or YAML (common short aliases are accepted). Unknown/unlabelled languages remain plain text. Highlighting is limited to 16 KiB per block and 64 KiB per document; larger blocks remain readable escaped text. GitHub-style blockquote markers `[!NOTE]`, `[!TIP]` and `[!WARNING]` render Spanish-labelled notices. Raw HTML is displayed as text. Mermaid fenced blocks render locally to SVG. With mmdr installed, the stress corpus covers flowcharts (including subgraphs, dotted links and dense graphs), sequence, state, class, ER, Gantt, pie and mind-map diagrams. Output is parsed with a strict SVG element/attribute allowlist: scripts, HTML, links, images, styles and external resource references are removed; local marker IDs are isolated per diagram. Inline presentation attributes preserve the native dark theme without weakening CSP. Diagram canvases are transparent. Diagrams retain their natural dimensions and font sizes; only diagrams wider than the content column shrink proportionally to fit, without horizontal scrolling. Small diagrams are never enlarged. Limits: 8 KiB source, 512 KiB SVG, 50,000-unit dimensions, sixteen diagrams per document, two seconds per native render and six seconds shared across the document. Render failures preserve escaped source. Native rendering requires PHP `proc_open`; no rendered-content files or caches are created. Keep the executable owned by the deployer and unreadable for writing by PHP-FPM. If it is absent, the source remains readable as a code block.

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

The default suite runs 164 core checks when the native renderer is installed (native-corpus checks are explicitly skipped otherwise) and 22 integration scenarios through the real front controller, PDO database and file sessions using a socket-free CLI harness. The concurrency check starts twelve independent PHP processes against one database and requires exactly ten section creations to succeed. The HTTP mode runs the same integration scenarios against the PHP development server using native Python HTTP/cookie handling. Neither mode requires a test framework or frontend dependencies. Test storage is isolated under temporary directories. Test-only Argon2 parameters are reduced for speed.

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

One blog/account; ten sections; no username changes, custom HTML/JavaScript, media, comments, RSS, API or email recovery. No CSS revision history, static export or shared page cache. Source strings are centralized in `config.php`. Private blogs are owner-only and share the same backend authorization on all endpoints. Previously viewed or downloaded public content cannot be recalled from visitors when privacy changes. SQLite serializes writes; tune PHP-FPM capacity and hash cost for the actual machine before opening public registration.

## Addons and compact layout

Runtime code is eight PHP files: `public/index.php`, `public/blog.php`, `public/manage.php`, `app.php`, `config.php`, `render.php`, `markdown.php` and `addons.php`. The internal front controller dispatches to the blog and management controllers; arbitrary PHP URLs remain blocked. `install.php` contains the versioned schema migrations through `app.php`; there is no separate migration directory. `tests/`, `bin/test.sh` and the deployment examples under `config/` are development/deployment material and can be omitted from a production release. Keep `bin/dev-router.php` only for the local development server.

`extensions/math`, `extensions/mermaid` and `extensions/highlight` each contain their descriptor, rendering code, stylesheet and dependencies. There is no handwritten Mermaid fallback: the optional native executable handles diagrams, or escaped source remains visible. Disabled math is ordinary Markdown; disabled highlighting is plain code. All source is retained in the database. Upgrading with `php install.php` enables the shipped addons for existing blogs; new blogs use each descriptor's `default` flag. Later additions do not silently activate themselves on existing blogs.

Owners choose enabled addons at `/manage/addons` with native checkboxes and a CSRF-protected POST. Selection is stored in `blog_addons(blog_id, addon_id)` with an account-deletion cascade. Selection affects homepage, sections, posts and preview. Enabled addon styles are prepended to the blog's protected `style.css` response, so custom CSS can override them. They never enter authentication, management or editor pages. Their resources stay outside the document root.

To create an addon, place `addon.php` inside `extensions/<id>/`. IDs must match `[a-z][a-z0-9-]{0,31}`. The descriptor returns a PHP array:

```php
<?php
return [
    'api' => 1,
    'label' => ['es' => 'nombre del addon'],
    'default' => false,
    // 'style' => __DIR__.'/style.css',
    // Optional callbacks documented below.
];
```

The registry discovers descriptors on each request and validates their API and callbacks. Keep descriptors cheap; load libraries with `require_once` inside their callbacks. No upload interface, package manager, remote discovery or client-side code is involved. Missing addons disappear from the checklist and cannot run. Their saved activation rows remain until the owner saves a new selection, so restoring a folder before that restores its activation. Disabling or removing a folder does not delete future addon content.

The three integration points are:

* **Markdown:** `parser(AddonMarkdown $parser): void` registers rules before parsing through `add_inline(array $markers, callable $callback)` and `add_block(array $markers, callable $start, ?callable $continue, ?callable $complete)`. Callbacks return native Parsedown rule arrays, or `null` when they do not match. `render(DOMDocument $doc, array $context): void` transforms the sanitized document afterward; `$context['blog']` supplies the blog. Safe `language-*` fence classes and `addon-*` code markers survive until these callbacks finish, then are removed. The shipped addons demonstrate both approaches. Each addon must sanitize any new markup it generates and bound its resource use; core sanitization cannot validate arbitrary addon output.
* **Routes:** `routes['public']['GET'|'POST']` receives `['blog'=>$blog, 'path'=>$segments]` for `/{username}/_addons/<id>/<path>`. `routes['manage'][...]` receives the same context under `/manage/addons/<id>/<path>`. Return `true` after handling a path, `false` for 404. Core checks enabled status, privacy, management ownership, allowed methods and POST CSRF/rate limits first. Callbacks must validate their own inputs and target post/section ownership through stable IDs; public routes must also check draft visibility when referencing posts. Use `page()` and native forms for submissions, keeping tokens outside customizable blog HTML. Successful mutations must use `redirect()` (303).
* **Render locations:** `slots['nav'](array $context): void` adds navigation content; `slots['post_after'](...)` adds content after an article. Context contains `blog` and `post` (null for navigation). Output must be escaped and free of privileged forms or secrets; blog CSP still blocks forms and scripts. Slots also render in owner previews, subject to the same sandbox.

A future guestbook or comments addon can use these routes and slots, but neither feature is implemented. Add schema changes to an explicit CLI installation procedure when an addon first needs storage; never migrate on page views. Addon rows must reference `blogs.id` with cascading deletion, and their queries must scope data to that blog. Do not place private files under `public/`.

**Trust boundary:** addons are trusted server-side PHP installed by the operator, not sandboxed code supplied by blog owners. The application does not isolate PHP extensions from each other or from the database. Keep all extension code, styles and executables owned by the deployer and non-writable by PHP-FPM. The checklist grants use of installed functionality, never permission to install executable code.
