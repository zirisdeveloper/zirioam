<?php
namespace App\Core;

/**
 * Encapsulation de la Requête HTTP
 */
class Request
{
    private string $method;
    private string $uri;
    private string $path;
    private array $query;
    private array $post;
    private array $files;
    private array $server;
    private array $headers;

    public function __construct(string $depPath = '')
    {
        $this->server = $_SERVER;
        $this->method = strtoupper($this->server['REQUEST_METHOD'] ?? 'GET');
        $this->query = $_GET;
        $this->post = $_POST;
        $this->files = $_FILES;

        // Headers
        $this->headers = [];
        foreach ($this->server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headerName = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $this->headers[$headerName] = $value;
            }
        }

        // URI et Path sans dep_path
        $requestUri = $this->server['REQUEST_URI'] ?? '/';
        $this->uri = $requestUri;
        
        $parsedPath = parse_url($requestUri, PHP_URL_PATH) ?: '/';
        
        // Retirer depPath du début du chemin pour le routeur
        $depPath = rtrim($depPath, '/');
        if ($depPath !== '' && str_starts_with($parsedPath, $depPath)) {
            $parsedPath = substr($parsedPath, strlen($depPath));
        }

        $this->path = '/' . trim($parsedPath, '/');
        if ($this->path === '//') {
            $this->path = '/';
        }
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function isGet(): bool
    {
        return $this->method === 'GET';
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getUri(): string
    {
        return $this->uri;
    }

    public function input(string $key, $default = null)
    {
        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    public function post(string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->post;
        }
        return $this->post[$key] ?? $default;
    }

    public function query(string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->query;
        }
        return $this->query[$key] ?? $default;
    }

    public function file(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    public function header(string $key, $default = null)
    {
        return $this->headers[$key] ?? $default;
    }

    public function getJson(): ?array
    {
        $raw = file_get_contents('php://input');
        if (empty($raw)) {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }
}

