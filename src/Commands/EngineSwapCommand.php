<?php

namespace nexxai\EngineSwap\Commands;

use Illuminate\Console\Command;

class EngineSwapCommand extends Command
{
    public $signature = 'engine-swap';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
