<?php

namespace TomJamon\TailwindLaravel;

use Illuminate\Support\Facades\Facade;

/**
 * Class FormFacade
 * @package TomJamon\TailwindLaravel
 */
class FormFacade extends Facade
{

    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'form';
    }
}
