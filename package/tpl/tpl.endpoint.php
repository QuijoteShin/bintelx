<?php
# package/tpl/tpl.endpoint.php
namespace tpl;

use bX\Router;
use bX\Response;
use bX\Config;

/**
 * @endpoint   /api/tpl/{path}.tpls
 * @method     GET
 * @scope      ROUTER_SCOPE_READ
 * @purpose    Serve template files for CSR (Client-Side Rendering)
 * @param      path (string) - Path to template relative to TPL_PATH
 * @note       Security: validates path traversal, allowed characters, and base directory
 * @header     Cache-Control: public, max-age=3600, must-revalidate
 * @header     ETag: md5 of path+mtime for conditional requests
 */
Router::register(['GET'], '(?P<path>.+)\.tpls', function($path) {
    # Validate allowed characters (alphanumeric, -, _, /)
    if (preg_match('/[^a-zA-Z0-9\/_\-]/', $path)) {
        return Response::error('Invalid characters in path', 400);
    }

    $basePath = Config::get('TPL_PATH', '/var/www/erp_labtronic/bintelx_front/src/apps/');
    $file = $basePath . $path . '.tpls';

    # Security: Path Traversal validation
    $realBase = realpath($basePath);
    if (!$realBase) {
        return Response::error('Template system error', 500);
    }
    $realBase .= DIRECTORY_SEPARATOR; # Prevent partial path match

    $realDir = realpath(dirname($file));
    if (!$realDir || strpos($realDir . DIRECTORY_SEPARATOR, $realBase) !== 0) {
        return Response::error('Access denied', 403);
    }

    if (!file_exists($file)) {
        return Response::error('Template not found', 404);
    }

    # ETag based on mtime for conditional requests
    $mtime = filemtime($file);
    $etag = md5($path . $mtime);

    if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH'], '"') === $etag) {
        header("HTTP/1.1 304 Not Modified");
        exit;
    }

    return Response::raw(file_get_contents($file), 'text/plain; charset=utf-8')
        ->withHeader('Cache-Control', 'public, max-age=3600, must-revalidate')
        ->withHeader('ETag', '"' . $etag . '"');

}, ROUTER_SCOPE_READ);
