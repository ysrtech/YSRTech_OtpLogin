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
    const XML_PATH_MAX_ATTEMPTS       = 'ysrtech_otplogin/general/max_attempts';
    const XML_PATH_MAX_SENDS          = 'ysrtech_otplogin/general/max_sends';
    const XML_PATH_SEND_WINDOW        = 'ysrtech_otplogin/general/send_window';

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
     * How many wrong codes may be entered against one OTP before it is burnt.
     *
     * @return int
     */
    public function getMaxAttempts($store = null)
    {
        $max = (int) Mage::getStoreConfig(self::XML_PATH_MAX_ATTEMPTS, $store);
        return $max > 0 ? $max : 5;
    }

    /**
     * How many codes may be sent to one address inside the window below.
     *
     * @return int
     */
    public function getMaxSends($store = null)
    {
        $max = (int) Mage::getStoreConfig(self::XML_PATH_MAX_SENDS, $store);
        return $max > 0 ? $max : 5;
    }

    /**
     * Length of the sending window, in seconds.
     *
     * @return int
     */
    public function getSendWindow($store = null)
    {
        $window = (int) Mage::getStoreConfig(self::XML_PATH_SEND_WINDOW, $store);
        return $window > 0 ? $window : 3600;
    }

    /**
     * Whether another code may be sent to this address right now.
     *
     * Without this the send endpoint is a mail relay pointed at any address
     * an attacker names: it is unauthenticated by nature, and every call puts
     * a message in someone's inbox. The count comes from the OTP table rather
     * than the session, because a session is the one thing the caller controls.
     *
     * @param  string $email
     * @return bool
     */
    public function canSendOtp($email)
    {
        /*
         * gmdate, not Mage::getModel('core/date')->gmtDate($f, $ts): given a
         * timestamp that helper reads it as store-local and converts it to
         * GMT, so an already-GMT value comes back shifted by the store's
         * offset - which on a store behind UTC lands the window in the future
         * and lets every send through.
         */
        $since = gmdate('Y-m-d H:i:s', time() - $this->getSendWindow());

        $count = Mage::getModel('ysrtech_otplogin/otp')->getCollection()
            ->addFieldToFilter('email', $email)
            ->addFieldToFilter('created_at', array('gteq' => $since))
            ->getSize();

        return $count < $this->getMaxSends();
    }

    /**
     * Generate a fresh OTP code according to the configured type and length.
     *
     * random_int, not mt_rand: mt_rand's state can be recovered from a handful
     * of outputs, and an attacker can ask for as many codes as they like.
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
            $code .= $pool[random_int(0, $max)];
        }

        return $code;
    }

    /**
     * Hash an OTP for storage / comparison. We never store the plain code.
     *
     * Keyed with the installation's crypt key rather than a bare digest: a
     * six-digit code has a million possible values, so a plain SHA-256 of one
     * is reversed by a laptop the moment the table leaks. The address is mixed
     * in as well, so a hash lifted from one row cannot be replayed against
     * another address.
     *
     * @param  string $code
     * @param  string $email
     * @return string
     */
    public function hashOtp($code, $email)
    {
        $key = (string) Mage::getConfig()->getNode('global/crypt/key');

        return hash_hmac('sha256', strtolower(trim((string) $email)) . '|' . (string) $code, $key);
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
            ->setOtp($this->hashOtp($code, $email))
            ->setStatus(1)
            ->setAttempts(0)
            // Written explicitly rather than left to the column's
            // CURRENT_TIMESTAMP default, which follows the database server's
            // time zone. Expiry is measured in PHP, so the two have to agree.
            ->setCreatedAt(gmdate('Y-m-d H:i:s'))
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
     * The lookup is by address, not by hash. Looking the row up by the hash of
     * what was typed means a wrong guess matches nothing, so there is no row on
     * which to record that a guess was made - which is how the original left
     * the codes open to being tried over and over. Here the live code is loaded
     * first and the guess compared against it, so every miss is counted and the
     * code is burnt once the allowance runs out.
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
            ->addFieldToFilter('status', 1)
            ->setOrder('entity_id', 'DESC')
            ->setPageSize(1);

        /** @var YSRTech_OtpLogin_Model_Otp $otp */
        $otp = $collection->getFirstItem();
        if (!$otp->getId()) {
            return false;
        }

        $createdAt = strtotime($otp->getCreatedAt() . ' UTC');
        if ((time() - $createdAt) > $this->getExpireTime()) {
            // Expired: burn it so it cannot be retried.
            $otp->setStatus(0)->save();
            return false;
        }

        if ((int) $otp->getAttempts() >= $this->getMaxAttempts()) {
            $otp->setStatus(0)->save();
            return false;
        }

        // hash_equals, so a wrong guess takes the same time as a right one
        if (!hash_equals((string) $otp->getOtp(), $this->hashOtp($code, $email))) {
            $otp->setAttempts((int) $otp->getAttempts() + 1)->save();
            return false;
        }

        // Consume the OTP so it cannot be reused.
        $otp->setStatus(0)->save();

        return true;
    }

    /**
     * Drop OTP rows that are long past use. Called from cron - the table is
     * written to on every sign-in attempt and nothing else ever clears it.
     *
     * @param  int $olderThanSeconds
     * @return int Rows removed
     */
    public function cleanExpiredOtps($olderThanSeconds = 86400)
    {
        $resource = Mage::getSingleton('core/resource');
        $adapter  = $resource->getConnection('core_write');
        $table    = $resource->getTableName('ysrtech_otplogin/otp');
        $cutoff   = gmdate('Y-m-d H:i:s', time() - (int) $olderThanSeconds);

        return (int) $adapter->delete($table, array('created_at < ?' => $cutoff));
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
            ->setGroupId(Mage::getStoreConfig(Mage_Customer_Model_Group::XML_PATH_DEFAULT_ID, $store))
            ->setPassword($customer->generatePassword(12));

        // The address has just been proved, so there is nothing left to confirm
        $customer->setConfirmation(null);
        $customer->save();
        $this->sendWelcomeEmail($customer);

        return $customer;
    }

    /**
     * Send the store's usual new-account email, without letting a mail problem
     * cost the customer the account they just created.
     *
     * @param  Mage_Customer_Model_Customer $customer
     * @return $this
     */
    public function sendWelcomeEmail(Mage_Customer_Model_Customer $customer)
    {
        try {
            $customer->sendNewAccountEmail('registered', '', $customer->getStoreId());
        } catch (Exception $e) {
            Mage::logException($e);
        }

        return $this;
    }
}
