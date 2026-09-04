<?php
/**
 * YSRTech OtpLogin - Account controller.
 *
 * Email OTP login & registration flow:
 *   otplogin/account/otploginpost  -> send OTP to an email address
 *   otplogin/account/otppost       -> verify OTP, then log in or register
 *   otplogin/account/resendotp     -> re-send the OTP
 *
 * @category  YSRTech
 * @package   YSRTech_OtpLogin
 */
class YSRTech_OtpLogin_AccountController extends Mage_Core_Controller_Front_Action
{
    /**
     * @return YSRTech_OtpLogin_Helper_Data
     */
    protected function _helper()
    {
        return Mage::helper('ysrtech_otplogin');
    }

    /**
     * @return Mage_Customer_Model_Session
     */
    protected function _customerSession()
    {
        return Mage::getSingleton('customer/session');
    }

    /**
     * Emit a JSON response and stop.
     *
     * @param bool   $error
     * @param string $message
     */
    protected function _json($error, $message)
    {
        $this->getResponse()
            ->setHeader('Content-Type', 'application/json', true)
            ->setBody(Mage::helper('core')->jsonEncode(array(
                'errors'  => (bool) $error,
                'message' => $message,
            )));
    }

    /**
     * Every action here is an unauthenticated POST that either sends mail or
     * signs somebody in, so each one is gated the same way: it must be a POST,
     * the module must be on, and the request must carry this session's form key.
     *
     * @return bool Whether the caller may proceed (a refusal is already sent)
     */
    protected function _canProceed()
    {
        $helper = $this->_helper();

        if (!$this->getRequest()->isPost() || !$helper->isEnabled()) {
            $this->_json(true, $helper->__('Invalid request.'));
            return false;
        }

        if (!$this->_validateFormKey()) {
            $this->_json(true, $helper->__('Your session has expired. Please reload the page and try again.'));
            return false;
        }

        return true;
    }

    /**
     * Look up a customer by email within the current website.
     *
     * @param  string $email
     * @return Mage_Customer_Model_Customer
     */
    protected function _loadCustomerByEmail($email)
    {
        $customer = Mage::getModel('customer/customer');
        $customer->setWebsiteId(Mage::app()->getStore()->getWebsiteId());
        $customer->loadByEmail($email);
        return $customer;
    }

    /**
     * Check what the store will insist on anyway, while a correction is still
     * cheap. Returns true, or the message to show.
     *
     * @param  array $params
     * @return true|string
     */
    protected function _validateRegistration(array $params)
    {
        $helper   = $this->_helper();
        $password = isset($params['password']) ? (string) $params['password'] : '';

        if (trim((string) (isset($params['firstname']) ? $params['firstname'] : '')) === '') {
            return $helper->__('Please enter your first name.');
        }

        if (trim((string) (isset($params['lastname']) ? $params['lastname'] : '')) === '') {
            return $helper->__('Please enter your last name.');
        }

        /*
         * Mage_Customer_Model_Customer::validate() lets a short password
         * through and the resource model then throws on save, so the length is
         * checked here against the same minimum the resource enforces.
         */
        if ($password !== '' && Mage::helper('core/string')->strlen($password) < 7) {
            return $helper->__('The password must have at least 7 characters.');
        }

        return true;
    }

    /**
     * Step 1: validate the email, generate an OTP and email it.
     */
    public function otploginpostAction()
    {
        $helper = $this->_helper();

        if (!$this->_canProceed()) {
            return;
        }

        $params = $this->getRequest()->getPost();
        $email  = isset($params['email']) ? trim($params['email']) : '';

        if (!Zend_Validate::is($email, 'EmailAddress')) {
            return $this->_json(true, $helper->__('Please enter a valid email address.'));
        }

        // Registration data is only present when the create-account form is used.
        $isRegister = isset($params['firstname']) || isset($params['password']);
        $customer   = $this->_loadCustomerByEmail($email);

        if (!$customer->getId() && !$isRegister) {
            return $this->_json(true, $helper->__('This email address is not registered.'));
        }

        if ($customer->getId() && $isRegister) {
            return $this->_json(true, $helper->__('An account already exists for this email address. Please sign in instead.'));
        }

        if (!$customer->getId() && $isRegister && !$helper->isRegistrationAllowed()) {
            return $this->_json(true, $helper->__('Registration is currently disabled.'));
        }

        if (!$helper->canSendOtp($email)) {
            return $this->_json(
                true,
                $helper->__('Too many codes have been requested for this address. Please try again later.')
            );
        }

        /*
         * Checked before the code is sent rather than after it is spent: a
         * password the store will refuse should cost the customer a
         * correction, not a burnt code and a generic failure.
         */
        if ($isRegister && ($error = $this->_validateRegistration($params)) !== true) {
            return $this->_json(true, $error);
        }

        try {
            // Remember what we are doing for the verification step.
            $formData = array(
                'email'       => $email,
                'is_register' => $isRegister ? 1 : 0,
                'firstname'   => isset($params['firstname']) ? trim($params['firstname']) : '',
                'lastname'    => isset($params['lastname']) ? trim($params['lastname']) : '',
                'password'    => isset($params['password']) ? $params['password'] : '',
            );
            $this->_customerSession()->setOtpFormData($formData);

            $name = trim($formData['firstname'] . ' ' . $formData['lastname']);
            if (!$name) {
                $name = $customer->getId() ? $customer->getName() : $email;
            }

            $helper->invalidatePreviousOtps($email);
            $code = $helper->generateOtpCode();
            $helper->saveOtpData($code, $email, $name);
            $helper->sendOtpEmail($code, $email, $name);

            return $this->_json(false, $helper->__('An OTP has been sent to your email address.'));
        } catch (Exception $e) {
            Mage::logException($e);
            return $this->_json(true, $helper->__('We could not send the OTP. Please try again later.'));
        }
    }

    /**
     * Step 2: verify the OTP and either log in or create the account.
     */
    public function otppostAction()
    {
        $helper = $this->_helper();

        if (!$this->_canProceed()) {
            return;
        }

        $formData = $this->_customerSession()->getOtpFormData();
        if (!$formData || empty($formData['email'])) {
            return $this->_json(true, $helper->__('Your session has expired. Please start again.'));
        }

        $email = $formData['email'];
        $code  = trim((string) $this->getRequest()->getPost('otp'));

        if (!$helper->validateOtp($code, $email)) {
            return $this->_json(true, $helper->__('The OTP is invalid or has expired.'));
        }

        try {
            $customer = $this->_loadCustomerByEmail($email);

            if (!$customer->getId()) {
                // Registration path.
                if (empty($formData['is_register']) || !$helper->isRegistrationAllowed()) {
                    return $this->_json(true, $helper->__('This email address is not registered.'));
                }

                $store    = Mage::app()->getStore();
                $customer = Mage::getModel('customer/customer');
                $customer->setWebsiteId($store->getWebsiteId())
                    ->setStore($store)
                    ->setEmail($email)
                    ->setFirstname($formData['firstname'] ? $formData['firstname'] : $email)
                    ->setLastname($formData['lastname'] ? $formData['lastname'] : '.')
                    ->setGroupId(Mage::getStoreConfig(Mage_Customer_Model_Group::XML_PATH_DEFAULT_ID, $store));

                if (!empty($formData['password'])) {
                    $customer->setPassword($formData['password']);
                } else {
                    $customer->setPassword($customer->generatePassword(12));
                }

                // The address has just been proved, so there is nothing left
                // to confirm - and a confirmation the customer never receives
                // would lock them out of the account they just made.
                $customer->setConfirmation(null);
                $customer->save();
                $helper->sendWelcomeEmail($customer);

                $message = $helper->__('Your account has been created and you are now signed in.');
            } else {
                $message = $helper->__('You are now signed in.');
            }

            $this->_customerSession()->setCustomerAsLoggedIn($customer);
            $this->_customerSession()->renewSession();
            $this->_customerSession()->unsOtpFormData();

            return $this->_json(false, $message);
        } catch (Mage_Core_Exception $e) {
            return $this->_json(true, $e->getMessage());
        } catch (Exception $e) {
            Mage::logException($e);
            return $this->_json(true, $helper->__('We could not complete your request. Please try again later.'));
        }
    }

    /**
     * Re-send an OTP for the email currently held in session.
     */
    public function resendotpAction()
    {
        $helper = $this->_helper();

        if (!$this->_canProceed()) {
            return;
        }

        $formData = $this->_customerSession()->getOtpFormData();
        if (!$formData || empty($formData['email'])) {
            return $this->_json(true, $helper->__('Your session has expired. Please start again.'));
        }

        $email = $formData['email'];

        if (!$helper->canSendOtp($email)) {
            return $this->_json(
                true,
                $helper->__('Too many codes have been requested for this address. Please try again later.')
            );
        }

        try {
            $name = trim($formData['firstname'] . ' ' . $formData['lastname']);
            if (!$name) {
                $name = $email;
            }

            $helper->invalidatePreviousOtps($email);
            $code = $helper->generateOtpCode();
            $helper->saveOtpData($code, $email, $name);
            $helper->sendOtpEmail($code, $email, $name);

            return $this->_json(false, $helper->__('A new OTP has been sent to your email address.'));
        } catch (Exception $e) {
            Mage::logException($e);
            return $this->_json(true, $helper->__('We could not resend the OTP. Please try again later.'));
        }
    }
}
