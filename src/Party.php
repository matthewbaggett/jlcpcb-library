<?php
namespace Party;
use Cache\Adapter\Apcu\ApcuCachePool;
use Cache\Bridge\SimpleCache\SimpleCacheBridge;
use GuzzleHttp\Client as Guzzle;
use League\Flysystem\Adapter\Local;
use League\Flysystem\Filesystem;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Settings as SpreadsheetSettings;

class Party{
    private Guzzle $guzzle;
    private Filesystem $storage;
    private ApcuCachePool $pool;
    private SimpleCacheBridge $simpleCache;
    /** @var Component[] */
    private array $components = [];

    const JLCPCB_SHEET_CACHE_FILE = "jlcpcb.xlsx";
    const CACHE_PATH = "cache/";

    public function __construct()
    {
        $this->guzzle = new Guzzle();
        $this->storage = new Filesystem(new Local(self::CACHE_PATH));
        $this->pool = new ApcuCachePool();
        $this->simpleCache = new SimpleCacheBridge($this->pool);
        //SpreadsheetSettings::setCache($this->simpleCache);
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

    private function readSheet($filePath = self::CACHE_PATH . self::JLCPCB_SHEET_CACHE_FILE){
        printf("Loading in components from {$filePath} ... ");
        $spreadsheet = IOFactory::load($filePath);

        foreach($spreadsheet->getWorksheetIterator() as $worksheet){
            $columns = [];
            foreach($worksheet->getRowIterator() as $rowNum => $row){
                if($rowNum == 1){
                    $columns = $spreadsheet->getActiveSheet()->rangeToArray("A{$rowNum}:H{$rowNum}")[0];
                }elseif($rowNum > 1){
                    $rowData = array_combine($columns, $spreadsheet->getActiveSheet()->rangeToArray("A{$rowNum}:H{$rowNum}")[0]);
                    if(!empty($rowData['LCSC Part'])) {
                        $this->components[] = (new Component($rowData));
                    }
                }
            }
        }
        printf("%d components found.\n", count($this->components));
    }

    /**
     * @param string $componentGroupName
     * @param Component[] $components
     */
    private function generateLibrary(string $componentGroupName, array $components){
        $libraryFile = new \DOMDocument('1.0');
        $libraryFile->preserveWhiteSpace = false;
        $libraryFile->formatOutput = true;
        $libraryFile->loadXML(file_get_contents( "assets/empty.lbr"));
        $xpath = new \DOMXPath($libraryFile);
        /** @var \DOMElement $deviceSets */
        $deviceSets = $libraryFile->getElementsByTagName('devicesets')[0];

        // Remove existing deviceset garbage data.
        while($deviceSets->hasChildNodes()){
            $deviceSets->removeChild($deviceSets->firstChild);
        }

        // Keep a list of the referenced packages/symbols so we can clean up the un-used at the end.
        $referencedPackages = [];
        $referencedSymbols = [];

        // Iterate over components and insert them.
        foreach($components as $component){
            $newDeviceSet = $libraryFile->createElement('deviceset');
            $newDeviceSet->setAttribute('name', $component->pickDeviceName());
            $gates = $libraryFile->createElement('gates');
            $gate = $libraryFile->createElement('gate');
            //<gate name="R$1" symbol="RESISTOR" x="0" y="0"/>
            $gate->setAttribute("name", $component->pickGateName());
            $gate->setAttribute("x","0");
            $gate->setAttribute("y","0");
            $gate->setAttribute("symbol", $component->pickSymbol());
            $gates->appendChild($gate);
            $devices = $libraryFile->createElement('devices');
            $device = $libraryFile->createElement('device');
            //<device name="" package="LED-1206">
            //    <connects>
            //        <connect gate="L$1" pin="A" pad="A"/>
            //        <connect gate="L$1" pin="C" pad="C"/>
            //    </connects>
            //    <technologies>
            //        <technology name=""/>
            //    </technologies>
            //</device>
            $device->setAttribute('name','');
            $device->setAttribute('package', $component->pickPackage());
            $referencedPackages[] = $component->pickPackage();
            $referencedSymbols[] = $component->pickSymbol();

            $connects = $libraryFile->createElement('connects');

            $xpathPins = "//symbols/symbol[@name=\"{$component->pickSymbol()}\"]/pin";
            $xpathPads = "//packages/package[@name=\"{$component->pickPackage()}\"]/smd";
            $pins = $xpath->query($xpathPins);
            $pads = $xpath->query($xpathPads);
            if(!($pins->count() == $component->getPadCount() && $pads->count() == $component->getPadCount())){
                printf(
                    "Pins (%d) and Pads (%d) count for %s don't add up!\n",
                    count($pins), count($pads),
                    $component->getLcscPartNumber()
                );

                \Kint::dump(
                    $xpathPins,
                    $xpathPads
                );

                exit(1);
            }

            for($i = 0; $i < $component->getPadCount(); $i++) {
                /** @var \DOMElement $pin */
                $pin = $pins[$i];
                /** @var \DOMElement $pad */
                $pad = $pads[$i];
                $connect = $libraryFile->createElement('connect');
                $connect->setAttribute('gate',$component->pickGateName());
                $connect->setAttribute('pin',$pin->getAttribute('name'));
                $connect->setAttribute('pad',$pad->getAttribute('name'));
                $connects->appendChild($connect);
            }
            $device->appendChild($connects);
            $technologies = $libraryFile->createElement('technologies');
            $technology = $libraryFile->createElement('technology');
            $technology->setAttribute('name', '');
            $technologies->appendChild($technology);
            $device->appendChild($technologies);
            $devices->appendChild($device);
            $newDeviceSet->appendChild($gates);
            $newDeviceSet->appendChild($devices);
            $deviceSets->appendChild($newDeviceSet);
        }

        // Filter referenced packages/symbols
        $referencedPackages = array_unique($referencedPackages);
        $referencedSymbols = array_unique($referencedSymbols);

        // Find non-referenced packages & symbols
        $unreferencedPackages = [];
        foreach($xpath->query("//packages/package") as $package){
            /** @var \DOMElement $package */
            $name = $package->getAttribute('name');
            if(!in_array($name, $referencedPackages)){
                $unreferencedPackages[] = $name;
            }
        }
        $unreferencedPackages = array_filter($unreferencedPackages);

        $unreferencedSymbols = [];
        foreach($xpath->query("//symbols/symbol") as $symbol){
            /** @var \DOMElement $symbol */
            $name = $symbol->getAttribute('name');
            if(!in_array($name, $referencedSymbols)){
                $unreferencedSymbols[] = $name;
            }
        }
        $unreferencedSymbols = array_filter($unreferencedSymbols);

        foreach($unreferencedPackages as $unreferencedPackage){
            foreach($xpath->query("//packages/package[@name=\"{$unreferencedPackage}\"]") as $elem) {
                $elem->parentNode->removeChild($elem);
            }
        }

        foreach($unreferencedSymbols as $unreferencedSymbol){
            foreach($xpath->query("//symbols/symbol[@name=\"{$unreferencedSymbol}\"]") as $elem) {
                $elem->parentNode->removeChild($elem);
            }
        }

        // Pretty print our library
        $prettyXML = $libraryFile->saveXML();
        $prettyOutputFilename = "$componentGroupName.lbr";
        file_put_contents($prettyOutputFilename, $prettyXML);
        printf(
            "Wrote %d components in %sKB to %s\n",
            count($components),
            number_format(strlen($prettyXML) / 1024,2),
            $prettyOutputFilename
        );
    }

    private function sortComponents(){
        $groups = [];

        foreach($this->components as $component){
            /** @var Component $component */
            $groups[$component->getCategoryFirst()][] = $component;
        }

        return $groups;
    }

    public function build(){
        $this->downloadSheet();
        $this->readSheet(self::CACHE_PATH . "jlcpcb_trimmed.xlsx");
        printf(
            "Found %d components in latest version of %s\n",
            count($this->components),
            self::JLCPCB_SHEET_CACHE_FILE
        );
        $componentGroups = $this->sortComponents();
        foreach($componentGroups as $componentGroupName => $components) {
            $this->generateLibrary($componentGroupName, $components);
        }
    }
}