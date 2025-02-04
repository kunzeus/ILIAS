<?php

/**
 * This file is part of ILIAS, a powerful learning management system
 * published by ILIAS open source e-Learning e.V.
 *
 * ILIAS is licensed with the GPL-3.0,
 * see https://www.gnu.org/licenses/gpl-3.0.en.html
 * You should have received a copy of said license along with the
 * source code, too.
 *
 * If this is not the case or you just want to try ILIAS, you'll find
 * us at:
 * https://www.ilias.de
 * https://github.com/ILIAS-eLearning
 *
 *********************************************************************/


/**
* XML  parser for folder xml
*
* @author Stefan Meyer <smeyer.ilias@gmx.de>
* @version $Id$
*
* @ingroup ModulesFolder
*/
class ilFolderXmlParser extends ilContainerBaseXmlParser
{
    /**
     * @var ilErrorHandling
     */
    protected $error;

    private $folder = null;
    

    /**
     * Constructor
     */
    public function __construct($folder, $xml)
    {
        global $DIC;

        $this->error = $DIC["ilErr"];
        parent::__construct();
        $this->setXMLContent($xml);
        $this->setFolder($folder);
        $this->setThrowException(true);
    }
    
    /**
     * set weblink
     * @param ilObject $webl
     * @return
     */
    public function setFolder(ilObject $folder)
    {
        $this->folder = $folder;
    }
    
    /**
     * Get folder object
     * @return ilObject
     */
    public function getFolder()
    {
        return $this->folder;
    }
    
    
    /**
     *
     * @return
     * @throws	ilSaxParserException	if invalid xml structure is given
     * @throws	ilWebLinkXMLParserException	missing elements
     */
    
    public function start()
    {
        return $this->startParsing();
    }
    
    /**
    * set event handlers
    *
    * @param	resource	reference to the xml parser
    * @access	private
    */
    public function setHandlers($a_xml_parser)
    {
        xml_set_object($a_xml_parser, $this);
        xml_set_element_handler($a_xml_parser, 'handlerBeginTag', 'handlerEndTag');
        xml_set_character_data_handler($a_xml_parser, 'handlerCharacterData');
    }
    
    /**
    * handler for begin of element
    *
    * @param	resource	$a_xml_parser		xml parser
    * @param	string		$a_name				element name
    * @param	array		$a_attribs			element attributes array
    */
    public function handlerBeginTag($a_xml_parser, $a_name, $a_attribs)
    {
        $ilErr = $this->error;

        switch ($a_name) {

            case 'Folder':
                break;


            case 'Sorting':
            case 'Sort':
                include_once './Services/Container/classes/class.ilContainerSortingSettings.php';
                ilContainerSortingSettings::_importContainerSortingSettings($a_attribs, $this->getFolder()->getId());
                break;
                
            case 'Title':
            case 'Description':
                break;
        }
    }
    
    /**
    * handler for end of element
    *
    * @param	resource	$a_xml_parser		xml parser
    * @param	string		$a_name				element name
    * @throws	ilSaxParserException	if invalid xml structure is given
    * @throws	ilWebLinkXMLParserException	missing elements
    */
    public function handlerEndTag($a_xml_parser, $a_name)
    {
        $GLOBALS['ilLog']->write(__METHOD__ . ': Called ' . $a_name);

        switch ($a_name) {
                
            case 'Folder':
                $this->getFolder()->update();
                break;
                
            case 'Title':
                $this->getFolder()->setTitle($this->trimAndStrip((string) $this->cdata));
                break;
                
            case 'Description':
                $this->getFolder()->setDescription($this->trimAndStrip((string) $this->cdata));
                break;
                
        }
        
        // Reset cdata
        $this->cdata = '';
    }
    

    
    /**
    * handler for character data
    *
    * @param	resource	$a_xml_parser		xml parser
    * @param	string		$a_data				character data
    */
    public function handlerCharacterData($a_xml_parser, $a_data)
    {
        if ($a_data != "\n") {
            // Replace multiple tabs with one space
            $a_data = preg_replace("/\t+/", " ", $a_data);
            $this->cdata .= $a_data;
        }
    }
}
