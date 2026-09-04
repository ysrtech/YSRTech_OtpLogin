<?php
/**
 * YSRTech OtpLogin - Data helper.
 *
 * Handles OTP generation, email delivery and OTP record bookkeeping.
 * The original SMS / Twilio delivery has been replaced with transactional
 * email delivery.
 *
 * @category  YSRTech
 * @package   YSRTech_OtpLogin
 */
class YSRTech_OtpLogin_Helper_Data extends Mage_Core_Helper_Abstract
{
    const XML_PATH_ENABLED            = 'ysrtech_otplogin/general/enabled';
    const XML_PATH_ALLOW_REGISTRATION = 'ysrtech_otplogin/general/allow_registration';
    const XML_PATH_OTP_TYPE           = 'ysrtech_otplogin/general/otp_type';
    const XML_PATH_OTP_LENGTH         = 'ysrtech_otplogin/general/otp_length';
    const XML_PATH_EXPIRE_TIME        = 'ysrtech_otplogin/general/expire_time';
    const XML_PATH_EMAIL_IDENTITY     = 'ysrtech_otplogin/email/identity';
    const XML_PATH_EMAIL_TEMPLATE     = 'ysrtech_otplogin/email/template';

    /**
     * @return bool
     */
    public function isEnabled($store = null)
    {
        return Mage::getStoreConfigFlag(self::XML_PATH_ENABLED, $store);
    }

    /**
     * @return bool
     */
    public function isRegistrationAllowed($store = null)
    {
        return Mage::getStoreConfigFlag(self::XML_PATH_ALLOW_REGISTRATION, $store);
    }

    /**
     * @return string
     */
    public function getOtpType($store = null)
    {
        return (string) Mage::getStoreConfig(self::XML_PATH_OTP_TYPE, $store);
    }

    /**
     * @return int
     */
    public function getOtpLength($store = null)
    {
        $length = (int) Mage::getStoreConfig(self::XML_PATH_OTP_LENGTH, $store);
        return $length > 0 ? $length : 6;
    }

    /**
     * Expiry window in seconds.
     *
     * @return int
     */
    public function getExpireTime($store = null)
    {
        $expire = (int) Mage::getStoreConfig(self::XML_PATH_EXPIRE_TIME, $store);
        return $expire > 0 ? $expire : 300;
    }

    /**
     * Generate a fresh OTP code according to the configured type and length.
     *
     * @return string
     */
    public function generateOtpCode($store = null)
    {
        $type   = $this->getOtpType($store);
        $length = $this->getOtpLength($store);

        switch ($type) {
            case 'alphabets':
                $pool = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
                break;
            case 'alphanumeric':
                $pool = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
                break;
            case 'number':
            default:
                $pool = '0123456789';
                break;
        }

        $max  = strlen($pool) - 1;
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= $pool[mt_rand(0, $max)];
        }

        return $code;
    }

    /**
     * Hash an OTP for storage / comparison. We never store the plain code.
     *
     * @param  string $code
     * @return string
     */
    public function hashOtp($code)
    {
        return hash('sha256', (string) $code);
    }

    /**
     * Send the OTP code to the given email address using a transactional email.
     *
     * @param  string $code
     * @param  string $email
     * @param  string $name
     * @return $this
     */
    public function sendOtpEmail($code, $email, $name = '')
    {
        $store      = Mage::app()->getStore();
        $storeId    = $store->getId();
        $templateId = Mage::getStoreConfig(self::XML_PATH_EMAIL_TEMPLATE, $storeId);
        $identity   = Mage::getStoreConfig(self::XML_PATH_EMAIL_IDENTITY, $storeId);

        if (!$name) {
            $name = $email;
        }

        $expireMinutes = ceil($this->getExpireTime($storeId) / 60);

        /** @var Mage_Core_Model_Email_Template $mailer */
        $mailer = Mage::getModel('core/email_template');
        $mailer->setDesignConfig(array('area' => 'frontend', 'store' => $storeId));
        $mailer->sendTransactional(
            $templateId,
            $identity,
            $email,
            $name,
            array(
                'otp'            => $code,
                'name'           => $name,
                'store'          => $store,
                'store_name'     => $store->getFrontendName(),
                'expire_minutes' => $expireMinutes,
            ),
            $storeId
        );

        return $this;
    }

    /**
     * Persist a new OTP for the given email.
     *
     * @param  string $code  Plain OTP code (will be hashed before saving)
     * @param  string $email
     * @param  string $name
     * @return YSRTech_OtpLogin_Model_Otp
     */
    public function saveOtpData($code, $email, $name = '')
    {
        /** @var YSRTech_OtpLogin_Model_Otp $otp */
        $otp = Mage::getModel('ysrtech_otplogin/otp');
        $otp->setEmail($email)
            ->setName($name)
            ->setOtp($this->hashOtp($code))
            ->setStatus(1)
            ->save();

        return $otp;
    }

    /**
     * Invalidate any previously issued (still active) OTPs for an email,
     * so only the latest code can be used.
     *
     * @param  string $email
     * @return $this
     */
    public function invalidatePreviousOtps($email)
    {
        $collection = Mage::getModel('ysrtech_otplogin/otp')->getCollection()
            ->addFieldToFilter('email', $email)
            ->addFieldToFilter('status', 1);

        foreach ($collection as $otp) {
            $otp->setStatus(0)->save();
        }

        return $this;
    }

    /**
     * Validate the supplied OTP for the given email.
     *
     * Checks the latest active record, verifies the hash matches and that the
     * code has not expired. On success the record is consumed (status = 0).
     *
     * @param  string $code
     * @param  string $email
     * @return bool
     */
    public function validateOtp($code, $email)
    {
        if (!strlen((string) $code) || !strlen((string) $email)) {
            return false;
        }

        /** @var YSRTech_OtpLogin_Model_Resource_Otp_Collection $collection */
        $collection = Mage::getModel('ysrtech_otplogin/otp')->getCollection()
            ->addFieldToFilter('email', $email)
            ->addFieldToFilter('otp', $this->hashOtp($code))
            ->addFieldToFilter('status', 1)
            ->setOrder('entity_id', 'DESC')
            ->setPageSize(1);

        /** @var YSRTech_OtpLogin_Model_Otp $otp */
        $otp = $collection->getFirstItem();
        if (!$otp->getId()) {
            return false;
        }

        $createdAt = strtotime($otp->getCreatedAt());
        $expire    = $this->getExpireTime();
        if ((time() - $createdAt) > $expire) {
            // Expired: burn it so it cannot be retried.
            $otp->setStatus(0)->save();
            return false;
        }

        // Consume the OTP so it cannot be reused.
        $otp->setStatus(0)->save();

        return true;
    }

    /* ------------------------------------------------------------------ *
     *  Google Sign-In (OAuth 2.0, email-only matching)
     * ------------------------------------------------------------------ */

    const XML_PATH_GOOGLE_ENABLED       = 'ysrtech_otplogin/google/enabled';
    const XML_PATH_GOOGLE_CLIENT_ID     = 'ysrtech_otplogin/google/client_id';
    const XML_PATH_GOOGLE_CLIENT_SECRET = 'ysrtech_otplogin/google/client_secret';

    const GOOGLE_AUTH_URL     = 'https://accounts.google.com/o/oauth2/v2/auth';
    const GOOGLE_TOKEN_URL    = 'https://oauth2.googleapis.com/token';
    const GOOGLE_USERINFO_URL = 'https://openidconnect.googleapis.com/v1/userinfo';

    /**
     * @return bool
     */
    public function isGoogleEnabled($store = null)
    {
        return Mage::getStoreConfigFlag(self::XML_PATH_GOOGLE_ENABLED, $store)
            && $this->getGoogleClientId($store)
            && $this->getGoogleClientSecret($store);
    }

    /**
     * @return string
     */
    public function getGoogleClientId($store = null)
    {
        return trim((string) Mage::getStoreConfig(self::XML_PATH_GOOGLE_CLIENT_ID, $store));
    }

    /**
     * Client secret is stored encrypted, so decrypt on read.
     *
     * @return string
     */
    public function getGoogleClientSecret($store = null)
    {
        $value = (string) Mage::getStoreConfig(self::XML_PATH_GOOGLE_CLIENT_SECRET, $store);
        if ($value === '') {
            return '';
        }
        return trim(Mage::helper('core')->decrypt($value));
    }

    /**
     * The redirect URI must exactly match what is registered in Google Cloud,
     * so it carries no session id and is forced to the secure base URL.
     *
     * @return string
     */
    public function getGoogleRedirectUrl()
    {
        return Mage::getUrl('otplogin/google/callback', array('_secure' => true, '_nosid' => true));
    }

    /**
     * Build the Google authorization URL the customer is sent to.
     *
     * @param  string $state CSRF token
     * @return string
     */
    public function getGoogleAuthUrl($state)
    {
        $params = array(
            'client_id'     => $this->getGoogleClientId(),
            'redirect_uri'  => $this->getGoogleRedirectUrl(),
            'response_type' => 'code',
            'scope'         => 'openid email profile',
            'state'         => $state,
            'access_type'   => 'online',
            'prompt'        => 'select_account',
        );
        return self::GOOGLE_AUTH_URL . '?' . http_build_query($params);
    }

    /**
     * Exchange an authorization code for an access token.
     *
     * @param  string $code
     * @return string Access token
     * @throws Mage_Core_Exception
     */
    public function exchangeGoogleCode($code)
    {
        $client = new Varien_Http_Client(self::GOOGLE_TOKEN_URL);
        $client->setMethod(Varien_Http_Client::POST);
        $client->setParameterPost(array(
            'code'          => $code,
            'client_id'     => $this->getGoogleClientId(),
            'client_secret' => $this->getGoogleClientSecret(),
            'redirect_uri'  => $this->getGoogleRedirectUrl(),
            'grant_type'    => 'authorization_code',
        ));

        $response = $client->request();
        $data     = Mage::helper('core')->jsonDecode($response->getBody());

        if (!is_array($data) || empty($data['access_token'])) {
            Mage::throwException($this->__('Google did not return an access token.'));
        }

        return $data['access_token'];
    }

    /**
     * Fetch the verified profile for an access token.
     *
     * @param  string $accessToken
     * @return array  sub, email, email_verified, given_name, family_name, name
     * @throws Mage_Core_Exception
     */
    public function getGoogleUserInfo($accessToken)
    {
        $client = new Varien_Http_Client(self::GOOGLE_USERINFO_URL);
        $client->setMethod(Varien_Http_Client::GET);
        $client->setHeaders('Authorization', 'Bearer ' . $accessToken);

        $response = $client->request();
        $data     = Mage::helper('core')->jsonDecode($response->getBody());

        if (!is_array($data) || empty($data['email'])) {
            Mage::throwException($this->__('Google did not return profile information.'));
        }

        return $data;
    }

    /**
     * Find a customer by email in the current website, or create one.
     *
     * Used by both the OTP and Google flows. Creation is gated on the
     * "Allow Registration" setting.
     *
     * @param  string $email
     * @param  string $firstname
     * @param  string $lastname
     * @return Mage_Customer_Model_Customer
     * @throws Mage_Core_Exception
     */
    public function getOrCreateCustomer($email, $firstname = '', $lastname = '')
    {
        $store    = Mage::app()->getStore();
        $customer = Mage::getModel('customer/customer');
        $customer->setWebsiteId($store->getWebsiteId());
        $customer->loadByEmail($email);

        if ($customer->getId()) {
            return $customer;
        }

        if (!$this->isRegistrationAllowed()) {
            Mage::throwException($this->__('This email address is not registered.'));
        }

        $customer = Mage::getModel('customer/customer');
        $customer->setWebsiteId($store->getWebsiteId())
            ->setStore($store)
            ->setEmail($email)
            ->setFirstname($firstname ? $firstname : $email)
            ->setLastname($lastname ? $lastname : '.')
            ->setPassword($customer->generatePassword(12));
        $customer->save();

        return $customer;
    }
}
