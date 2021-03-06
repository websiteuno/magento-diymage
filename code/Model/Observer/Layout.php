<?php
// {{license}}
class Meanbee_Diy_Model_Observer_Layout implements Meanbee_Diy_Model_Observer_Interface {
    protected $_removedBlocks = array();
    protected $_log;
    
    public function __construct() {
        $this->_log = Mage::getModel('diy/log');
    }
    
    /**
     * @TODO: Add event listeners for adding custom stylesheets
     *
     * @param Varien_Event_Observer $observer 
     * @return void
     * @author Nicholas Jones
     */
    public function observe(Varien_Event_Observer $observer) {
        
        if (!Mage::getSingleton('diy/config')->isEnabled()) {
            return;
        }
        
        $action_obj = $observer->getAction();
        $layout = $observer->getLayout();
        $update = $layout->getUpdate();
        $request = $action_obj->getRequest();
        
        $module = $request->getModuleName();
        $action = $request->getActionName();
        $controller = $request->getControllerName();
        
        $design = Mage::getDesign();
        $area = $design->getArea();
        
        if ($area == "adminhtml") {
            $this->_checkLicenseValid();
            return;
        }
        
        $full_identifier = "{$module}_{$controller}_{$action}";
        
        $this->_log->debug("--- Observing layout for $full_identifier");
        
        $identifiers = array(
            "{$module}",
            "{$module}_{$controller}",
            $full_identifier
        );
        
        $store = $this->_getStoreId();
        
        $this->_addStaticBlocks($identifiers, $layout);
        $this->_sortBlocks($identifiers, $layout);
        $this->_removeBlocks($identifiers, $layout);
        $this->_modifyPageLayout($identifiers, $layout);
        $this->_otherDIYSettings($store, $identifiers, $layout);
        
        if (Mage::getDesign()->getPackageName() == Meanbee_Diy_Helper_Data::DESIGN_PACKAGE_NAME) {
            $this->_addStylesheet($layout, "diymage_" . $store . ".css?" . time());
        }
        
        // Apply our sort changes..
        //$update->load();
    }
    
    protected function _getStoreId() {
        return Mage::app()->getStore()->getStoreId();
    }
    
    /**
     * Search through the $identifiers to find the layout we should apply.
     *
     * @param array $identifiers 
     * @param Mage_Core_Model_Layout $layout 
     * @return void
     * @author Nicholas Jones
     */
    protected function _modifyPageLayout($identifiers, $layout) {
        if ($this->_isCMSPage($identifiers)) {
            $this->_log->debug("Layout not switched because we're on a CMS page");
            return;
        }
        
        // This will become true if we match something in the next set of conditionals
         $layout_file = false;

         // Attempt to load the desired layout, working increasing precision with each step
         foreach ($identifiers as $identifier) {
             $update = Mage::helper('diy')->getValue($identifier, "layout");

             if ($update !== null) {
                 $layout_file = $update;
             }
         }

         if ($layout_file) {
             $this->_setTemplate($layout, $layout_file);
         }
    }
    
    /**
     * Find the blocks that we need to remove form the layout.
     *
     * @param array $identifiers 
     * @param Mage_Core_Model_Layout $layout
     * @return void
     * @author Nicholas Jones
     */
    protected function _removeBlocks($identifiers, $layout) {
        $to_remove = array();
        
        foreach ($identifiers as $identifier) {
            $update = $this->_getUpdateXML($identifier);
            
            if (count($update) > 0) {
                foreach ($update as $group => $data) {
                    $remove = $data['remove'];
                    
                    if ($remove == null) {
                        $remove = array();
                    }
                    
                    $to_remove = array_merge($to_remove, $remove);
                }
            }
        }
        
        if (count($to_remove) > 0) {
            $to_remove = array_unique($to_remove);
            
            $this->_removedBlocks = $to_remove;
            
            foreach ($to_remove as $block_name) {
                array_push($this->_removedBlocks, $block_name);
                $this->_removeBlock($layout, $block_name);
            }
        }
    }
    
    /**
     * Sort the blocks in the references.
     *
     * @param string $identifiers 
     * @param string $layout 
     * @return void
     * @author Nicholas Jones
     */
    protected function _sortBlocks($identifiers, $layout) {
        $this->_log->debug("Sorting blocks")->indent();
        $simple_xml = $layout->getUpdate()->asSimpleXml();
        
        foreach ($identifiers as $identifier) {
            $this->_log->debug("Searching for identifier $identifier")->indent();
            
            $update_xml = $this->_getUpdateXML($identifier);
            
            if (count($update_xml) > 0) {
                foreach ($update_xml as $group => $data) {
                    $this->_log->debug("Searching for group $group")->indent();
                    $blocks = $data['sort_order'];

                    $sorted_blocks = array();

                    foreach ($blocks as $key => $block_data) {
                        $block_name = $block_data['name'];
                        $previous_block_name = $block_data['after'];

                        $previous_idx = array_search($previous_block_name, $sorted_blocks);
                        if (false === $previous_idx) {
                            $sorted_blocks[] = $block_name;
                        } else {
                            array_splice($sorted_blocks, $previous_idx + 1, 0, $block_name);
                        }
                    }

                    $sorted_blocks_str = join(',', $sorted_blocks);
                    $xml = "
                        <reference name='$group'>
                            <action method='setBlockOrder'><order>$sorted_blocks_str</order></action>
                        </reference>
                    ";

                    $layout->getUpdate()->addUpdate($xml);
                }
            }
        }
    } // function
    
    /**
     * Utility method to generate the XML to set the template
     *
     * @param Mage_Core_Model_Layout $layout 
     * @param string $template 
     * @return void
     * @author Nicholas Jones, Tom Robertshaw
     */
    protected function _setTemplate($layout, $template) {
        $this->_log->debug("Setting template to $template");
        $layout->getUpdate()->addUpdate(
            '<reference name="root">
                <action method="setTemplate"><template>' . $template . '</template></action>
            </reference>'
        );
        
        // Also need to update page_ related handles as well as template.
        
        // Remove all page_ related handles
        foreach (Mage::getSingleton('page/config')->getPageLayoutHandles() as $h) {
            $layout->getUpdate()->removeHandle($h);
        }
        
        // Identify correct page_ handle:
        $layouts = Mage::getSingleton('page/config')->getPageLayouts();
        foreach ($layouts as $l) {
            if ($l->getTemplate() == $template) {
                $handle = $l->getLayoutHandle();
                break;
            }
        }
        
        // Add correct one
        $layout->getUpdate()->addHandle($handle);
    }
    
    /**
     * Utility method to generate the XML to add a stylesheet
     *
     * @param Mage_Core_Model_Layout $layout 
     * @param string $stylesheet 
     * @return void
     * @author Nicholas Jones
     */
    protected function _addStylesheet($layout, $stylesheet) {
        $this->_log->debug("Adding stylesheet $stylesheet");
        $layout->getUpdate()->addUpdate(
            '<reference name="head">
                <action method="addItem"><type>skin_css</type><name>' . $stylesheet . '</name><params/><if /></action>
            </reference>'
        );
    }
    
    /**
     * Utility function to generate the XML to remove blocks
     *
     * @param Mage_Core_Model_Layout $layout 
     * @param string $name 
     * @return void
     * @author Nicholas Jones
     */
    protected function _removeBlock($layout, $name) {
        $this->_log->debug("Removing block $name");
        $layout->getUpdate()->addUpdate(
            '<remove name="' . $name . '" />'
        );
    }
    
    /**
     * Utility function to generate the XML to add a block
     *
     * @param string $layout 
     * @param string $reference
     * @param string $name 
     * @param string $type 
     * @param string $after 
     * @param string $before 
     * @param string $template 
     * @param string $as 
     * @return void
     * @author Nicholas Jones
     */
    protected function _addBlock($layout, $reference, $name, $type, $after = null, $before = null, $template = null, $as = null) {
        $this->_log->debug("Adding block $name of type $type to reference $reference");
        $arguments = array(
            "after", "before", "template", "as"
        );
        
        $attributes = array(
            "name" => $name,
            "type" => $type
        );
        
        foreach ($arguments as $argument) {
            if ($$argument) {
                $attributes[$argument] = $$argument;
            }
        }
        
        $xml = "<block ";
        
        foreach ($attributes as $key => $value) {
            $xml .= $key . "='". $value."' ";
        }
        
        $xml .= "/>";
        
        $xml = "<reference name='" . $reference . "'>" . $xml . "</reference>";
        
        $layout->getUpdate()->addUpdate($xml);
    }
    
    /**
     * Action other DIY settings that involve layout updates (non-builder)
     * 
     * @param string $identifiers
     * @param string $layout
     * @return void
     * @author Tom Robertshaw
     **/
     protected function _otherDIYSettings($store, $identifiers, $layout) {
         // Assign helper to variable to save time
         $diy = Mage::helper('diy');
         
         /*
          * Global
          */
         
         // Should the header mini cart be shown
         if (!$diy->getValue('global', 'header_show_minicart', $store)) {
             $this->_removeBlock($layout, 'minicart');
         }
         
         // Should the top category navigation be shown
         if (!$diy->getValue("global", "show_categories", $store )) {
             $this->_removeBlock($layout, "catalog.topnav");
         }
         
         // Should the footer promo block be shown
         if (!$diy->getValue('global', 'show_footer_promo', $store )) {
             $this->_removeBlock($layout, "footer.promo");
         }
         
         
         /*
          * Product Page 
          */
         
         // Hide product reviews
         if (!$diy->getValue("catalog_product_view", "show_reviews", $store)) {
             $this->_removeBlock($layout, "product.info.product_additional_data");
         }
         
         // Hide cross-sell products
         if (!$diy->getValue("catalog_product_view", "show_upsells", $store)) {
             $this->_removeBlock($layout, "product.info.upsell");
         }
         
         // Hide product tags
         if (!$diy->getValue("catalog_product_view", "show_tags", $store )) {
             $this->_removeBlock($layout, "product_tag_list");
         }
         
         // Hide additional data
         if (!$diy->getValue("catalog_product_view", "show_attributes", $store)) {
             $this->_removeBlock($layout, "product.attributes");
         }
         
         /*
          * Cart
          */
          
         if (!$diy->getValue("checkout_cart_index", "show_crosssell", $store)) {
             $this->_removeBlock($layout, "checkout.cart.crosssell");
         }
         
         if (!$diy->getValue("checkout_cart_index", "show_shipping", $store)) {
             $this->_removeBlock($layout, "checkout.cart.shipping");
         }
         
         if (!$diy->getValue("checkout_cart_index", "show_coupon", $store)) {
             $this->_removeBlock($layout, "checkout.cart.coupon");
         }

     }
    
    /**
     * Add a static block and position it
     *
     * @param string $layout 
     * @param string $block_id The id of the static block in the database
     * @param string $block_name The name we need to use in the before/after statements of other layout references
     * @return void
     * @author Nicholas Jones
     */
    protected function _addStaticBlock($layout, $reference, $block_id, $block_name, $after = null, $before = null) {
        $this->_log->debug("Adding static block $block_id as $block_name");
        $xml = "<reference name='$reference'>";
            $xml .= '<block type="cms/block" name="' . $block_name . '"';
            
            if ($after) {
                $xml .= " after='$after' ";
            }
            
            if ($before) {
                $xml .= " before='$before' ";
            }
            
            $xml .= '>';
                $xml .= '<action method="setBlockId"><block_id>' . $block_id . '</block_id></action>';
            $xml .= '</block>';
        $xml .= '</reference>';
        
        $layout->getUpdate()->addUpdate($xml);
    }
    
    protected $_compiledEarlyLayout = null;
    protected function _identifyBlockType($identifiers, $layout, $name) {
        if ($this->_compiledEarlyLayout == null) {
            $this->_compiledEarlyLayout = $layout->getUpdate()->load($identifiers)->asSimpleXml();
        }
        
        $xpath = "reference/block[@name='{$name}']";
        
        $result = $this->_compiledEarlyLayout->xpath($xpath);
        
        if (count($result) > 0) {
            return $result[0];
        }
    }
    
    public function _addStaticBlocks($identifiers, $layout) {
        foreach ($identifiers as $identifier) {
            $update = $this->_getUpdateXML($identifier);

            if (count($update) > 0) {
                foreach ($update as $group => $data) {
                    $blocks = $data['sort_order'];
                    
                    foreach ($blocks as $block) {
                        if (isset($block['static']) && $block['static']) {
                            $static = (isset($block['static'])) ? $block['static'] : false;
                            $name   = (isset($block['name']))   ? $block['name']   : false;
                            $after  = (isset($block['after']))  ? $block['after']  : false;
                            $before = (isset($block['before'])) ? $block['before'] : false;

                            $this->_addStaticBlock($layout, $group, $static, $name, $after, $before);
                        }
                    }
                }
            }
        }
    }
    
    // http://www.imarc.net/communique/148-xml_pretty_printer_in_php5
    private function __xmlpp($xml, $html_output=false) {
        $xml_obj = new SimpleXMLElement($xml);
        $level = 4;
        $indent = 0; // current indentation level
        $pretty = array();

        // get an array containing each XML element
        $xml = explode("\n", preg_replace('/>\s*</', ">\n<", $xml_obj->asXML()));

        // shift off opening XML tag if present
        if (count($xml) && preg_match('/^<\?\s*xml/', $xml[0])) {
          $pretty[] = array_shift($xml);
        }

        foreach ($xml as $el) {
          if (preg_match('/^<([\w])+[^>\/]*>$/U', $el)) {
              // opening tag, increase indent
              $pretty[] = str_repeat(' ', $indent) . $el;
              $indent += $level;
          } else {
            if (preg_match('/^<\/.+>$/', $el)) {            
              $indent -= $level;  // closing tag, decrease indent
            }
            if ($indent < 0) {
              $indent += $level;
            }
            $pretty[] = str_repeat(' ', $indent) . $el;
          }
        }   
        $xml = implode("\n", $pretty);   
        return ($html_output) ? htmlentities($xml) : $xml;
    }
    
    /**
     * Contact the license server to determine whether a license is valid or not.
     *
     * @return bool
     * @author Nicholas Jones
     */
    protected function _checkLicenseValid() {
        $cache = Mage::getSingleton('diy/cache');
        $config = Mage::getSingleton('diy/config');
        $client = new Varien_Http_Client($config->getPingUrl());
        
        // An indication of wether all fields were complete, or not.
        $incomplete = false;
        
        if ($cache->load(Meanbee_Diy_Model_Cache::KEY_LSTATUS)) {
            $this->_log->debug("License valid, cache hit");
            return true;
        }
        
        if (!$config->hasCompletedLicenseFields()) {
            Mage::getSingleton('adminhtml/session')->addNotice(
                Mage::helper('diy')->__('You need to enter the email address you used to purchase DIY Mage in the <a href="' . Mage::helper("adminhtml")->getUrl('adminhtml/system_config/edit/', array('section' => 'diy')) . '">configuration section</a>.')
            );
            
            $this->_log->warn("License fields are not complete");
            $incomplete = true;
        }
        
        $post_data = array(
            "date"            => date("c"),
            "locale"          => Mage::getStoreConfig('general/locale/code'),
            "base_url"        => Mage::getStoreConfig('web/unsecure/base_url'),
            "email"           => $config->getLicenseEmail(),
            "magento_version" => Mage::getVersion(),
            "diymage_version" => $config->getVersion()
        );
        
        $client->setParameterPost('payload', $post_data);
        
        $this->_log->debug("Contacting server for license status");
        try {
            $response = $client->request(Zend_Http_Client::POST);

            if ($response->isSuccessful()) {
                if ($response->getHeader('Content-type') == "application/json") {
                    $result = json_decode($response->getBody(), true); // Convert to assoc array 

                    if ($result['valid']) {
                        $this->_log->debug("License is valid, confirmed by server");
                        $cache->save(Meanbee_Diy_Model_Cache::KEY_LSTATUS, true, 60*60*24*7);
                        return true;
                    } else {
                        // Only display the error to the customer if we know that all fields were complete
                        if (!$incomplete) {
                            Mage::getSingleton('adminhtml/session')->addError(
                                Mage::helper('diy')->__('Your license settings for DIY Mage are currently not valid.  Please contact support@diymage.com as soon as possible to resolve this issue.')
                            );

                            $this->_log->warn("Using an invalid license");
                        }
                    }
                } else {
                    $this->_log->critical("Incorrect content-type from the license server");
                }
            } else {
                throw new Exception();
            }
        } catch (Exception $e) {
            $this->_log->alert("Unable to contact license server");
        }
        
        return false;
    }
    
    protected function _getUpdateXML($ident) {
        if ($this->_isCMSPage($ident)) {
            $page_id = Mage::app()->getRequest()->getParam('page_id');

            if (!$page_id) {
                $page_key = Mage::getStoreConfig('web/default/cms_home_page');

                /**
                 * The $page_key could have the format: %s|%d, when %s is the name of the page identifier, and the %d is
                 * the actual page id.
                 */
                $page_key_delimiter_position = strrpos($page_key, '|');

                if ($page_key_delimiter_position) {
                    $page_id = substr($page_key, $page_key_delimiter_position + 1);
                    $page = Mage::getModel('cms/page')->load($page_id);
                } else {
                    $page = Mage::getModel('cms/page')->setStoreId(Mage::app()->getStore()->getId())->load($page_key);
                }
            } else {
                $page = Mage::getModel('cms/page')->load($page_id);
            }

            $this->_log->debug("Observing a CMS page (#" . $page->getId() . ")");
            
            $update_xml = $page->getData('diy_builder');
        } else {
            $update_xml = Mage::helper('diy')->getValue($ident, "builder", $this->_getStoreId());
        }
        
        return Zend_Json::decode($update_xml);
    }
    
    protected function _isCMSPage($ident) {
        if (!is_array($ident)) {
            $ident = array($ident);
        }
        
        return in_array("cms", $ident);
    }
}
