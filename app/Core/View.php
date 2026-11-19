<?php
namespace App\Core;

/**
 * Moteur de Rendu de Vues PHP
 */
class View
{
    private string $viewsPath;

    public function __construct(string $viewsPath)
    {
        $this->viewsPath = rtrim($viewsPath, '/\\');
    }

    /**
     * Rend une vue avec son layout
     */
    public function render(string $view, array $data = [], string $layout = 'main'): string
    {
        $viewFile = $this->viewsPath . DIRECTORY_SEPARATOR . str_replace('.', DIRECTORY_SEPARATOR, $view) . '.php';

        if (!file_exists($viewFile)) {
            throw new \RuntimeException("La vue spécifiée est introuvable : {$viewFile}");
        }

        // Rendre le contenu interne de la vue
        extract($data, EXTR_SKIP);
        ob_start();
        include $viewFile;
        $content = ob_get_clean();

        // Si aucun layout demandé
        if (!$layout) {
            return $content;
        }

        $layoutFile = $this->viewsPath . DIRECTORY_SEPARATOR . 'layouts' . DIRECTORY_SEPARATOR . $layout . '.php';
        if (!file_exists($layoutFile)) {
            throw new \RuntimeException("Le layout spécifié est introuvable : {$layoutFile}");
        }

        // Rendre dans le layout
        ob_start();
        include $layoutFile;
        return ob_get_clean();
    }
}

