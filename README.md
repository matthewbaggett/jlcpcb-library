JLCPCB Autogenerated parts library
==================================

JLCPCB kindly provides a nice spreadsheet of parts available for their PCBA service.

I thought it'd be useful to turn that into an eaglecad parts library.

To maximise its usefulness, I've done a few other things:

 * Copied Sparkfuns fabulous Power Symbols library
 * Copied CAM job, Desirn Rules file & ULPs from https://github.com/oxullo/jlcpcb-eagle
 
## TODO List:
 * Fix non-trivial packages (multipin ICs)
 * Write Guide on how to export for JLCPCB:
   * Layout guidelines
   * Part picking
   * CAM exporting
   * Running jlcpcb_smta_exporter.ulp to get BOM and placement.
 * Dockerise this tool
 * Create github actions to run on a schedule.
 * Add github actions linting.
