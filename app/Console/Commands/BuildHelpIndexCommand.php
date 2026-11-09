<?php

namespace App\Console\Commands;

use App\Services\Ai\Assistant\HelpIndex;
use App\Services\Ai\Assistant\HelpIndexBuilder;
use Illuminate\Console\Command;

class BuildHelpIndexCommand extends Command
{
    protected $signature = 'ai:build-help-index';

    protected $description = "Construit l'index documentaire consulté par l'assistant (aide contextuelle, docs/, outils MCP)";

    public function handle(HelpIndexBuilder $builder): int
    {
        $count = $builder->write();

        $this->info("Index d'aide construit : {$count} sections → ".HelpIndex::path());

        return self::SUCCESS;
    }
}
