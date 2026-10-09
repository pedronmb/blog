# Solidchain

Blog personal con PHP 8.2+, HTML autónomos y metadatos dentro de cada artículo. El sitio público no utiliza base de datos, sesiones, login ni escrituras en disco.

## Ejecutar y verificar

En XAMPP, iniciar Apache y abrir **http://localhost/blog/**. Apache/LiteSpeed debe permitir `.htaccess`, `mod_rewrite`, `Require` y las directivas `Options`/`RemoveHandler`. PHP requiere las extensiones DOM, mbstring y libxml; el importador también requiere curl y SimpleXML. No se necesita Composer ni Node.js.

Desde esta carpeta:

```powershell
C:\xampp\php\php.exe tools/validate.php
C:\xampp\php\php.exe tests/run.php
C:\xampp\php\php.exe tests/http.php http://localhost/blog
```

Las pruebas HTTP necesitan Apache y crean archivos de prueba temporales que retiran al terminar. Ejecutarlas en el entorno local, no en producción.

Mientras el WordPress original siga disponible, `php tests/migration.php` compara el texto, la estructura y los bloques de código de los 19 artículos y About contra la API pública. Esta prueba necesita acceso a Internet y deja de aplicar después del reemplazo o de hacer cambios editoriales locales.

## Publicar un artículo

El botón de sol/luna de la cabecera alterna los modos claro y oscuro. La elección se guarda en el navegador y se comparte entre la portada, Sobre mí y los artículos. En la primera visita se utiliza la preferencia del sistema. Funciona sin cookies ni almacenamiento en el servidor. La plantilla y el importador incluyen `assets/theme.js` antes de las hojas de estilo, y `assets/theme.css` después de `assets/site.css`, para aplicar el tema antes de mostrar la página. Los HTML con un diseño propio pueden incorporar estos recursos y una cabecera con `.main-nav` para obtener el mismo selector.

1. Copiar `templates/articulo.html` a un nombre como `posts/mi-articulo.html`. El nombre admite letras minúsculas ASCII, números y guiones.
2. Cambiar título, resumen, fecha, categorías y contenido. El archivo es un documento HTML completo; puede contener sus propios estilos y scripts. La plantilla muestra una interacción JavaScript de ejemplo.
3. Subir imágenes y otros recursos primero, por ejemplo a `media/mi-articulo/`. Desde el HTML dentro de `posts/`, referenciarlos con `../media/mi-articulo/imagen.webp`.
4. Subir el HTML como `mi-articulo.html.part` y renombrarlo a `mi-articulo.html` al terminar la transferencia. Los temporales no se listan ni se sirven.
5. Verificar con `tools/validate.php`. La portada, categorías, buscador, RSS y sitemap se actualizan con la siguiente solicitud.

Para modificar o retirar un artículo, reemplazar o eliminar su HTML. No hace falta regenerar un índice. La publicación es inmediata; una fecha futura no convierte el archivo en un borrador privado. Los HTML son contenido de confianza producido por el propietario y sus scripts se ejecutan en el navegador.

### Metadatos de `<head>`

```html
<title>Mi artículo</title>
<meta name="description" content="Un resumen breve para la portada.">
<meta property="article:published_time" content="2026-10-07T12:00:00-03:00">
<meta property="article:modified_time" content="2026-10-07T13:00:00-03:00">
<meta property="og:image" content="../media/mi-articulo/portada.webp">
<meta property="article:section" content="Web">
<meta property="article:section" content="Arquitectura">
<link rel="canonical" href="https://solidchain.ar/posts/mi-articulo.html">
<meta property="og:url" content="https://solidchain.ar/posts/mi-articulo.html">
```

Título, description y published_time son obligatorios. La fecha usa ISO 8601, segundos y zona horaria (`Z` o `±HH:MM`). modified_time es opcional, pero debe tener el mismo formato. Las categorías son opcionales y repetibles. Un artículo sin categorías aparece en «Todos». Una imagen local ausente o una URL inválida utiliza la miniatura predeterminada. También se admiten imágenes externas HTTP/HTTPS; el contenido migrado utiliza recursos locales.

Los HTML con metadatos obligatorios inválidos se omiten del catálogo; siguen siendo archivos públicos si ya fueron subidos. La herramienta CLI muestra el diagnóstico. Los nombres y resúmenes se escapan al renderizar. Los artículos se leen con DOMDocument, nunca con `include` o `eval`.

El título de `<title>` es la fuente del título de las tarjetas; los metadatos Open Graph complementarios deben actualizarse junto con él. No agregar el sufijo «Solidchain» al `<title>` salvo que se quiera mostrar también en las tarjetas.

### Rutas públicas

| Ruta | Función |
| --- | --- |
| `/` | Catálogo, 12 artículos por página |
| `/?categoria=criptografia` | Filtro de categoría |
| `/?q=ethereum&categoria=blockchain&pagina=1` | Búsqueda combinable, por título, resumen y categorías |
| `/posts/mi-articulo.html` | HTML estático |
| `/sobre-mi.html` | Presentación personal |
| `/feed.xml` | RSS de todos los artículos |
| `/sitemap.xml` | Sitemap actualizado desde los HTML |

Las rutas funcionan también bajo `/blog/` en XAMPP. La búsqueda ignora mayúsculas y acentos; todos los términos escritos deben encontrarse en los metadatos. El orden es por fecha de publicación descendente y por nombre de archivo en caso de empate. Las categorías se normalizan a slugs sin acentos, por ejemplo «Criptografía» → `criptografia`.

## Migrar WordPress

```powershell
C:\xampp\php\php.exe tools/import-wordpress.php --dry-run
C:\xampp\php\php.exe tools/import-wordpress.php
```

El importador recorre toda la API pública, contrasta los artículos con `post-sitemap.xml`, descarga contenido, portadas, imágenes, variantes srcset y fórmulas QuickLaTeX, y migra la página About. Los artículos conservan el contenido original. Los recursos se guardan bajo `media/uploads/` y `media/ql-cache/`, manteniendo los subdirectorios originales.

El estado está en `tools/import-state.json`, con hashes SHA-256. El informe detallado está en `tools/migration-report.json`. Ambos están bloqueados por las reglas web. El importador solo corre en CLI.

Una repetición puede actualizar archivos importados cuyo hash coincide con el estado anterior. Omite archivos HTML modificados manualmente y avisa. Tampoco reemplaza recursos locales modificados. `--force` habilita el reemplazo explícito de archivos existentes; hacer respaldo antes de usarlo. No elimina artículos locales ni recursos que ya no figuren en el origen. `--dry-run` consulta el inventario sin escribir archivos.

Los metadatos `blog:legacy-url` y `blog:wordpress-id` de cada HTML permiten las redirecciones 301 de las rutas originales y `?p=ID`. Las categorías antiguas se redirigen a sus filtros y About a Sobre mí. Las URLs de imágenes bajo `/wp-content/uploads/` y `/wp-content/ql-cache/` se redirigen a sus copias locales.

## Reemplazar WordPress y volver atrás

1. Respaldar fuera del directorio público todos los archivos de WordPress, la base de datos y la configuración del servidor, incluida su `.htaccess`. Verificar que el respaldo sea restaurable.
2. Preparar este sitio en un directorio de prueba separado con PHP 8.2+ y las reglas de Apache/LiteSpeed activas. Ejecutar las validaciones y revisar las páginas en móvil y escritorio.
3. Antes del cambio, volver a consultar el inventario del origen y revisar el informe de migración. No apuntar el importador al nuevo sitio una vez retirado WordPress.
4. Subir el sitio completo a un directorio de lanzamiento nuevo, incluidas las `.htaccess` de raíz, posts y media. Conceder lectura al servidor y escritura solo a la cuenta de despliegue. No conceder al proceso PHP permisos de publicación.
5. Cambiar el directorio raíz del dominio al nuevo lanzamiento mediante la configuración del hosting, o un reemplazo atómico del directorio público. No superponer la aplicación nueva con los archivos ejecutables de WordPress.
6. Comprobar portada, artículo, categoría, Sobre mí, imágenes/fórmulas, una URL antigua, RSS y sitemap. Revisar los registros de acceso/error después del cambio.
7. Para revertir, volver a apuntar el dominio al respaldo del lanzamiento anterior y restaurar su configuración. Si se había retirado la base de datos, restaurarla desde el respaldo.

La URL canónica predeterminada es `https://solidchain.ar`. Para otro dominio, configurar `SOLIDCHAIN_BASE_URL` para los endpoints PHP y actualizar los canonical/og:url de los HTML y robots.txt. En producción deben seguir activos los bloqueos de `app/`, `tools/`, `tests/` y `templates/`, los archivos privados y la ejecución de scripts dentro de posts/media/assets. No hay una política CSP global restrictiva porque los HTML pueden aportar JavaScript y CSS propios.

El cambio de hosting se realiza por separado; la implementación y migración de este proyecto no modifican el servidor de solidchain.ar.
