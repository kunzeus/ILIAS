<?php
/*
    +-----------------------------------------------------------------------------+
    | ILIAS open source                                                           |
    +-----------------------------------------------------------------------------+
    | Copyright (c) 1998-2001 ILIAS open source, University of Cologne            |
    |                                                                             |
    | This program is free software; you can redistribute it and/or               |
    | modify it under the terms of the GNU General Public License                 |
    | as published by the Free Software Foundation; either version 2              |
    | of the License, or (at your option) any later version.                      |
    |                                                                             |
    | This program is distributed in the hope that it will be useful,             |
    | but WITHOUT ANY WARRANTY; without even the implied warranty of              |
    | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the               |
    | GNU General Public License for more details.                                |
    |                                                                             |
    | You should have received a copy of the GNU General Public License           |
    | along with this program; if not, write to the Free Software                 |
    | Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA. |
    +-----------------------------------------------------------------------------+
*/

require_once("./Services/Xml/classes/class.ilSaxParser.php");
require_once('./Services/User/classes/class.ilObjUser.php');
include_once('./Services/Calendar/classes/class.ilDateTime.php');


/**
 * Group Import Parser
 *
 * @author Stefan Meyer <meyer@leifos.com>
 * @version $Id$
 *
 * @extends ilSaxParser

 */
class ilContainerReferenceXmlParser extends ilContainerBaseXmlParser
{
    /**
     * @var ilErrorHandling
     */
    protected $error;

    const MODE_CREATE = 1;
    const MODE_UPDATE = 2;
    
    private $ref = null;
    private $parent_id = 0;

    /**
     * @var ilLogger
     */
    protected $logger;

    /**
     * @var ilImportMapping
     */
    protected $import_mapping;
    
    /**
     * Constructor
     *
     * @param	string		$a_xml_file		xml file
     *
     * @access	public
     */

    public function __construct($a_xml, $a_parent_id = 0)
    {
        global $DIC;

        $this->error = $DIC["ilErr"];
        parent::__construct(null);

        $this->mode = ilContainerReferenceXmlParser::MODE_CREATE;
        $this->setXMLContent($a_xml);

        $this->logger = $DIC->logger()->exp();
    }

    /**
     * @param ilImportMapping $mapping
     */
    public function setImportMapping(ilImportMapping $mapping)
    {
        $this->import_mapping = $mapping;
    }
    
    /**
     * Get parent id
     * @return type
     */
    public function getParentId()
    {
        return $this->parent_id;
    }
    
    /**
     * set event handler
     * should be overwritten by inherited class
     * @access	private
     */
    public function setHandlers($a_xml_parser)
    {
        xml_set_object($a_xml_parser, $this);
        xml_set_element_handler($a_xml_parser, 'handlerBeginTag', 'handlerEndTag');
        xml_set_character_data_handler($a_xml_parser, 'handlerCharacterData');
    }

    /**
     * start the parser
     */
    public function startParsing()
    {
        parent::startParsing();
        
        if ($this->ref instanceof ilContainerReference) {
            return $this->ref;
        }
        return 0;
    }


    /**
     * handler for begin of element
     */
    public function handlerBeginTag($a_xml_parser, $a_name, $a_attribs)
    {
        $a_attribs = $this->trimAndStripAttribs($a_attribs);
        $ilErr = $this->error;

        switch ($a_name) {
            case "ContainerReference":
                break;
            
            case 'Title':
                switch ($a_attribs['type']) {
                    case ilContainerReference::TITLE_TYPE_REUSE:
                        $this->getReference()->setTitleType(ilContainerReference::TITLE_TYPE_REUSE);
                        break;

                    default:
                        $this->getReference()->setTitleType(ilContainerReference::TITLE_TYPE_REUSE);
                        break;
                }
                break;
            
            case 'Target':
                $target_id = $this->parseTargetId(isset($a_attribs['id']) ? (string) $a_attribs['id'] : '');
                if ($target_id) {
                    $this->logger->debug('Using mapped target_id: ' . $target_id);
                    $this->getReference()->setTargetId($target_id);
                } else {
                    $this->logger->info('No mapping found for: ' . $a_attribs['id']);
                    $this->getReference()->setTargetId(0);
                }
                break;
        }
    }

    /**
     * @param string $attribute_target
     * @return int
     */
    protected function parseTargetId(string $attribute_target) : int
    {
        if (!strlen($attribute_target)) {
            $this->logger->debug('No target id provided');
            return 0;
        }
        if (!$this->import_mapping instanceof ilImportMapping) {
            return 0;
        }
        $obj_mapping_id = $this->import_mapping->getMapping('Services/Container', 'objs', $attribute_target);
        if (!$obj_mapping_id) {
            $this->logger->debug('Cannot find object mapping for target_id: ' . $attribute_target);
            return 0;
        }
        return $obj_mapping_id;
    }


    /**
     * Handler end tag
     * @param type $a_xml_parser
     * @param type $a_name
     */
    public function handlerEndTag($a_xml_parser, $a_name)
    {
        $this->cdata = $this->trimAndStrip((string) $this->cdata);
        switch ($a_name) {
            case "ContainerReference":
                $this->save();
                break;
            
            case 'Title':
                if ($this->getReference()->getTitleType() == ilContainerReference::TITLE_TYPE_CUSTOM) {
                    $this->getReference()->setTitle(trim($this->cdata));
                }
                break;
        }
        $this->cdata = '';
    }


    /**
     * handler for character data
     */
    public function handlerCharacterData($a_xml_parser, $a_data)
    {
        #$a_data = str_replace("<","&lt;",$a_data);
        #$a_data = str_replace(">","&gt;",$a_data);

        if (!empty($a_data)) {
            $this->cdata .= $a_data;
        }
    }

    /**
     * Save category object
     * @return type
     */
    protected function save()
    {
        /**
         * mode can be create or update
         */
        include_once './Modules/Category/classes/class.ilCategoryXmlParser.php';
        if ($this->mode == ilCategoryXmlParser::MODE_CREATE) {
            $this->create();
            $this->getReference()->create();
            $this->getReference()->createReference();
            $this->getReference()->putInTree($this->getParentId());
            $this->getReference()->setPermissions($this->getParentId());
        }
        $this->getReference()->update();
        return true;
    }




    /**
     * Set import mode
     * @param type $mode
     */
    public function setMode($mode)
    {
        $this->mode = $mode;
    }

    
    /**
     * Set container reference
     * @param ilContainerReference $ref
     */
    public function setReference(ilContainerReference $ref)
    {
        $this->ref = $ref;
    }
    
    /**
     * Get container reference
     * @return ilContainerReference
     */
    public function getReference()
    {
        return $this->ref;
    }
}
