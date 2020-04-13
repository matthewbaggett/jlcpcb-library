<?php

namespace Party;

use ForceUTF8\Encoding;
use Gone\UUID\UUID;
use Stichoza\GoogleTranslate\GoogleTranslate;
use Symfony\Component\Yaml\Yaml;

class Component
{
    private string $lcscPartNumber;
    private string $manufacturerPart;
    private string $categoryFirst;
    private string $categorySecond;
    private string $package;
    private int $padCount;
    private string $manufacturer;
    private bool $isExpanded;
    private bool $valid = true;

    private array $packageAliases = [
        'SOT-23-3'  => ['SOT-23-3L'],
        'SOT-23-6'  => ['SOT-23-6L'],
        'SOT-323'   => ['SOT-323F'],
        'SOD-123'   => ['SOD-123FL'],
        'SMB'       => ['SMBF'],
        'SOIC-16'   => ['SOIC-16_3.9x9.9x1.27P'],
    ];

    private array $bodgeStringReplacement = [
        'LED_LED_' => 'LED_',
    ];

    public function getDebugName(): string
    {
        return sprintf("%s's %s", $this->getManufacturer(), $this->pickDeviceName());
    }

    public function pickValue() : string
    {
        if(stripos($this->getManufacturerPart()," ") !== false){
            $part = substr($this->getManufacturerPart(),0, stripos($this->getManufacturerPart(), " "));
            if(stripos($part, "(") !== false) {
                $part = substr($part, 0, stripos($part, "("));
            }
            return $part;
        }
        return $this->getManufacturerPart();
    }

    public function pickDeviceUUID() : string
    {
        return UUID::v4Hash([$this->pickDeviceName()]);
    }

    private array $translations = [];
    public function translate($input) : string{
        $tr = new GoogleTranslate('en'); // Translates into English
        $translationFile = __DIR__ . "/../cache/translations.yaml";

        if(empty(!$this->translations) && file_exists($translationFile)){
            $this->translations = Yaml::parseFile($translationFile);
        }
        if(!isset($this->translations[$input])){
            try {
                $this->translations[$input] = $tr->translate($input);
                file_put_contents($translationFile, Yaml::dump($this->translations));
            }catch(\ErrorException $errorException){
                $this->valid = false;
                $this->debug(sprintf(
                    "WARNING \e[0;31m\"%s\"\e[0m (\e[0;32m%s\e[0m): Could not translate %s because %s!",
                    $this->getDebugName(),
                    $this->getLcscPartNumber(),
                    $input,
                    $errorException->getMessage()
                ));
            }
        }

        return $this->translations[$input];
    }

    public function pickDeviceName(): ?string
    {
        // Get the ManufacturerPart
        $part = trim($this->getManufacturerPart());

        // Make sure its converted to UTF8
        $part = Encoding::toUTF8($part);

        // Detect chinese, translate to english characters.
        if(preg_match("/\p{Han}+/u", $part)){
            $part = preg_replace("/\p{Han}+/u", 'CN', $part);
            //return null;
            //$part = $this->translate($part);
        }

        $part = preg_replace("/[^A-Za-z0-9_Ω% ]/", '', $part);

        // Replace spaces with underscores.
        $part = str_replace(" ", "_", $part);

        // Uppercase it.
        $part = strtoupper($part);

        // Finally, fix any utf8 wierdness.
        $part = Encoding::fixUTF8($part, Encoding::ICONV_IGNORE);

        return $part;
    }

    public function pickGateName(): string
    {
        $magicLetter = strtoupper(substr($this->getCategoryFirst(), 0, 1));
        return "{$magicLetter}\$1";
    }

    public function pickPrefix(): ?string
    {
        switch($this->pickSymbol()){
            case 'CRYSTAL':
                return 'Y';
            case 'LED':
                return 'D';
            case 'IC':
                return 'IC';
            default:
                return substr($this->pickSymbol(),0,1);
        }
    }

    public function pickGateSymbol() : string{
        return sprintf("%s_%s", $this->pickSymbol(), $this->pickDeviceName());
    }

    public function pickSymbol(): ?string
    {
        switch ($this->getCategoryFirst()) {
            case 'Resistors':
                return "RESISTOR";
            case 'Capacitors':
                return "CAPACITOR";
            case 'Diodes':
                return "DIODE";
            case 'Crystals':
                return "CRYSTAL";
            case 'Fuses':
                return "FUSE";
            case 'Embedded Processors & Controllers':
            case 'Embedded Peripheral ICs':
            case 'Power Management ICs':
            case 'Driver ICs':
            case 'Logic ICs':
            case 'Analog ICs':
            case 'Interface ICs':
            case 'Memory':
                return "IC";
            case 'Optocouplers & LEDs & Infrared':
                switch ($this->getCategorySecond()){
                    case 'Light Emitting Diodes (LED)':
                        return 'LED';
                    default:
                        return null;
                    }
            default:
                return null;
        }
    }

    private ?string $_foundPackage = null;

    public function pickPackage(): string
    {
        if($this->_foundPackage){
            return $this->_foundPackage;
        }
        $potentialPackages = explode(",", $this->getPackage());

        foreach($potentialPackages as &$potentialPackage){
            $potentialPackage = trim($potentialPackage);
            foreach($this->packageAliases as $alias => $matches){
                foreach($matches as $match){
                    if($match == $potentialPackage){
                        $potentialPackage = $alias;
                    }
                }
            }
        }

        $this->_foundPackage = strtoupper(sprintf(
            "%s_%s",
            $this->pickSymbol(),
            $potentialPackages[0]
        ));

        // Bodge:
        foreach($this->bodgeStringReplacement as $bad => $good) {
            $this->_foundPackage = str_replace($bad, $good, $this->_foundPackage);
        }

        return $this->_foundPackage;
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

    private ?string $_detectedSymbol = null;

    public function hasBespokePart(\DOMXPath $xpath) : bool
    {
        $symbolBespoke = $this->pickSymbol() . "_" . $this->pickDeviceName();
        $xpathPinsBespoke = "//symbols/symbol[@name=\"{$symbolBespoke}\"]/pin";

        // If there is a bespoke matching part, use it.
        $pinsBespoke = $xpath->query($xpathPinsBespoke);
        if($pinsBespoke->count() > 0){
            $this->_detectedSymbol = $symbolBespoke;
            $this->debug(sprintf(
                "NOTICE \e[0;31m\"%s\"\e[0m (\e[0;32m%s\e[0m): Found a bespoke Symbol: %s !",
                $this->getDebugName(),
                $this->getLcscPartNumber(),
                $this->_detectedSymbol
            ));
            return true;
        }
        $this->_detectedSymbol = $this->pickSymbol();
        return false;

    }
    public function isValid(\DOMXPath $xpath): bool
    {
        $this->hasBespokePart($xpath);
        if($this->pickDeviceName() === null){
            $this->debug(sprintf(
                "WARNING \e[0;31m\"%s\"\e[0m (\e[0;32m%s\e[0m): Could not generate a device name!",
                $this->getDebugName(),
                $this->getLcscPartNumber()
            ));
            return false;
        }
        if(!$this->pickSymbol()){
            $this->debug(sprintf(
                "WARNING \e[0;31m\"%s\"\e[0m (\e[0;32m%s\e[0m): Could not pick a symbol suitable for category \e[0,34m%s\e[0m!",
                $this->getDebugName(),
                $this->getLcscPartNumber(),
                $this->getCategoryFirst()
            ));
            return false;
        }
        $xpathPackage = "//packages/package[@name=\"{$this->pickPackage()}\"]";
        $xpathPins = "//symbols/symbol[@name=\"{$this->_detectedSymbol}\"]/pin";
        $xpathPads = "{$xpathPackage}/smd";
        $package = $xpath->query($xpathPackage);
        if ($package->count() == 0) {
            $this->debug(sprintf(
                "WARNING \e[0;31m\"%s\"\e[0m (\e[0;32m%s\e[0m): Package %s doesn't exist!",
                $this->getDebugName(),
                $this->getLcscPartNumber(),
                $this->pickPackage()
            ));
            return false;
        }

        // If there is a bespoke matching part, use it.
        $pins = $xpath->query($xpathPins);
        $pads = $xpath->query($xpathPads);
        if (!($pins->count() == $this->getPadCount() && $pads->count() == $this->getPadCount())) {
            $this->debug(sprintf(
                "WARNING \e[0;31m\"%s\"\e[0m (\e[0;32m%s\e[0m): Pins (%d) and Pads (%d) count don't add up!",
                $this->getDebugName(),
                $this->getLcscPartNumber(),
                count($pins), count($pads)
            ));
            $this->debug(sprintf(" > xpath pins: %s", $xpathPins));
            $this->debug(sprintf(" > xpath pads: %s", $xpathPads));
            return false;
        }

        return true;
    }

    public function __construct($rowData)
    {
        $this->lcscPartNumber       = trim($rowData['LCSC Part']);
        $this->manufacturerPart     = trim($rowData['MFR.Part']);
        $this->categoryFirst        = trim($rowData['First Category']);
        $this->categorySecond       = trim($rowData['Second Category']);
        $this->package              = trim($rowData['Package']);
        if (is_numeric(trim($rowData['Solder Joint']))) {
            $this->padCount         = intval($rowData['Solder Joint']);
        } else {
            die("Unknown Solder Joint count: {$rowData['Solder Joint']}\n");
        }
        $this->manufacturer         = trim($rowData['Manufacturer']);
        switch (trim($rowData['Library Type'])) {
            case 'base':
                $this->isExpanded = false;
                break;
            case 'expand':
                $this->isExpanded = true;
                break;
            default:
                die("Unknown Library Type: {$rowData['Library Type']}\n");
        };
    }

    public function getLcscPartNumber(): string
    {
        return $this->lcscPartNumber;
    }

    /**
     * @param mixed|string $lcscPartNumber
     * @return Component
     */
    public function setLcscPartNumber($lcscPartNumber)
    {
        $this->lcscPartNumber = $lcscPartNumber;
        return $this;
    }

    public function getManufacturerPart(): string
    {
        return $this->manufacturerPart;
    }

    /**
     * @param string $manufacturerPart
     * @return Component
     */
    public function setManufacturerPart($manufacturerPart)
    {
        $this->manufacturerPart = $manufacturerPart;
        return $this;
    }

    /**
     * @return mixed|string
     */
    public function getCategoryFirst()
    {
        return $this->categoryFirst;
    }

    /**
     * @param mixed|string $categoryFirst
     * @return Component
     */
    public function setCategoryFirst($categoryFirst)
    {
        $this->categoryFirst = $categoryFirst;
        return $this;
    }

    /**
     * @return mixed|string
     */
    public function getCategorySecond()
    {
        return $this->categorySecond;
    }

    /**
     * @param mixed|string $categorySecond
     * @return Component
     */
    public function setCategorySecond($categorySecond)
    {
        $this->categorySecond = $categorySecond;
        return $this;
    }

    /**
     * @return mixed|string
     */
    public function getPackage()
    {
        return $this->package;
    }

    /**
     * @param mixed|string $package
     * @return Component
     */
    public function setPackage($package)
    {
        $this->package = $package;
        return $this;
    }

    /**
     * @return int|string
     */
    public function getPadCount()
    {
        return $this->padCount;
    }

    /**
     * @param int|string $padCount
     * @return Component
     */
    public function setPadCount($padCount)
    {
        $this->padCount = $padCount;
        return $this;
    }

    /**
     * @return mixed|string
     */
    public function getManufacturer()
    {
        return $this->manufacturer;
    }

    /**
     * @param mixed|string $manufacturer
     * @return Component
     */
    public function setManufacturer($manufacturer)
    {
        $this->manufacturer = $manufacturer;
        return $this;
    }

    /**
     * @return bool
     */
    public function isExpanded(): bool
    {
        return $this->isExpanded;
    }

    /**
     * @param bool $isExpanded
     * @return Component
     */
    public function setIsExpanded(bool $isExpanded): Component
    {
        $this->isExpanded = $isExpanded;
        return $this;
    }


}