<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class DownloadBooster extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'carnegie:multiGet
                            {url : The Url of the file you want to download}
                            {--serial}
                            {--chunks=4 : The number of chunks you want to divide your download into}
                            {--chunkSize=4 : The size (in MiB) you want each chunk to be ie 4 = 4 MiB}
                            {--fileName=output.txt : What do you want to name the file}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This is a command will download a file using Multi-Get';

    const MiB = (1024 * 1024);

    protected $url;
    protected $chunks;
    protected $chunkSize;
    protected $fileName;
    protected $result;
    protected $curlHandles = [];
    protected $multiHandle;

    /**
     * DownloadBooster constructor.
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
        $this->url = $this->argument('url');
        $this->chunks = $this->option('chunks');
        $this->chunkSize = $this->option('chunkSize') * self::MiB ;
        $this->fileName = $this->option('fileName');

        // Its a coding test so lets clear the file.
        // File names would be unique hashes in real world scenario
        Storage::delete($this->fileName);

        // Allows you to run parallel by default and as serial if --serial flag is set on command
        if ($this->option('serial')){
            $this->downloadSerial();
        } else {
            $this->downloadParallel();
        }

        // Will signal SUCCESS! if the saved file is the same size as the requested file size
        if(($this->chunkSize*$this->chunks) == Storage::size($this->fileName)){
            echo "\nSUCCESS!\n";
        }

    }

    private function downloadSerial()
    {
        for($i=0; $i<$this->chunks; $i++){
            $offset = $i + 1;

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $this->url);
            curl_setopt($ch, CURLOPT_RANGE, ($i * $this->chunkSize) . '-' . (($offset * $this->chunkSize) - 1));
            curl_setopt($ch, CURLOPT_BINARYTRANSFER, 1);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_NOPROGRESS, false);

            $this->result .= curl_exec($ch);

            curl_close($ch);

            $this->writeFile();
        }
    }

    private function downloadParallel()
    {
        $this->createMultiHandle();

        $this->downloadFile();

        $this->prepareResponse();

        $this->writeFile();
    }

    private function createMultiHandle()
    {
        for($i=0; $i<$this->chunks; $i++){
            $this->curlHandles[$i] = curl_init();
        }

        $this->multiHandle = curl_multi_init();

        foreach ($this->curlHandles as $index => $ch){
            $offset = $index + 1;

            curl_setopt($ch, CURLOPT_URL, $this->url);
            curl_setopt($ch, CURLOPT_RANGE, ($index * $this->chunkSize) . '-' . (($offset * $this->chunkSize) - 1));
            curl_setopt($ch, CURLOPT_BINARYTRANSFER, 1);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_NOPROGRESS, false);

            curl_multi_add_handle($this->multiHandle, $ch);
        }
    }

    private function downloadFile()
    {
        $running = null;

        do {
            curl_multi_exec($this->multiHandle, $running);
        } while ($running > 0);
    }

    private function closeConnections()
    {
        foreach ($this->curlHandles as $index => $handle) {
            curl_multi_remove_handle($this->multiHandle, $handle);
        }
        curl_multi_close($this->multiHandle);

        $this->closeConnections();
    }

    private function prepareResponse()
    {
            $responses = '';

            foreach ($this->curlHandles as $index => $handle) {
                $responses .= curl_multi_getcontent($handle);
            }
            $this->result = $responses;
    }

    private function writeFile()
    {
        Storage::disk('local')->put($this->fileName, $this->result);
    }
}
