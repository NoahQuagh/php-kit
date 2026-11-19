<?php

namespace core;
use Database;

abstract class Controller
{
    protected SessionManager $session;
    protected CookieManager $cookies;

    public function __construct()
    {
        $this->session = SessionManager::getInstance();
        $this->cookies = CookieManager::getInstance();
    }

    /**
     * Récupère les données envoyées par le client (JSON brut via fetch ou $_POST)
     */
    protected function getInput(): array
    {
        $raw = file_get_contents('php://input');
        $json = json_decode($raw, true);

        return is_array($json) ? $json : $_POST;
    }
}