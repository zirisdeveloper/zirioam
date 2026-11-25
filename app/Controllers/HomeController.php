<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;

/**
 * Contrôleur Principal
 */
class HomeController extends Controller
{
    public function index(Request $request): Response
    {
        if (!$this->session->has('admin_user')) {
            return $this->redirect('/login');
        }

        if ($this->session->has('oracle_session')) {
            return $this->redirect('/databases');
        }

        return $this->redirect('/servers');
    }
}

