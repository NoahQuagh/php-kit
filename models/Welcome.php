<?php

namespace models;

use core\Model;

class Welcome extends Model
{
    public function getInfo(): array
    {
        return [
            'pk_id'        => 1,
            'pk_msg'       => 'Bienvenue sur API PHP Kit',
            'pk_app_ver'       => '1.0.2',
        ];
    }
}