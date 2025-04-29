<?php

namespace nexxai\EngineSwap\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \nexxai\EngineSwap\EngineSwap
 */
class EngineSwap extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \nexxai\EngineSwap\EngineSwap::class;
    }
}
