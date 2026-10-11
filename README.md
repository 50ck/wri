# blogs.

A small multi-user blog engine: PHP 8.4/8.5, PDO SQLite, native forms and CSS. All interface text is Spanish. No JavaScript, framework, build process, uploads or external runtime services.

Users register and immediately receive `/{username}`. Each account has one blog with two built-in sections: `inicio` at `/{username}` holds one editable Markdown text, and `blogs` at `/{username}/blogs` lists its posts. The homepage text is editable from its pencil link or `/manage/sections/home`. These permanent pages do not count toward the ten additional custom sections. Each blog also has drafts and published posts, one global stylesheet, private/public visibility and password-confirmed account deletion. Owner actions appear inside their blog; `/manage` has exactly three sections. Section removal and post moves preserve old article URLs through redirects.

## Install

Requirements: PHP **8.4 or 8.5**, `pdo_sqlite`, `mbstring`, `dom`, PHP-FPM and Nginx. Argon2id is preferred when compiled into PHP. Python 3 and PHP `pcntl` are needed only for tests. SQLite must support WAL, expression indexes and UPSERT (3.24+).

```sh
git clone <your-repository-url> blog
cd blog
php bin/install.php
```

The installer creates private storage and runs versioned migrations transactionally. It is safe to run again. Do not expose the repository root through the web server. Only `public/` is a document root. Parsedown 1.7.4 is vendored with its MIT license; Composer is unnecessary.

On Void, install the versioned PHP packages (or the distribution's `php`, `php-fpm`, `php-sqlite` meta-packages), enable `pdo_sqlite` in PHP's configuration, and check both CLI and FPM extension configuration. See the [Void PHP handbook](https://docs.voidlinux.org/config/php.html). With the extension installed but disabled, use `php -d extension=pdo_sqlite bin/install.php`.

```sh
doas xbps-install -S php8.5 php8.5-fpm php8.5-sqlite nginx
```

Use the installed service names under `/etc/sv/` to enable PHP-FPM and Nginx with runit after configuring them. Package/service names depend on the selected PHP branch.

On FreeBSD, choose a supported PHP branch using `pkg search php85` or `pkg search php84`; install that branch's PHP, PDO SQLite, mbstring and DOM packages plus Nginx. Enable `php_fpm` and `nginx` using `sysrc`, then start their services. FPM configuration conventionally lives under `/usr/local/etc/`; adapt the pool and socket to `/var/run/` and Nginx's service group. See [FreeBSD PHP-FPM](https://man.freebsd.org/cgi/man.cgi?query=php-fpm&sektion=8).

## Run locally

```sh
php -d extension=pdo_sqlite bin/install.php
php -d extension=pdo_sqlite -S localhost:8080 -t public bin/dev-router.php
```

Open `http://localhost:8080`. If SQLite is already enabled, omit `-d extension=pdo_sqlite`. The development router exposes only the two platform CSS assets and routes everything else through the application. The PHP development server is for local use only.

## Production

1. Create a dedicated service account, e.g. `blog`. Source files should be owned by the deployer/root and readable, but **not writable**, by this account. Nginx needs read access only to `public/`.
2. Copy `config/private.php.example` to `/etc/blog/config.php`. Set your real HTTPS origin, database and session paths. Protect the file with service-only read permission, e.g. root:blog `0640` and directory `0750`.
3. Create `/var/lib/blog`, owned by `blog`, mode `0700`. Run `BLOG_CONFIG=/etc/blog/config.php php bin/install.php` as that service account. The database is `0600`; the session directory is `0700`. SQLite needs directory write permission for its WAL/SHM files.
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
```

## Data and routes

All variable SQL uses prepared statements. Foreign keys enforce ownership of section/post associations; unique indexes enforce account and URL namespaces. SQLite is initialized in WAL mode with foreign keys and a 5000 ms busy timeout on connections. Writes that allocate names or move content use `BEGIN IMMEDIATE`. A database trigger independently enforces the ten-section maximum. Timestamps are UTC ISO 8601.

Usernames are trimmed and converted to lowercase ASCII before validation; accepted format is 3–24 ASCII letters/digits/hyphens, without consecutive or edge hyphens. Operational and application names are centrally reserved in `src/config.php`. The `inicio` and `blogs` section paths are reserved. Migration 002 adds the singleton homepage field; existing sections using those paths receive the first available numeric suffix, retaining their text, posts and redirects from old post URLs. Section and post slugs use lowercase ASCII with single hyphens, a maximum 60-character initial base and numeric collision suffixes. Spanish accents are transliterated; other characters are discarded, with `entrada` as a fallback. Slugs remain stable on title changes. Moving posts may add a collision suffix, while old URLs redirect to their current location. Deleted post slugs are retained to avoid silently reusing their URLs.

Markdown uses [Parsedown 1.7.4](https://github.com/erusev/parsedown/tree/1.7.4) safe mode with raw HTML escaped and an additional DOM allowlist. No extensions, embeds, images or active attributes survive. Links are restricted to HTTP(S), mailto, root-relative paths and fragments. Code blocks have no client-side highlighting. Ordinary text is escaped with UTF-8 context-safe HTML escaping.

Primary routes are `/`, `/register`, `/login`, POST `/logout`, `/{username}`, `/{username}/blogs`, `/{username}/style.css`, `/{username}/new`, `/{username}/edit/{post-id}`, `/{username}/delete/{post-id}`, `/{username}/{section}`, `/{username}/posts/{slug}`, and `/{username}/{section}/{slug}`. Management routes are `/manage/sections`, `/manage/appearance`, `/manage/settings`, with explicit edit/delete/reorder/restore suffixes. Mutations require POST and CSRF; successful mutations redirect with 303. Wrong methods return 405. Private or missing content returns 404; protected routes return 401 and cross-owner attempts return 403/404. Validation failures preserve non-password fields. HTTPS sessions are regenerated on registration/login.

## Tests

```sh
./bin/test.sh
# On a machine where local sockets are permitted:
./bin/test.sh --http
```

The default suite runs 61 core checks and 19 integration scenarios through the real front controller, PDO database and file sessions using a socket-free CLI harness. The concurrency check starts twelve independent PHP processes against one database and requires exactly ten section creations to succeed. The HTTP mode runs the same integration scenarios against the PHP development server using native Python HTTP/cookie handling. Neither mode requires a test framework or frontend dependencies. Test storage is isolated under temporary directories. Test-only Argon2 parameters are reduced for speed.

Core/request tests and local HTTP integration tests have been executed. Local sockets require execution outside the restricted sandbox. Working `doas`, Nginx and PHP-FPM were unavailable in that environment. Browser presentation/CSP enforcement and Nginx/FPM deployment still need verification on the target server; no browser or production-server test result is claimed.

## Backups, deletion and recovery

Back up the SQLite database with SQLite's online backup mechanism, not by copying only `blog.sqlite` while writers are active. For example, with the SQLite CLI installed:

```sh
sqlite3 /var/lib/blog/blog.sqlite ".backup '/secure-backups/blog.sqlite'"
```

Keep backups private, encrypted where appropriate, and test restoration. Stop FPM before restoring a backup, restore permissions, rerun migrations, and clear the session directory to force new logins. Do not restore obsolete WAL/SHM files alongside a newer database backup.

Post deletion is soft deletion: the application exposes no deleted articles, but the records remain for trusted administrative recovery and count toward the post cap. There is no recovery UI. Account/blog deletion checks the password and exact typed username, then cascades through all content and styles in one transaction. No generated content files exist. Every authenticated request resolves the current database user ID; deleted IDs are never reused, so other outstanding sessions immediately lose authority, even if the username is registered again. The current session is destroyed and its cookie expired. Other stale session files are garbage-collected normally; keep session storage private and configure regular cleanup.

Deletion does **not** erase already taken backups. Set and communicate a finite backup retention period for your service, purge expired backups, and handle deletion requests if restoring older snapshots. There is no email, password reset, security question or automatic account recovery. A separate trusted administrator can reset a password using `password_make()` in a controlled CLI procedure after independently verifying ownership, then remove file sessions to revoke existing logins. Never log or place passwords in shell arguments or command history.

## Deliberate limits

One blog/account; ten sections; no username changes, custom HTML/JavaScript, media, comments, RSS, API or email recovery. No CSS revision history, static export or shared page cache. Source strings are centralized in `src/strings.php`. Private blogs are owner-only and share the same backend authorization on all endpoints. Previously viewed or downloaded public content cannot be recalled from visitors when privacy changes. SQLite serializes writes; tune PHP-FPM capacity and hash cost for the actual machine before opening public registration.
