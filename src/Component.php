<?php

namespace Party;

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

    public function getDebugName(): string
    {
        return sprintf("%s's %s", $this->getManufacturer(), $this->pickDeviceName());
    }

    public function pickDeviceName(): string
    {
        return str_replace(" ", "_", $this->getManufacturerPart());
        return sprintf(
            "%s_%s",
            $this->getLcscPartNumber(),
            str_replace(" ", "_", $this->getManufacturerPart())
        );
    }

    public function pickGateName(): string
    {
        $magicLetter = strtoupper(substr($this->getCategoryFirst(), 0, 1));
        return "{$magicLetter}\$1";
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
            case 'Power Management ICs':
            case 'Driver ICs':
            case 'Logic ICs':
            case 'Analog ICs':
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

    private array $packageAliases = [
        'SOT-23-3'  => ['SOT-23-3L'],
        'SOT-23-6'  => ['SOT-23-6L'],
        'SOT-323'   => ['SOT-323F'],
        'SOD-123'   => ['SOD-123FL'],
        'SMB'       => ['SMBF'],
    ];

    public function pickPackage(): string
    {
        $potentialPackages = explode(",", $this->getPackage());

        foreach($potentialPackages as $potentialPackage){
            $potentialPackage = trim($potentialPackage);
            foreach($this->packageAliases as $alias => $matches){
                foreach($matches as $match){
                    if($match == $potentialPackage){
                        $potentialPackage = $alias;
                    }
                }
            }
        }

        return strtoupper(sprintf(
            "%s_%s",
            $this->pickSymbol(),
            $potentialPackages[0]
        ));
    }

    private function debug(string $message) : void
    {
        echo $message . "\n";
        file_put_contents(
            "validation.log",
            $test = preg_replace('#\\x1b[[][^A-Za-z]*[A-Za-z]#', '', $message) . "\n",
            FILE_APPEND
        );
    }

    public function isValid(\DOMXPath $xpath): bool
    {
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
        $xpathPins = "//symbols/symbol[@name=\"{$this->pickSymbol()}\"]/pin";
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
        $pins = $xpath->query($xpathPins);
        $pads = $xpath->query($xpathPads);
        if (!($pins->count() == $this->getPadCount() && $pads->count() == $this->getPadCount())) {
            $this->debug(sprintf(
                "WARNING \e[0;31m\"%s\"\e[0m (\e[0;32m%s\e[0m): Pins (%d) and Pads (%d) count don't add up!",
                $this->getDebugName(),
                $this->getLcscPartNumber(),
                count($pins), count($pads)
            ));
            //$this->debug(sprintf(" > xpath pins: %s", $xpathPins));
            //$this->debug(sprintf(" > xpath pads: %s", $xpathPads));
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