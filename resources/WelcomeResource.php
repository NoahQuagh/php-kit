<?php

namespace resources;

use core\Resource;

class WelcomeResource extends Resource
{
    public function toArray(): array
    {
        return [
            'message' => $this->resource['pk_msg'],
            'version' => $this->resource['pk_app_ver'],
        ];
    }
}