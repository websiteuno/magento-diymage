<?php
class Meanbee_Diy_Block_Admin_Control_Builder extends Meanbee_Diy_Block_Admin_Control_Abstract {
    public function __construct() {
        parent::__construct();
        $this->setTemplate('diy/controls/builder.phtml');
    }
    
    /**
     * Get the child blocks from the layout references in the XML.  Also, sort them in the array
     * by the sort order that's user defined.
     *
     * @param string $name 
     * @return string (json)
     * @author Nicholas Jones
     */
    public function getLayoutReferenceJson($name) {
        $template = Mage::registry('diy_current_template');
        $layout = Mage::getModel('diy/layout')->addHandle($template);
        
        // @TODO: Split the template on _, and add all of the handle varients.
        $template_parts = explode("_", $template);
        
        if ($template_parts > 1) {
            $layout->addHandle($template_parts[0]);
            $layout->addHandle($template_parts[0] . "_" . $template_parts[1]);
            $layout->addHandle($template_parts[0] . "_" . $template_parts[1] . "_default");
        }
        
        $reference = $layout->getReference($name);
        $data = $this->_getValueAsArray(); 
        $sort_order = $data[$name]['sort_order'];
        
        $return = array();
        $reference_index_map = array();
        
        // Provide a quick way for referencing the references..
        if (count($reference) > 0) {
            foreach ($reference as $idx => $ref_data) {
                $reference_index_map[$ref_data['name']] = $idx;
            }
        }
        
        // Arrange the references by the sort order in an array to return
        if (count($sort_order) > 0) {
            foreach ($sort_order as $idx => $sort_data) {
                $name = $sort_data['name'];
                
                // Add in any static blocks we find
                if ($sort_data['static']) {
                    $return[] = $sort_data;
                    continue;
                }
                
                if ($reference_index_map[$name] !== null) {
                    $return[] = $reference[$reference_index_map[$name]];

                    // Remove the reference, so we can check if we've got any left at the
                    // end.
                    unset($reference[$reference_index_map[$name]]);
                }
            }
        }
        
        // If we didn't build anything above, then just return the default structure 
        if (count($return) == 0) {
            $return = $reference;
        } else if (count($reference) > 0) {
            // If we didn't use everything that we pulled from the layout.xml, then just tack them on
            // the end.  Deleted blocks will appear here too, as they won't appear in the sort_order.
            foreach ($reference as $idx => $ref_data) {
                $return[] = $ref_data;
            }
        }
        
        return Zend_Json::encode($return);
    }
    
    /**
     * Create the JSON properly for outputting
     *
     * @return string (json)
     * @author Nicholas Jones
     */
    public function getValue() {
        return Zend_Json::encode($this->_getValueAsArray());
    }
    
    /**
     * @return array
     * @author Nicholas Jones
     */
    protected function _getValueAsArray() {
        $keys = array('remove', 'sort_order');
        
        $value_json = parent::getValue();
        $value = Zend_Json::decode($value_json);
        
        foreach ($value as $group => $data) {
            foreach ($keys as $key) {
                if ($value[$group][$key] == "[]") {
                    $value[$group][$key] = array();
                } else {
                    $value[$group][$key] = Zend_Json::decode($value[$group][$key]);
                }
            }
        }
        
        return $value;
    }
    
    /**
     * Get a map of block names to nice names, in json form
     *
     * @TODO: Move this out to XML file
     *
     * @return string (json)
     * @author Nicholas Jones
     */
    public function getBlocksNiceNameMap() {
        return Zend_Json::encode(array(
            "right.poll"                => "Poll",
            "left.newsletter"           => "Newsletter Signup",
            "currency"                  => "Currency Select",
            "tags_popular"              => "Popular Tags",
            "catalog.leftnav"           => "Category Navigation",
            "cart_sidebar"              => "Mini Cart",
            "catalog.compare.sidebar"   => "Compare Products",
            "paypal.partner.right.logo" => "Paypal Logo",
            "wishlist_sidebar"          => "Wishlist"
        ));
    }
    
    /**
     * Return a json array of the active static blocks
     *
     * @return string (json)
     * @author Nicholas Jones
     */
    public function getStaticBlocks() {
        $collection = Mage::getModel('cms/block')->getCollection();
        $blocks = array();
        
        foreach ($collection as $block) {
            if ($block->getIsActive()) {
                $blocks[$block->getIdentifier()] = $block->getTitle();
            }
        }
        
        return Zend_Json::encode($blocks);
    }
}