<?php

namespace core;

abstract class Resource
{
    protected array $resource;

    public function __construct(array $resource)
    {
        $this->resource = $resource;
    }

    /**
     * Chaque ressource enfant définit ici le renommage des clés
     */
    abstract public function toArray(): array;

    /**
     * Transforme et envoie un seul élément formaté en JSON
     *
     * @param int $status statut du json, par defaut 200
     * @return void
     */
    public function send(int $status = 200): void
    {
        self::respond($this->toArray(), $status);
    }

    /**
     * Transforme et envoie une liste d'éléments formatée en JSON
     *
     * @param array $items tableau associatif PHP
     * @param int $status statut de la réponse; defaut : 200
     * @return void
     */
    public static function collection(array $items, int $status = 200): void
    {
        $formatted = array_map(function ($item) {
            return (new static($item))->toArray();
        }, $items);

        self::respond($formatted, $status);
    }

    /**
     * Méthode interne unique qui gère l'envoi HTTP + JSON
     *
     * @param array $data données à envoyer
     * @param int $status statut de la réponse JSON
     * @return void
     */
    private static function respond(array $data, int $status): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}