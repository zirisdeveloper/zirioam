<?php
namespace App\Core;

/**
 * Routeur HTTP pour ZIRIOAM
 */
class Router
{
    private array $routes = [];

    public function get(string $path, $handler, array $middlewares = []): void
    {
        $this->addRoute('GET', $path, $handler, $middlewares);
    }

    public function post(string $path, $handler, array $middlewares = []): void
    {
        $this->addRoute('POST', $path, $handler, $middlewares);
    }

    private function addRoute(string $method, string $path, $handler, array $middlewares): void
    {
        $pattern = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '(?P<$1>[^/]+)', $path);
        $pattern = '#^' . $pattern . '$#';

        $this->routes[] = [
            'method' => $method,
            'path' => $path,
            'pattern' => $pattern,
            'handler' => $handler,
            'middlewares' => $middlewares,
        ];
    }

    public function dispatch(Request $request): Response
    {
        $method = $request->getMethod();
        $path = $request->getPath();

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            if (preg_match($route['pattern'], $path, $matches)) {
                $params = [];
                foreach ($matches as $key => $value) {
                    if (is_string($key)) {
                        $params[$key] = $value;
                    }
                }

                // Exécution des middlewares
                foreach ($route['middlewares'] as $middlewareClass) {
                    $middleware = new $middlewareClass();
                    $middlewareResult = $middleware->handle($request);
                    if ($middlewareResult instanceof Response) {
                        return $middlewareResult;
                    }
                }

                // Exécution du handler
                $handler = $route['handler'];

                if (is_array($handler)) {
                    [$controllerClass, $actionMethod] = $handler;
                    $controller = new $controllerClass();
                    return $controller->$actionMethod($request, $params);
                }

                if (is_callable($handler)) {
                    return $handler($request, $params);
                }
            }
        }

        // 404 Not Found
        $response = new Response();
        $response->setStatusCode(404);
        $response->setContent("<!DOCTYPE html><html><head><meta charset='utf-8'><title>404 - Page non trouvée</title><link rel='stylesheet' href='" . zirioam_asset('css/bootstrap.min.css') . "'></head><body class='bg-light d-flex align-items-center min-vh-100'><div class='container text-center'><h1 class='display-4 text-danger fw-bold'>404</h1><p class='lead'>La page demandée est introuvable.</p><a href='" . zirioam_url('/') . "' class='btn btn-primary'>Retour à l'accueil</a></div></body></html>");
        return $response;
    }
}

