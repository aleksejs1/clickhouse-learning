<?php

namespace App\Command;

use App\Ai\PromptBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Печатает собранный system prompt (docs/AI_ASSISTANT.md §6) — отладка и
 * проверка воспроизводимости (для промпт-кэша текст должен быть стабильным).
 */
#[AsCommand('app:ai-prompt', 'Print the assembled AI system prompt')]
class AiPromptCommand extends Command
{
    public function __construct(private PromptBuilder $prompts)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('kind', InputArgument::REQUIRED, 'report | alert');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $kind = $input->getArgument('kind');
        $text = match ($kind) {
            'report' => $this->prompts->report(),
            'alert' => $this->prompts->alert(),
            default => null,
        };
        if (null === $text) {
            $output->writeln('<error>kind must be report or alert</error>');

            return Command::INVALID;
        }
        $output->write($text);

        return Command::SUCCESS;
    }
}
