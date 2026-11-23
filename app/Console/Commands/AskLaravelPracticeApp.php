<?php

namespace App\Console\Commands;

use App\Ai\Agents\LarvelPracticeAgent;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ai:ask {question}')]
#[Description('Ask the Laravel Ai Agent a question')]
class AskLaravelPracticeApp extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $question = $this->argument('question');
        $response = (new LarvelPracticeAgent)->prompt($question);
        $this->line((string) $response);

        return self::SUCCESS;
    }
}
