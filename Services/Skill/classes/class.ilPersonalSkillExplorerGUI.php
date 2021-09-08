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
 ********************************************************************
 */

/**
 * Explorer for selecting a personal skill
 *
 * @author	Alex Killing <alex.killing@gmx.de>
 */
class ilPersonalSkillExplorerGUI extends ilTreeExplorerGUI
{
    /**
     * @var ilCtrl
     */
    protected $ctrl;

    /**
     * @var ilLanguage
     */
    protected $lng;

    /**
     * @var object|string
     */
    protected $select_gui;
    protected string $select_cmd;
    protected string $select_par;
    protected array $all_nodes;
    protected array $node;
    protected array $child_nodes;
    protected array $parent;

    protected $selectable = [];
    protected $selectable_child_nodes = [];
    protected $has_selectable_nodes = false;

    public function __construct(
        $a_parent_obj,
        string $a_parent_cmd,
        $a_select_gui,
        string $a_select_cmd,
        string $a_select_par = "obj_id")
    {
        global $DIC;

        $this->ctrl = $DIC->ctrl();
        $this->lng = $DIC->language();
        
        $this->select_gui = (is_object($a_select_gui))
            ? strtolower(get_class($a_select_gui))
            : $a_select_gui;
        $this->select_cmd = $a_select_cmd;
        $this->select_par = $a_select_par;


        $this->lng->loadLanguageModule("skmg");
        
        $this->tree = new ilSkillTree();
        $this->root_id = $this->tree->readRootId();
        
        parent::__construct("pskill_sel", $a_parent_obj, $a_parent_cmd, $this->tree);
        $this->setSkipRootNode(true);
        
        $this->all_nodes = $this->tree->getSubTree($this->tree->getNodeData($this->root_id));
        foreach ($this->all_nodes as $n) {
            $this->node[$n["child"]] = $n;
            $this->child_nodes[$n["parent"]][] = $n;
            $this->parent[$n["child"]] = $n["parent"];
        }

        
        //		$this->setTypeWhiteList(array("skrt", "skll", "scat", "sktr"));
        $this->buildSelectableTree($this->tree->readRootId());
    }

    protected function setHasSelectableNodes(bool $a_val) : void
    {
        $this->has_selectable_nodes = $a_val;
    }

    public function getHasSelectableNodes() : bool
    {
        return $this->has_selectable_nodes;
    }

    public function buildSelectableTree(int $a_node_id) : void
    {
        if (in_array(ilSkillTreeNode::_lookupStatus($a_node_id), array(ilSkillTreeNode::STATUS_DRAFT, ilSkillTreeNode::STATUS_OUTDATED))) {
            return;
        }

        if (ilSkillTreeNode::_lookupSelfEvaluation($a_node_id)) {
            $this->selectable[$a_node_id] = true;
            $cid = $a_node_id;
            //$this->selectable[$this->parent[$a_node_id]] = true;
            while (isset($this->parent[$cid])) {
                $this->selectable[$this->parent[$cid]] = true;
                $cid = $this->parent[$cid];
            }
        }
        foreach ($this->getOriginalChildsOfNode($a_node_id) as $n) {
            $this->buildSelectableTree($n["child"]);
        }
        if ($this->selectable[$a_node_id]) {
            $this->setHasSelectableNodes(true);
            $this->selectable_child_nodes[$this->node[$a_node_id]["parent"]][] =
                $this->node[$a_node_id];
        }
    }

    /**
     * Get childs of node (selectable tree)
     *
     * @param int $a_parent_node_id parent id
     * @return array childs
     */
    public function getChildsOfNode($a_parent_node_id) : array
    {
        if (is_array($this->selectable_child_nodes[$a_parent_node_id])) {
            $childs = $this->selectable_child_nodes[$a_parent_node_id];
            $childs = ilUtil::sortArray($childs, "order_nr", "asc", true);
            return $childs;
        }
        return [];
    }

    /**
     * Get original childs of node (whole tree)
     */
    public function getOriginalChildsOfNode(int $a_parent_id) : array
    {
        if (is_array($this->child_nodes[$a_parent_id])) {
            return $this->child_nodes[$a_parent_id];
        }
        return [];
    }

    /**
     * @param object|array $a_node
     * @return string
     */
    public function getNodeHref($a_node) : string
    {
        $ilCtrl = $this->ctrl;
        
        $skill_id = $a_node["child"];
        
        $ilCtrl->setParameterByClass($this->select_gui, $this->select_par, $skill_id);
        $ret = $ilCtrl->getLinkTargetByClass($this->select_gui, $this->select_cmd);
        $ilCtrl->setParameterByClass($this->select_gui, $this->select_par, "");
        
        return $ret;
    }

    /**
     * @param object|array $a_node
     * @return string
     */
    public function getNodeContent($a_node) : string
    {
        $lng = $this->lng;

        // title
        $title = $a_node["title"];

        return $title;
    }

    /**
     * @param object|array $a_node
     * @return bool
     */
    public function isNodeClickable($a_node) : bool
    {
        if (!ilSkillTreeNode::_lookupSelfEvaluation($a_node["child"])) {
            return false;
        }
        return true;
    }

    /**
     * get image path (may be overwritten by derived classes)
     *
     * @param object|array $a_node
     * @return string
     */
    public function getNodeIcon($a_node) : string
    {
        $t = $a_node["type"];
        if ($t == "sktr") {
            return ilUtil::getImagePath("icon_skll.svg");
        }
        return ilUtil::getImagePath("icon_" . $t . ".svg");
    }

    /**
     * @param object|array $a_node
     * @return string
     */
    public function getNodeIconAlt($a_node) : string
    {
        $lng = $this->lng;

        if ($lng->exists("skmg_" . $a_node["type"])) {
            return $lng->txt("skmg_" . $a_node["type"]);
        }

        return $lng->txt($a_node["type"]);
    }

}
