<?php

namespace controllers;

use core\Controller;
use resources\WelcomeResource;

class WelcomeController extends Controller
{
    function show()
    {
        $fakeDbRow = [
            'pk_id'        => 1,
            'pk_msg'       => 'Bienvenue sur API PHP Kit',
            'pk_app_ver'       => '1.0.2',
        ];

        (new WelcomeResource($fakeDbRow))->send();
    }
}