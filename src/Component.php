<?php
namespace Party;

class Component {
    private string $lcscPartNumber;
    private string $manufacturerPart;
    private string $categoryFirst;
    private string $categorySecond;
    private string $package;
    private int $padCount;
    private string $manufacturer;
    private bool $isExpanded;

    public function pickDeviceName(){
        $name = $this->getManufacturerPart();
        $name = str_replace(" ", "_", $name);
        return $name;
    }

    public function pickGateName(){
        $magicLetter = strtoupper(substr($this->getCategoryFirst(), 0,1));
        return "{$magicLetter}\$1";
    }

    public function pickSymbol(){
        switch($this->getCategoryFirst()){
            case 'Resistors':
                return "RESISTOR";
            case 'Capacitors':
                return "CAPACITOR";
            default:
                die("Can't pick a symbol that matches {$this->getCategoryFirst()}!\n");
        }
    }

    public function pickPackage(){
        return sprintf(
            "%s_%s",
            $this->pickSymbol(),
            $this->getPackage()
        );
    }

    public function __construct($rowData)
    {
        $this->lcscPartNumber = $rowData['LCSC Part'];
        $this->manufacturerPart = $rowData['MFR.Part'];
        $this->categoryFirst = $rowData['First Category'];
        $this->categorySecond = $rowData['Second Category'];
        $this->package = $rowData['Package'];
        if(is_numeric($rowData['Solder Joint'])) {
            $this->padCount = $rowData['Solder Joint'];
        }else{
            die("Unknown Solder Joint count: {$rowData['Solder Joint']}\n");
        }
        $this->manufacturer = $rowData['Manufacturer'];
        switch($rowData['Library Type']){
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

    /**
     * @return mixed|string
     */
    public function getLcscPartNumber()
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

    /**
     * @return mixed|string
     */
    public function getManufacturerPart()
    {
        return $this->manufacturerPart;
    }

    /**
     * @param mixed|string $manufacturerPart
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