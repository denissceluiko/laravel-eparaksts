<?php

namespace Dencel\LaravelEparaksts\Console\Commands;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'eparaksts:install';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Installs the package.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->call('vendor:publish', [
            '--tag' => 'eparaksts-config'
        ]);
                $this->call('vendor:publish', [
            '--tag' => 'eparaksts-migrations'
        ]);

        $this->info('Review eparaksts.php config file and run migrations after that.');
    }
}
