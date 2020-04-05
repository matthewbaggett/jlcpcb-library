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
        return sprintf(
            "%s_%s",
            str_replace(" ", "_", $this->getManufacturerPart()),
            $this->getLcscPartNumber()
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
            default:
                return null;
        }
    }

    public function pickPackage(): string
    {
        return strtoupper(sprintf(
            "%s_%s",
            $this->pickSymbol(),
            $this->getPackage()
        ));
    }

    public function isValid(\DOMXPath $xpath): bool
    {
        if(!$this->pickSymbol()){
            printf(
                "WARNING \e[0;31m\"%s\"\e[0m (\e[0;32m%s\e[0m): Could not pick a symbol suitable for category \e[0,34m%s\e[0m!\n",
                $this->getDebugName(),
                $this->getLcscPartNumber(),
                $this->getCategoryFirst()
            );
            return false;
        }
        $xpathPackage = "//packages/package[@name=\"{$this->pickPackage()}\"]";
        $xpathPins = "//symbols/symbol[@name=\"{$this->pickSymbol()}\"]/pin";
        $xpathPads = "{$xpathPackage}/smd";
        $package = $xpath->query($xpathPackage);
        if ($package->count() == 0) {
            printf(
                "WARNING \e[0;31m\"%s\"\e[0m (\e[0;32m%s\e[0m): Package %s doesn't exist!\n",
                $this->getDebugName(),
                $this->getLcscPartNumber(),
                $this->pickPackage()
            );
            return false;
        }
        $pins = $xpath->query($xpathPins);
        $pads = $xpath->query($xpathPads);
        if (!($pins->count() == $this->getPadCount() && $pads->count() == $this->getPadCount())) {
            printf(
                "WARNING \e[0;31m\"%s\"\e[0m (\e[0;32m%s\e[0m): Pins (%d) and Pads (%d) count don't add up!\n",
                $this->getDebugName(),
                $this->getLcscPartNumber(),
                count($pins), count($pads)
            );
            printf(" > xpath pins: %s\n", $xpathPins);
            printf(" > xpath pads: %s\n", $xpathPads);
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