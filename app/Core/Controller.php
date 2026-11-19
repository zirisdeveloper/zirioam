<?php
namespace App\Core;

/**
 * Contrôleur de Base MVC pour ZIRIOAM
 */
abstract class Controller
{
    protected App $app;
    protected Session $session;
    protected View $view;

    public function __construct()
    {
        $this->app = App::getInstance();
        $this->session = $this->app->getSession();
        $this->view = $this->app->getView();
    }

    protected function render(string $view, array $data = [], string $layout = 'main'): Response
    {
        // Données globales injectées automatiquement
        $data['currentSession'] = $this->session;
        $data['appConfig'] = $this->app->getConfig();
        $data['currentServer'] = $this->session->get('current_server');
        $data['currentCdb'] = $this->session->get('current_cdb');
        $data['currentPdb'] = $this->session->get('current_pdb');

        $html = $this->view->render($view, $data, $layout);

        $response = new Response();
        $response->setHeader('Content-Type', 'text/html; charset=UTF-8');
        $response->setContent($html);
        return $response;
    }

    protected function json(array $data, int $statusCode = 200): Response
    {
        $response = new Response();
        return $response->json($data, $statusCode);
    }

    protected function redirect(string $path, int $statusCode = 302): Response
    {
        $url = zirioam_url($path);
        $response = new Response();
        return $response->redirect($url, $statusCode);
    }

    protected function flash(string $type, string $message): void
    {
        $this->session->setFlash($type, $message);
    }
}

