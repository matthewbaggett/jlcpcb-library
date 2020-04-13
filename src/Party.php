<?php
namespace Party;
use Cache\Adapter\Apcu\ApcuCachePool;
use Cache\Bridge\SimpleCache\SimpleCacheBridge;
use ForceUTF8\Encoding;
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
        $this->guzzle       = new Guzzle();
        $this->storage      = new Filesystem(new Local(self::CACHE_PATH));
        $this->pool         = new ApcuCachePool();
        $this->simpleCache  = new SimpleCacheBridge($this->pool);
        //SpreadsheetSettings::setCache($this->simpleCache);
    }

    private function downloadSheet()
    {
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

    private function readSheet($filePath = self::CACHE_PATH . self::JLCPCB_SHEET_CACHE_FILE)
    {
        $this->debug(sprintf("Loading in components from {$filePath} ... "));
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
        $this->debug(sprintf("%d components found.\n", count($this->components)));
    }

    private function generateDeviceGates(\DOMDocument $libraryFile, Component $component, bool $hasBespokeSymbol = false) : \DOMElement
    {
        $gates = $libraryFile->createElement('gates');
        $gate = $libraryFile->createElement('gate');
        $gate->setAttribute("name", $component->pickGateName());
        $gate->setAttribute("x","0");
        $gate->setAttribute("y","0");
        $gate->setAttribute("symbol", $hasBespokeSymbol ? $component->pickGateSymbol() : $component->pickSymbol());
        $gates->appendChild($gate);
        return $gates;
    }

    private function generateDeviceDevice(\DOMDocument $libraryFile, Component $component) : \DOMElement
    {
        $device = $libraryFile->createElement('device');

        $name = $component->getLcscPartNumber();
        $name = substr($name, 0,24);

        $device->setAttribute('name', $name);
        $device->setAttribute('package', $component->pickPackage());
        $device->appendChild($this->generateDeviceConnects($libraryFile, $component));
        $technologies = $libraryFile->createElement('technologies');
        $technology = $libraryFile->createElement('technology');
        $technology->setAttribute('name', '');

        // Add a JLCPCB Part number
        $partNumber = $libraryFile->createElement('attribute');
        $partNumber->setAttribute("name", "LCSC_PART");
        $partNumber->setAttribute("value", $component->getLcscPartNumber());
        $partNumber->setAttribute("constant", "no");
        $technology->appendChild($partNumber);

        // Label it basic or not
        $isBasic = $libraryFile->createElement('attribute');
        $isBasic->setAttribute("name", "JLCPCB_IS_BASIC");
        $isBasic->setAttribute("value", $component->isExpanded() ? "no" : "yes");
        $isBasic->setAttribute("constant", "no");
        $technology->appendChild($isBasic);

        // Label its value
        $value = $libraryFile->createElement('attribute');
        $value->setAttribute("name", "VALUE");
        $value->setAttribute("value", $component->pickValue());
        $value->setAttribute("constant", "no");
        $technology->appendChild($value);

        // Glue the XML together.
        $technologies->appendChild($technology);
        $device->appendChild($technologies);

        return $device;
    }

    private function generateDeviceConnects(\DOMDocument $libraryFile, Component $component) : \DOMElement
    {
        $xpath = new \DOMXPath($libraryFile);

        $symbol = $component->pickSymbol();
        $symbolBespoke = $component->pickSymbol() . "_" . $component->pickDeviceName();
        $xpathPins = "//symbols/symbol[@name=\"{$symbol}\"]/pin";
        $xpathPinsBespoke = "//symbols/symbol[@name=\"{$symbolBespoke}\"]/pin";
        $xpathPads = "//packages/package[@name=\"{$component->pickPackage()}\"]/smd";
        $pins = $xpath->query($xpathPins);
        if($xpath->query($xpathPinsBespoke)->count() > 0){
            $pins = $xpath->query($xpathPinsBespoke);
        }
        $pads = $xpath->query($xpathPads);

        $connects = $libraryFile->createElement('connects');

        for($i = 0; $i < $component->getPadCount(); $i++) {
            /** @var \DOMElement $pin */
            $pin = $pins[$i];
            /** @var \DOMElement $pad */
            $pad = $pads[$i];

            if(!$pin || !$pad){
                \Kint::dump(
                    $component,
                    $i,
                    $pin,
                    $pad
                );
                exit;
            }

            $connect = $libraryFile->createElement('connect');
            $connect->setAttribute('gate',$component->pickGateName());
            $connect->setAttribute('pin',$pin->getAttribute('name'));
            $connect->setAttribute('pad',$pad->getAttribute('name'));
            $connects->appendChild($connect);
        }

        return $connects;
    }

    /**
     * @param string $componentGroupName
     * @param Component[] $components
     * @return int parts count
     */
    private function generateLibrary(string $componentGroupName, array $components) : int
    {
        $libraryFile = new \DOMDocument('1.0');
        $libraryFile->preserveWhiteSpace = false;
        $libraryFile->formatOutput = true;
        $libraryFile->loadXML(file_get_contents( "assets/empty.lbr"));
        $xpath = new \DOMXPath($libraryFile);

        $description = $libraryFile->createElement("description", "JLCPCB automatically generated library");
        $packages = $xpath->query("//packages");
        $packagesElem = $packages->item(0);
        $packagesElem->parentNode->insertBefore($description, $packagesElem);

        /** @var \DOMElement $deviceSets */
        $deviceSets = $libraryFile->getElementsByTagName('devicesets')[0];

        // Remove existing deviceset garbage data.
        while($deviceSets->hasChildNodes()){
            $deviceSets->removeChild($deviceSets->firstChild);
        }

        // Keep a list of the referenced packages/symbols so we can clean up the un-used at the end.
        $referencedPackages = [];
        $referencedSymbols = [];
        $componentsAdded = 0;

        // Iterate over components and insert them.
        foreach($components as $component){
            if(!$component->isValid($xpath)){
                continue;
            }
            $referencedPackages[] = $component->pickPackage();
            $referencedSymbols[] = $component->pickSymbol();
            $referencedSymbols[] = $component->pickGateSymbol();

            $hasBespokePart = $component->hasBespokePart($xpath);

            $existingDeviceSet = $xpath->query("//devicesets/deviceset[@name=\"{$component->pickDeviceName()}\"]");
            if($existingDeviceSet->count() > 0) {
                //$deviceSet = $existingDeviceSet->item(0);
                $devices = $xpath->query("//devicesets/deviceset[@name=\"{$component->pickDeviceName()}\"]/devices");
                $devices->item(0)->appendChild($this->generateDeviceDevice($libraryFile, $component));
            }else{
                $deviceSet = $libraryFile->createElement('deviceset');
                $deviceSets->appendChild($deviceSet);
                $deviceSet->appendChild($this->generateDeviceGates($libraryFile, $component, $hasBespokePart));
                $devices = $libraryFile->createElement('devices');
                $deviceSet->appendChild($devices);
                $devices->appendChild($this->generateDeviceDevice($libraryFile, $component));
                $deviceSet->setAttribute('name', $component->pickDeviceName());
                $deviceSet->setAttribute('prefix', $component->pickPrefix());
                unset($deviceSet);
            }

            if($component->pickDeviceName() == ""){
                \Kint::dump(
                    $component,
                    $component->pickDeviceName()
                );
                die("Device name cannot be empty\n");
            }

            //$newDeviceSet->setAttribute('uuid' , $component->pickDeviceUUID());

            $componentsAdded++;
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

        $prettyOutputFilename = sprintf(
            "lbr/%s.lbr",
            $componentGroupName
        );

        if($componentsAdded > 0) {
            // Pretty print our library
            $prettyXML = $libraryFile->saveXML();
            file_put_contents($prettyOutputFilename, $prettyXML);
            $this->debug(sprintf(
                "Wrote %d components in %sKB to %s\n",
                $componentsAdded,
                number_format(strlen($prettyXML) / 1024, 2),
                $prettyOutputFilename
            ));
        }else{
            $this->debug(sprintf(
                "Skipped writing to %s, no components generated\n",
                $prettyOutputFilename
            ));
        }

        return $componentsAdded;
    }

    private function sortComponents() : array
    {
        $groups = [];

        foreach($this->components as $component){
            /** @var Component $component */
            $groupName = sprintf("%s.%s", $component->getCategoryFirst(), $component->isExpanded() ? 'expanded' : 'basic');
            $groups[$groupName][] = $component;
        }

        return $groups;
    }

    public function build() : void
    {
        echo "Building EAGLE libraries ... \n";

        //$this->downloadSheet();

        $this->readSheet();
        //$this->readSheet( self::CACHE_PATH . "cropped.xlsx");

        $this->debug(sprintf(
            "Found %d components in latest version of %s\n",
            count($this->components),
            self::JLCPCB_SHEET_CACHE_FILE
        ));

        $componentGroups = $this->sortComponents();
        $partsCount = 0;
        foreach($componentGroups as $componentGroupName => $components) {
           $partsCount += $this->generateLibrary($componentGroupName, $components);
        }
        echo "Generated {$partsCount} parts.\n\n";
        echo "\x07";
    }

    private function debug(string $message) : void
    {
        //echo $message . "\n";
        file_put_contents(
            "validation.log",
            $test = preg_replace('#\\x1b[[][^A-Za-z]*[A-Za-z]#', '', $message) . "\n",
            FILE_APPEND
        );
    }
}