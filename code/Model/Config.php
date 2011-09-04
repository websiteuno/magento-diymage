<?php
// {{license}}
class Meanbee_Diy_Model_Config {
    public function isEnabled() {
        return Mage::getStoreConfig('diy/general/enabled');
    }
    
    public function isLoggingEnabled() {
        return $this->isEnabled() && Mage::getStoreConfig('diy/general/log_enabled');
    }
    
    public function isDeveloperMode() {
        return $this->isEnabled() && Mage::getStoreConfig('diy/general/developer_enabled');
    }
    
    public function getLicenseKey() {
        return Mage::getStoreConfig('diy/license/key');
    }
    
    public function getLicenseEmail() {
        return Mage::getStoreConfig('diy/license/email');
    }
    
    public function hasCompletedLicenseFields() {
        return $this->getLicenseKey() && $this->getLicenseEmail();
    }
    
    public function getPingUrl() {
        return "http://ping.diymage.com/check";
    }
    
    public function getLogName() {
        return "diymage.log";
    }
}