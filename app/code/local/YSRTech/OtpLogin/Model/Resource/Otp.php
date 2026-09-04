<?php
/**
 * YSRTech OtpLogin - OTP resource model.
 *
 * @category  YSRTech
 * @package   YSRTech_OtpLogin
 */
class YSRTech_OtpLogin_Model_Resource_Otp extends Mage_Core_Model_Resource_Db_Abstract
{
    protected function _construct()
    {
        $this->_init('ysrtech_otplogin/otp', 'entity_id');
    }
}
