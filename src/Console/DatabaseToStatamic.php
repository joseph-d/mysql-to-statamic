<?php

namespace Josephd\MysqlToStatamic\Console;

use Illuminate\Console\Command;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Stache;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DatabaseToStatamic extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mysql-to-statamic:run
                            {--table= : MySQL table name}
                            {--field=* : Optional field names required}
                            {--collection= : Statamic collection handle}
                            {--imagePrefix= : Optional image URL prefix to be replaced with asset tag}
                            {--assetTag= : Optional asset tag to replace image URL prefix}
                            { --confirm= : Run without showing confirmation }';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Converts data from Laravel-style MySQL databases to Statamic YAML';

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
     * @return int
     */
    public function handle()
    {
        $this->newLine();

        if (!Collection::handles()->all()) {
            $this->error('😲 No Statamic collections found. Aborting.');
            $this->newLine();
            exit();
        } else {
            $this->warn('❗USE WITH CAUTION❗ Back up existing Statamic collections before proceeding!');
            $this->newLine();
        }

        if (!$tableName = $this->option('table')) {
            $tablePrefix = $this->ask('Please enter a table prefix (or leave blank for none)');

            $tableList = $this->getTableList($tablePrefix);

            $tableName = $this->choice('Which table do you want to convert? 🤔',$tableList);
        }

        if (!$fieldNames = $this->option('field')) {
            $fieldList = $this->getFieldList($tableName);
        
            $fieldNames = $this->choice(
                'Which optional field(s) do you want to convert? 🤔 (seperated with commas)',
                $fieldList,
                null,
                $maxAttempts = null,
                $allowMultipleSelections = true
            );
        }

        if (!$collectionName = $this->option('collection')) {
            $collectionName = $this->choice('Please choose a Statamic collection name', Collection::handles()->all());
        }

        if (!$imagePrefix = $this->option('imagePrefix')) {
            $imagePrefix = $this->ask('If you need to replace an image URL prefix with a Statamic asset tag, enter the URL prefix here (optional)');
        }

        if ($imagePrefix && !$assetTag = $this->option('assetTag')) {
            $assetTag = $this->ask('Please give the corresponding asset tag for ' . $imagePrefix, 'asset::assets::');
        } else {
            $assetTag = $this->option('assetTag');
        }

        $entries = DB::table($tableName)->get();

        $this->info(count($fieldNames) . ' fields across ' . count($entries) . ' records will be converted');

        $this->newLine();

        if ($imagePrefix && $assetTag) {
            $this->info('All occurances of \'' . $imagePrefix . '\' will be replaced with \'' . $assetTag . '\'');
        }


        if ($this->option('confirm') || $this->confirm('Are you ready? 🏁', false)) {
            
            $this->info('Converting ' . $tableName . " 🚧");
            
            $this->convertRecords($entries, $fieldNames, $collectionName, $imagePrefix, $assetTag);
        
            $this->info('The ' . $tableName . ' MySQL table has been converted into Statamic YAML collection \''. $collectionName . '\' ✨😀');
    
            $this->newLine();
            
            Stache::clear();

        } else {

            $this->info("Ok, bye then  😥");

            $this->newLine();
        }
    }


    private function getTableList($tablePrefix)
    {
        if (!$tablePrefix) {
            $tables = DB::select("SHOW TABLES;");
        } else {
            $tables = DB::select("SHOW TABLES LIKE '" . $tablePrefix . "\_%';");
        }

        $tableList = [];

        foreach ($tables as $table) {
            foreach ($table as $key => $value)
                $tableList[] = $value;
        }

        return $tableList;
    }

    private function getFieldList($tableName)
    {
        $fields = DB::select("DESC " . $tableName . ";");

        $optionalFieldList = [];

        $filterFields = ['id','slug','published','published_at','created_at','updated_at','title','name'];

        foreach ($fields as $field) {
            if (!in_array($field->Field, $filterFields)) {
                $optionalFieldList[] = $field->Field;
            }
        }

        return $optionalFieldList;
    }

    private function convertRecords($entries, $fieldNames, $collectionName, $imagePrefix, $assetTag)
    {
        $bar = $this->output->createProgressBar(count($entries));
    
        $this->newLine();

        $bar->start();

        foreach ($entries as $entry) {

            $data = new \stdClass();
            
            // Required fields
            $data->title = $entry->title ?? $entry->name;
            $data->updated_at = Carbon::parse($entry->updated_at)->timestamp;

            // Optional fields
            foreach ($fieldNames as $key => $value) {

                if ($imagePrefix && $assetTag) {
                    $entryValue = str_replace($imagePrefix, $assetTag, $entry->$value);
                } else {
                    $entryValue = $entry->$value;
                }

                $json = json_decode($entryValue,true);

                if (!is_array($json)) {
                    $data->$value = $entryValue;
                } else {
                    $data->$value = $this->jsonToArray($json, $imagePrefix, $assetTag);
                }
            }

            // Create entry
            $newEntry = Entry::make()
                ->slug($entry->slug)
                ->published((boolean) $entry->published)
                ->collection($collectionName)
                ->data($data);    
            $newEntry->date(Carbon::parse($entry->published_at ?? $entry->created_at)->format('Y-m-d'));
            $newEntry->save();

            $bar->advance();
        }

        $bar->finish();

        $this->newLine(2);
    }

    private function jsonToArray($json, $imagePrefix, $assetTag)
    {
        $jsonForYaml = [];
    
        foreach ($json as $jsonItem) {
            foreach ($jsonItem as $itemKey => $itemValue) {
                $jsonItem[$itemKey] = str_replace(["\n", "\r"], '', $itemValue);
                if ($imagePrefix && $assetTag) {
                    $jsonItem[$itemKey] = str_replace($imagePrefix, $assetTag, $jsonItem[$itemKey]);
                }
            }
            $jsonItem['enabled'] = true;
            $jsonForYaml[] = $jsonItem;
        }

        return $jsonForYaml;
    }

}
