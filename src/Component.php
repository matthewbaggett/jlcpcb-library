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
}