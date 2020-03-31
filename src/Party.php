<?php
namespace Party;
use GuzzleHttp\Client as Guzzle;
use League\Flysystem\Adapter\Local;
use League\Flysystem\Filesystem;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Row;

class Party{
    private Guzzle $guzzle;
    private Filesystem $storage;
    /** @var Component[] */
    private array $components = [];

    const JLCPCB_SHEET_CACHE_FILE = "jlcpcb.xlsx";
    const CACHE_PATH = __DIR__ . "/../cache/";

    public function __construct()
    {
        $this->guzzle = new Guzzle();
        $this->storage = new Filesystem(new Local(self::CACHE_PATH));
    }

    private function downloadSheet(){
        if(!$this->storage->has(self::JLCPCB_SHEET_CACHE_FILE) || $this->storage->getTimestamp("jlcpcb.xlsx") < time() - 86400) {
            $filePath = self::CACHE_PATH . self::JLCPCB_SHEET_CACHE_FILE;

            $this->guzzle->request(
                "get",
                "https://jlcpcb.com/componentSearch/uploadComponentInfo",
                [
                    'sink' => $filePath
                ]
            );
        }
    }

    private function readSheet(){
        $filePath = self::CACHE_PATH . self::JLCPCB_SHEET_CACHE_FILE;
        $spreadsheet = IOFactory::load($filePath);

        foreach($spreadsheet->getWorksheetIterator() as $worksheet){
            foreach($worksheet->getRowIterator() as $rowNum => $row){
                if($rowNum == 1){
                    $columns = $spreadsheet->getActiveSheet()->rangeToArray("A{$rowNum}:H{$rowNum}")[0];
                }
                if($rowNum > 1){
                    /** @var $row Row */
                    $rowData = array_combine($columns, $spreadsheet->getActiveSheet()->rangeToArray("A{$rowNum}:H{$rowNum}")[0]);
                    $this->components[] = (new Component($rowData));
                }
            }
        }
    }

    private function generateLibrary(){

    }

    public function build(){
        $this->downloadSheet();
        $this->readSheet();
        printf(
            "Found %d components in latest version of %s\n",
            count($this->components),
            self::JLCPCB_SHEET_CACHE_FILE
        );
        $this->generateLibrary();
    }
}