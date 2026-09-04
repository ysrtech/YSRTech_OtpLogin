<?php
/**
 * YSRTech OtpLogin - cron entry points.
 *
 * @category  YSRTech
 * @package   YSRTech_OtpLogin
 */
class YSRTech_OtpLogin_Model_Observer
{
    /**
     * Clear out spent and stale OTP rows.
     *
     * Every sign-in attempt writes a row and nothing else ever removes one, so
     * without this the table grows for the life of the store.
     *
     * @return $this
     */
    public function cleanExpiredOtps()
    {
        Mage::helper('ysrtech_otplogin')->cleanExpiredOtps();
        return $this;
    }
}
