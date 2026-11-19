<?php
namespace App\Core;

/**
 * Encapsulation de la Réponse HTTP
 */
class Response
{
    private int $statusCode = 200;
    private array $headers = [];
    private string $content = '';

    public function setStatusCode(int $code): self
    {
        $this->statusCode = $code;
        return $this;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function setHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function setContent(string $content): self
    {
        $this->content = $content;
        return $this;
    }

    public function json(array $data, int $statusCode = 200): self
    {
        $this->setStatusCode($statusCode);
        $this->setHeader('Content-Type', 'application/json; charset=UTF-8');
        $this->setContent(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return $this;
    }

    public function redirect(string $url, int $statusCode = 302): self
    {
        $this->setStatusCode($statusCode);
        $this->setHeader('Location', $url);
        return $this;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->statusCode);

            // En-têtes de sécurité recommandés
            $this->setHeader('X-Frame-Options', 'DENY');
            $this->setHeader('X-Content-Type-Options', 'nosniff');
            $this->setHeader('X-XSS-Protection', '1; mode=block');
            $this->setHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

            foreach ($this->headers as $name => $value) {
                header("{$name}: {$value}");
            }
        }

        echo $this->content;
    }
}

