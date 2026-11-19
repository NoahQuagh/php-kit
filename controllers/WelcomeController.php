<?php

namespace controllers;

use core\Controller;
use models\Welcome;
use resources\WelcomeResource;

class WelcomeController extends Controller
{
    function show()
    {
        new WelcomeResource(new Welcome()->getInfo())->send();
    }
}