<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Log;

class MySQLCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:mysql {--command= : <create|restore> command to execute} {--snapshot= : provide of name snapshot}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'MySQL Weekly backup';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        switch ($this->option('command')) 
        {
            case 'create':
                $this->takeSnapshot();
                break;
            case 'restore':
                $this->restoreSnapshot();
                break;
            default:
                break;
        }
    }

    public function takeSnapshot() 
    {
        set_time_limit(0);

        // Define location storage
        $temp_file_location = '/tmp/' . env('DB_DATABASE') . '_' . date('Y-m-d_Hi') . time() . '.sql';

        $target_file_path   = '/mysql/' . env('DB_DATABASE') . '_' . date('Y-m-d_Hi')  . time() . '.sql';

        // Run CLI Job
        $process = new Process(env('MY_SQL_PATH') . 'mysqldump -u' . env('DB_USERNAME') . ' ' .env('DB_DATABASE'). ' > ' .$temp_file_location);

        $process->run();

        $current_timestamp = time() - (72 * 3600);

        try 
        {
            if ($process->isSuccessful()) 
            {
                // Store file
                Storage::put($target_file_path, file_get_contents($temp_file_location));
                
                $files = Storage::files(public_path('storage/mysql'));
                // info(public_path('storage/mysql'));
                // Log::debug(storage_path('app/public/mysql'));
                // Log::debug($files);
                foreach ($files as $file) 
                {
                    if (Storage::lastModified($file) < $current_timestamp)
                    {
                        Storage::delete($file);

                         $this->info('File: {$file} deleted.');
                    }
                }
            }
            else 
            {
                throw new ProcessFailedException($process);
            }

            unlink($temp_file_location);
        } 
        catch (\Exception $e) 
        {
            $this->info($e->getMessage());
        }
    }

    public function restoreSnapshot() 
    {
        $snapshot = $this->option('snapshot');

        if (! $snapshot) 
        {
             $this->error("snapshot option is required.");
        }

        try
        {
            // Get file content
            $sql_content = Storage::get('/mysql/' . $snapshot . '.sql');

            $temp_file_location = '/tmp/' .env('DB_DATABASE') . '_' . date('Y-m-d_Hi') . '.sql';

            // Create temp file
            $file = Storage::put($sql_content, file_get_contents($temp_file_location));

             if (!$file) {
                $this->info('Error writing to file: ' . $temp_file_location);
            }

            $process = new Process(env('MY_SQL_PATH') . 'mysql -h ' . env('DB_HOST') . ' -u' . env('DB_USERNAME') . ' -p' . env('DB_PASSWORD') . ' ' . env('DB_DATABASE') . ' < ' . $temp_file_location);
            $process->run();

            if ($process->isSuccessful())
            {
                $this->info('Restored snapshot: ' . $snapshot);
            }
            else
            {
                throw new ProcessFailedException($process);
            }
        }
        catch (\Exception $e)
        {
            $this->info('File Not Found: '. $e->getMessage());
        }
    }
}
