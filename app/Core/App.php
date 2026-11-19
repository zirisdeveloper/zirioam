<?php
namespace App\Core;

/**
 * Application Bootstrap et Conteneur d'instances ZIRIOAM
 */
class App
{
    private static ?App $instance = null;
    private array $config;
    private Session $session;
    private View $view;
    private Router $router;

    private function __construct(array $config)
    {
        $this->config = $config;
        $this->session = new Session($config['session'] ?? []);
        $this->view = new View($config['paths']['views'] ?? dirname(__DIR__, 2) . '/views');
        $this->router = new Router();
    }

    public static function initialize(array $config): self
    {
        if (self::$instance === null) {
            self::$instance = new self($config);
        }
        return self::$instance;
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            throw new \RuntimeException("L'application ZIRIOAM n'a pas été initialisée.");
        }
        return self::$instance;
    }

    public function getConfig(string $key = '', $default = null)
    {
        if ($key === '') {
            return $this->config;
        }

        $parts = explode('.', $key);
        $current = $this->config;

        foreach ($parts as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) {
                return $default;
            }
            $current = $current[$part];
        }

        return $current;
    }

    public function getSession(): Session
    {
        return $this->session;
    }

    public function getView(): View
    {
        return $this->view;
    }

    public function getRouter(): Router
    {
        return $this->router;
    }

    public function run(): void
    {
        $depPath = $this->getConfig('dep_path', '');
        $request = new Request($depPath);
        $response = $this->router->dispatch($request);
        $response->send();
    }
}

