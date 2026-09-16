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
     * Step 1: validate the email, generate an OTP and email it.
     */
    public function otploginpostAction()
    {
        $helper = $this->_helper();

        if (!$this->getRequest()->isPost() || !$helper->isEnabled()) {
            return $this->_json(true, $helper->__('Invalid request.'));
        }

        $params = $this->getRequest()->getPost();
        $email  = isset($params['email']) ? trim($params['email']) : '';

        if (!Zend_Validate::is($email, 'EmailAddress')) {
            return $this->_json(true, $helper->__('Please enter a valid email address.'));
        }

        // Registration is only in play when the create-account form is used (it
        // carries a first name); the sign-in form sends an email address alone.
        $isRegister = isset($params['firstname']);
        $customer   = $this->_loadCustomerByEmail($email);

        // ---- Passwordless sign-in --------------------------------------------------
        // The reply must never reveal whether an address has an account, so it is the
        // same generic line either way. A code is generated and emailed only when a
        // matching customer actually exists; for an unknown address nothing is sent,
        // but the flow (and the "enter your code" step) looks identical to an attacker.
        if (!$isRegister) {
            $this->_customerSession()->setOtpFormData(array(
                'email'       => $email,
                'is_register' => 0,
                'firstname'   => '',
                'lastname'    => '',
                'password'    => '',
            ));

            if ($customer->getId()) {
                try {
                    $helper->invalidatePreviousOtps($email);
                    $code = $helper->generateOtpCode();
                    $helper->saveOtpData($code, $email, $customer->getName());
                    $helper->sendOtpEmail($code, $email, $customer->getName());
                } catch (Exception $e) {
                    // Swallow so a send failure cannot be told apart from an unknown
                    // address; the customer simply never receives a code.
                    Mage::logException($e);
                }
            }

            return $this->_json(false, $helper->__('If this email address is registered, we have sent you an email with a code to sign in.'));
        }

        // ---- Registration by email verification (no password) ----------------------
        if ($customer->getId()) {
            return $this->_json(true, $helper->__('An account already exists for this email address. Please sign in instead.'));
        }

        if (!$helper->isRegistrationAllowed()) {
            return $this->_json(true, $helper->__('Registration is currently disabled.'));
        }

        try {
            $formData = array(
                'email'       => $email,
                'is_register' => 1,
                'firstname'   => isset($params['firstname']) ? trim($params['firstname']) : '',
                'lastname'    => isset($params['lastname']) ? trim($params['lastname']) : '',
                'password'    => '',
            );
            $this->_customerSession()->setOtpFormData($formData);

            $name = trim($formData['firstname'] . ' ' . $formData['lastname']);
            if (!$name) {
                $name = $email;
            }

            $helper->invalidatePreviousOtps($email);
            $code = $helper->generateOtpCode();
            $helper->saveOtpData($code, $email, $name);
            $helper->sendOtpEmail($code, $email, $name);

            return $this->_json(false, $helper->__('We have emailed you a code to finish creating your account.'));
        } catch (Exception $e) {
            Mage::logException($e);
            return $this->_json(true, $helper->__('We could not send your code. Please try again later.'));
        }
    }

    /**
     * Step 2: verify the OTP and either log in or create the account.
     */
    public function otppostAction()
    {
        $helper = $this->_helper();

        if (!$this->getRequest()->isPost() || !$helper->isEnabled()) {
            return $this->_json(true, $helper->__('Invalid request.'));
        }

        $formData = $this->_customerSession()->getOtpFormData();
        if (!$formData || empty($formData['email'])) {
            return $this->_json(true, $helper->__('Your session has expired. Please start again.'));
        }

        $email = $formData['email'];
        $code  = trim((string) $this->getRequest()->getPost('otp'));

        if (!$helper->validateOtp($code, $email)) {
            return $this->_json(true, $helper->__('That code is invalid or has expired.'));
        }

        try {
            $customer = $this->_loadCustomerByEmail($email);

            if (!$customer->getId()) {
                // Registration path.
                if (empty($formData['is_register']) || !$helper->isRegistrationAllowed()) {
                    return $this->_json(true, $helper->__('That code is invalid or has expired.'));
                }

                $store    = Mage::app()->getStore();
                $customer = Mage::getModel('customer/customer');
                $customer->setWebsiteId($store->getWebsiteId())
                    ->setStore($store)
                    ->setEmail($email)
                    ->setFirstname($formData['firstname'] ? $formData['firstname'] : $email)
                    ->setLastname($formData['lastname'] ? $formData['lastname'] : '.');

                if (!empty($formData['password'])) {
                    $customer->setPassword($formData['password']);
                } else {
                    $customer->setPassword($customer->generatePassword(12));
                }

                $customer->save();

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
     * Sign the customer out and return them to checkout.
     *
     * Used by the "Log in with a different email" link in the checkout billing
     * section. After logout the checkout gate treats them as a guest and shows
     * the sign-in panel again, so they can sign in with another address.
     */
    public function logoutAction()
    {
        Mage::getSingleton('customer/session')->logout()->renewSession();
        $this->_redirect('onestepcheckout', array('_secure' => true));
    }

    /**
     * Re-send an OTP for the email currently held in session.
     */
    public function resendotpAction()
    {
        $helper = $this->_helper();

        if (!$helper->isEnabled()) {
            return $this->_json(true, $helper->__('Invalid request.'));
        }

        $formData = $this->_customerSession()->getOtpFormData();
        if (!$formData || empty($formData['email'])) {
            return $this->_json(true, $helper->__('Your session has expired. Please start again.'));
        }

        $email = $formData['email'];

        try {
            // Mirror the send step: registration always re-sends, sign-in only for a
            // real account, and the reply is the same either way (no enumeration).
            $isRegister = !empty($formData['is_register']);
            $name       = trim($formData['firstname'] . ' ' . $formData['lastname']);
            if (!$name) {
                $name = $email;
            }

            $send = $isRegister;
            if (!$isRegister) {
                $customer = $this->_loadCustomerByEmail($email);
                $send     = (bool) $customer->getId();
                if ($send) {
                    $name = $customer->getName();
                }
            }

            if ($send) {
                $helper->invalidatePreviousOtps($email);
                $code = $helper->generateOtpCode();
                $helper->saveOtpData($code, $email, $name);
                $helper->sendOtpEmail($code, $email, $name);
            }

            return $this->_json(false, $helper->__('If this email address is registered, we have sent you a new code.'));
        } catch (Exception $e) {
            Mage::logException($e);
            return $this->_json(true, $helper->__('We could not resend your code. Please try again later.'));
        }
    }
}
