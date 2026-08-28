<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class AppSetupCommand extends Command
{
    protected $signature = 'app:setup {--mode= : local|remote} {--auth-mode= : bearer|cookie|mock} {--non-interactive}';

    protected $description = 'Run the monorepo setup console (forwards to scripts/setup.mjs).';

    public function handle(): int
    {
        $monorepoRoot = dirname(base_path(), 2);
        $script = $monorepoRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'setup.mjs';

        if (! is_file($script)) {
            $this->error("Setup script not found: {$script}");
            $this->line('Run this command from inside apps/api/ of a monorepo clone.');

            return self::FAILURE;
        }

        $args = ['node', $script];
        foreach (['mode', 'auth-mode'] as $opt) {
            $val = $this->option($opt);
            if ($val !== null && $val !== '') {
                $args[] = "--{$opt}={$val}";
            }
        }
        if ($this->option('non-interactive')) {
            $args[] = '--non-interactive';
        }

        $process = new Process($args, $monorepoRoot, null, null, null);
        $process->setTty(Process::isTtySupported());
        $process->run(function ($type, $buffer): void {
            $this->output->write($buffer);
        });

        return $process->getExitCode() ?? self::FAILURE;
    }
}
