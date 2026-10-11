<?php
declare(strict_types=1);
function config(): array {
    static $config;
    if ($config !== null) return $config;
    $root = __DIR__;
    $config = [
        'site_name' => getenv('BLOG_SITE_NAME') ?: 'blogs.',
        'site_description' => getenv('BLOG_SITE_DESCRIPTION') ?: 'un pequeño lugar para escribir en internet',
        'base_url' => rtrim(getenv('BLOG_BASE_URL') ?: 'http://localhost:8080', '/'),
        'database' => getenv('BLOG_DATABASE') ?: "$root/storage/blog.sqlite",
        'session_path' => getenv('BLOG_SESSION_PATH') ?: "$root/storage/sessions",
        'registration' => getenv('BLOG_REGISTRATION') !== '0',
        'debug' => getenv('BLOG_DEBUG') === '1',
        'request_limit' => (int)(getenv('BLOG_REQUEST_LIMIT') ?: 262144),
        'body_limit' => (int)(getenv('BLOG_BODY_LIMIT') ?: 131072),
        'css_limit' => (int)(getenv('BLOG_CSS_LIMIT') ?: 65536),
        'post_limit' => (int)(getenv('BLOG_POST_LIMIT') ?: 10000),
        'session_lifetime' => (int)(getenv('BLOG_SESSION_LIFETIME') ?: 86400),
        'argon_memory' => (int)(getenv('BLOG_ARGON_MEMORY') ?: 65536),
        'argon_time' => (int)(getenv('BLOG_ARGON_TIME') ?: 3),
        'bcrypt_cost' => (int)(getenv('BLOG_BCRYPT_COST') ?: 12),
    ];
    $private = getenv('BLOG_CONFIG');
    if ($private) $config = array_replace($config, require $private);
    $url = parse_url($config['base_url']);
    if (!$url || !in_array($url['scheme'] ?? '', ['http', 'https'], true) || empty($url['host']) || isset($url['user']) || isset($url['query']) || isset($url['fragment']) || !empty($url['path'])) throw new RuntimeException('Invalid base URL; use an origin without a path.');
    return $config;
}
function reserved_names(): array {
    return ['admin','administrator','api','login','logout','register','manage','assets','static','style','style.css','health','robots.txt','posts','new','edit','delete','preview','_addons','blog.php','manage.php','favicon.ico','index.php','www','root','support','security','abuse','postmaster','webmaster','bin','src','config','storage','database','vendor','tests','.well-known'];
}

function section_reserved_names(): array { return array_merge(reserved_names(), ['inicio','blogs']); }

function t(string $key): string {
    if ($key==='brand') return config()['site_name'];
    if ($key==='intro') return config()['site_description'];
    static $strings = [
        'username'=>'nombre de usuario', 'create_blog'=>'crear blog',
        'have_account'=>'¿ya tienes cuenta?', 'login'=>'entrar', 'logout'=>'salir', 'register'=>'crear tu blog', 'password'=>'contraseña', 'confirm_password'=>'repetir contraseña',
        'password_help'=>'min 12 caracteres', 'username_help'=>'min 3 letras',
        'home'=>'inicio', 'manage'=>'gestionar', 'sections'=>'secciones', 'appearance'=>'apariencia', 'settings'=>'ajustes', 'addons'=>'addons', 'addons_empty'=>'no hay addons instalados', 'blog'=>'ver blog', 'new_post'=>'nueva entrada',
        'edit'=>'editar', 'delete'=>'eliminar', 'title'=>'título', 'description'=>'descripción breve', 'section'=>'sección', 'unsectioned'=>'sin sección', 'content'=>'contenido Markdown',
        'save'=>'guardar', 'draft'=>'borrador', 'save_draft'=>'guardar borrador', 'publish'=>'publicar', 'empty'=>'todavía no hay entradas', 'blogs'=>'blogs', 'edit_home'=>'editar inicio',
        'previous'=>'anterior', 'next'=>'siguiente', 'cancel'=>'cancelar', 'confirm'=>'confirmar', 'new_section'=>'nueva sección', 'intro_content'=>'introducción Markdown (opcional)',
        'up'=>'subir', 'down'=>'bajar', 'delete_post'=>'¿eliminar esta entrada?', 'delete_post_help'=>'la entrada dejará de estar disponible; se conserva en la base de datos hasta eliminar la cuenta',
        'delete_section'=>'¿eliminar esta sección?', 'delete_section_help'=>'sus entradas se conservarán y pasarán a «sin sección»; los enlaces anteriores redirigirán a su nueva dirección',
        'css'=>'style.css', 'apply'=>'aplicar', 'restore'=>'restaurar CSS predeterminado', 'restore_help'=>'se reemplazará tu CSS; tus entradas y secciones se conservarán',
        'preview'=>'vista previa', 'css_help'=>'hasta 64 KiB; no se permiten recursos externos; la política del navegador bloquea imágenes, fuentes e importaciones externas',
        'public'=>'público', 'private'=>'privado', 'visibility'=>'visibilidad', 'delete_blog'=>'eliminar blog y cuenta', 'delete_blog_help'=>'se eliminarán permanentemente tu cuenta, blog, entradas, secciones y estilos; las copias de seguridad se conservan según la política del servidor',
        'type_username'=>'escribe tu nombre de usuario', 'delete_permanently'=>'eliminar permanentemente', 'saved'=>'guardado', 'published'=>'publicado', 'section_created'=>'sección creada',
        'deleted'=>'eliminado', 'css_restored'=>'css restaurado', 'not_found'=>'no se encuentra esta página', 'unavailable'=>'este blog no está disponible', 'invalid'=>'solicitud no válida',
        'username_error'=>'nombre no válido o reservado', 'username_taken'=>'este nombre ya está ocupado', 'text_error'=>'revisa los campos y su longitud',
        'password_min'=>'minimo 12 caracteres', 'password_null'=>'sin caracteres nulos', 'password_max'=>'máximo {n} caracteres', 'confirmation_error'=>'las contraseñas no coinciden',
        'login_error'=>'nombre o contraseña incorrectos', 'login_required'=>'necesitas entrar para acceder', 'csrf_error'=>'el formulario ha caducado; vuelve a cargar la página',
        'forbidden'=>'no tienes permiso', 'rate_error'=>'demasiados intentos; inténtalo más tarde', 'too_large'=>'el contenido supera el tamaño permitido', 'section_limit'=>'puedes tener como máximo diez secciones',
        'post_limit'=>'has alcanzado el límite de entradas', 'delete_error'=>'la contraseña o el nombre de confirmación no son correctos', 'registration_closed'=>'el registro está cerrado',
        'method_error'=>'método no permitido', 'server_error'=>'no se pudo completar la solicitud', 'error'=>'error', 'skip'=>'ir al contenido', 'edit_post'=>'editar entrada',
        'edit_section'=>'editar sección', 'section_count'=>'de 10', 'table'=>'tabla', 'diagram'=>'diagrama', 'formula'=>'fórmula matemática', 'flowchart'=>'diagrama de flujo', 'diagram_unsupported'=>'no se pudo dibujar este diagrama; se muestra su código', 'alert_note'=>'nota', 'alert_tip'=>'consejo', 'alert_warning'=>'aviso', 'date'=>'fecha', 'status'=>'estado', 'privacy_help'=>'un blog privado solo puede leerse desde tu cuenta',
    ];
    return $strings[$key] ?? throw new LogicException('Missing translation: '.$key);
}
