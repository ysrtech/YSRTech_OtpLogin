<?php
/**
 * YSRTech OtpLogin - OTP collection.
 *
 * @category  YSRTech
 * @package   YSRTech_OtpLogin
 */
class YSRTech_OtpLogin_Model_Resource_Otp_Collection extends Mage_Core_Model_Resource_Db_Collection_Abstract
{
    protected function _construct()
    {
        $this->_init('ysrtech_otplogin/otp');
    }
}
