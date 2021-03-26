<?php

namespace Josephd\MysqlToStatamic\Console;

use Illuminate\Console\Command;
use Statamic\Facades\Collection;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Entry;
use Statamic\Facades\Term;
use Statamic\Facades\Stache;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Symfony\Component\Yaml\Yaml;
use Illuminate\Support\Facades\Storage;

class DatabaseToStatamic extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mysql-to-statamic:run
                            {--conversion= : Name of conversion to run}
                            { --confirm= : Run without showing confirmation }';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Converts data from MySQL databases to Statamic YAML';

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
        if (!$conversion = $this->option('conversion')) {
            $conversion = $this->choice('Which conversion do you want to run? 🤔', array_flatten(array_diff(scandir(config_path('conversions/')), array('..', '.'))));
        }
        
        $this->yaml = Yaml::parse(file_get_contents(config_path('conversions/' . $conversion)));

        $this->tableName = $this->yaml['table']['name'];
        $this->tableLimit = $this->yaml['table']['limit'] ?? null;
        $this->date = $this->yaml['table']['date'] ?? null;
        $this->fieldNames = $this->yaml['table']['fields'];
        $this->destinationType = $this->yaml['destination']['type'];
        $this->destinationName = $this->yaml['destination']['collectionName'];

        $this->replicatorName = $this->yaml['destination']['replicatorName'] ?? null;
        $this->imagePrefix = $this->yaml['imageLinks']['prefix'] ?? null;
        $this->assetTag = $this->yaml['imageLinks']['assetTag'] ?? null;
        
        $entries = $this->getEntries();

        $this->info(count($this->fieldNames) . ' fields across ' . count($entries) . ' records will be converted');

        $this->newLine();

        if ($this->imagePrefix && $this->assetTag) {
            $this->info('All occurances of \'' . $this->imagePrefix . '\' will be replaced with \'' . $this->assetTag . '\'');
        }


        if ($this->option('confirm') || $this->confirm('Are you ready? 🏁', false)) {
            $this->info('Converting ' . $this->tableName . " 🚧");
            
            $this->convertRecords($entries, $this->fieldNames, $this->destinationName, $this->imagePrefix, $this->assetTag, $this->destinationType);
        
            $this->info('The ' . $this->tableName . ' MySQL table has been converted into Statamic ' . $this->destinationType . ' \''. $this->destinationName . '\' ✨😀');
    
            $this->newLine();
            
            Stache::clear();
        } else {
            $this->info("Ok, bye then  😥");

            $this->newLine();
        }
    }

    private function convertRecords($entries)
    {
        $bar = $this->output->createProgressBar(count($entries));
        
        $this->newLine();
        
        $bar->start();
        
        if ($this->destinationType == 'global') {
            $global = [];
        }
        
        foreach ($entries as $entry) {
            $data = new \stdClass();
            
            if (isset($entry->file_name) && isset($entry->disk_name)) {
                $image = file_get_contents('https://cdn.ajarn.com/uploads/public/' . implode('/', array_slice(str_split($entry->disk_name, 3), 0, 3)) . '/' . $entry->disk_name);
                file_put_contents(public_path('assets/' . $this->destinationName . '/' . $entry->file_name), $image);
            }

            if ($this->destinationType == 'collection') {
                // Create entry
                $fields = $this->makeFields($entry);

                if ($this->date) {
                    $date = Carbon::parse($entry->{$this->date})->format('Y-m-d');
                } else {
                    $date = null;
                }
            
                $newEntry = Entry::make()
                    ->slug($fields['slug'] ?? $fields['title'])
                    ->date($date)
                    ->published($fields['published'])
                    ->collection($this->destinationName)
                    ->data($fields);
                $newEntry->save();
            } elseif ($this->destinationType == 'taxonomy') {
                // Create term
                $fields = $this->makeFields($entry);
                $newTerm = Term::make()
                    ->slug($fields['slug'] ?? $fields['title'])
                    ->taxonomy($this->destinationName)
                    ->data($fields);
                $newTerm->save();
            } elseif ($this->destinationType == 'global') {
                // Create global
                $fields = $this->makeFields($entry);
                $fields['type'] = $this->replicatorName;
                $global[] = $fields;
            }

            $bar->advance();
        }

        if ($this->destinationType == 'global') {
            $this->saveGlobal($global);
        }

        $bar->finish();

        $this->newLine(2);
    }

    private function makeFields($entry)
    {
        foreach ($this->fieldNames as $fieldName => $values) {
            if ($values['type'] == 'boolean') {
                $fields[$fieldName] = (boolean)$entry->{$values['from']};
            } elseif ($values['type'] == 'attachment') {
                $fields[$fieldName] = 'assets::' . $this->destinationName . '/' . $entry->file_name;
            } elseif ($values['type'] == 'timestamp') {
                $fields[$fieldName] = Carbon::parse($entry->{$values['from']})->timestamp;
            } elseif ($values['type'] == 'relation') {
                $fields[$fieldName] = DB::table($this->yaml['table']['fields'][$fieldName]['relatedTable'])
                ->limit(1)->where('id', $entry->{$values['from']})
                ->get('slug')
                ->toArray()[0]
                ->{$values['relatedField']};
            } elseif ($values['type'] == 'json') {
                $jsonItems = json_decode($entry->{$values['from']});

                $fields[$fieldName] = [];

                foreach ($jsonItems as $item) {
                    $fields[$fieldName][] = [
                        strtolower($item->type) => str_replace(
                            $values['imageLinks']['prefix'],
                            $values['imageLinks']['assetTag'],
                            str_replace(
                                                            ["\n", "\r"],
                                                            '',
                                                            $item->{$values['jsonFields']['content']}
                                                        )
                        ),
                        'type' => strtolower($item->{$values['jsonFields']['type']}),
                        'enabled' => true
                    ];
                }
            } else {
                $fields[$fieldName] = $entry->{$values['from']};
            }
            
            if (isset($values['imageLinks'])) {
                $fields[$fieldName] = str_replace($values['imageLinks']['prefix'], $values['imageLinks']['assetTag'], $fields[$fieldName]);
            }
        }

        return $fields;
    }
    private function jsonToArray($json)
    {
        $jsonForYaml = [];
        dd(json_decode($json));
        foreach ($json as $jsonItem) {
            foreach ($jsonItem as $itemKey => $itemValue) {
                $jsonItem[$itemKey] = str_replace(["\n", "\r"], '', $itemValue);
                if ($this->imagePrefix && $this->assetTag) {
                    $jsonItem[$itemKey] = str_replace($this->imagePrefix, $this->assetTag, $jsonItem[$itemKey]);
                }
            }
            $jsonItem['enabled'] = true;
            $jsonForYaml[] = $jsonItem;
        }

        return $jsonForYaml;
    }

    private function saveGlobal($global)
    {
        $global['sponsor'] = $global;

        $set = GlobalSet::findByHandle($this->destinationName);

        $set = $set->in('default');

        $fields = $set->blueprint()->fields()->addValues($global);

        $values = $fields->process()->values();

        $set->data($values);

        $set->save();
    }

    private function getEntries()
    {
        $attachment = array_filter($this->fieldNames, function ($item) {
            return isset($item['type']) &&
                $item['type'] === 'attachment';
        });

        if (count($attachment) === 1) {
            foreach ($attachment as $items) {
                $model = $items['from'];
            }
            return DB::table($this->tableName)
            ->limit($this->tableLimit)
            ->select($this->tableName.'.*', 'system_files.disk_name', 'system_files.file_name')
            ->join('system_files', function ($join) use ($model) {
                $join->on($this->tableName.'.id', '=', 'system_files.attachment_id')
                     ->where('system_files.attachment_type', '=', $model);
            })
            ->orderBy('sort_order', 'ASC')
            ->orderBy('published_at', 'DESC')
            ->get();
        } else {
            return DB::table($this->tableName)
            ->limit($this->tableLimit)
            // ->orderBy('sort_order','ASC')
            ->orderBy('published_at', 'DESC')
            ->get();
        }
    }
}
