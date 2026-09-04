# YSRTech_OtpLogin

Email-based **One-Time-Password (OTP) login & registration** for **OpenMage LTS / Magento 1.x**.

This is a ground-up port of a Magento 2 SMS OTP module. The SMS / Twilio
delivery, the international telephone input and the `mobile_number` customer
attribute have all been removed. OTP codes are now generated server-side and
delivered by a standard transactional email.

## Features

- Sign in with an emailed OTP (no password needed).
- Optional account creation via OTP verification.
- Standard email + password login is still available as a second tab.
- Configurable OTP type (numeric / alphabetic / alphanumeric), length and expiry.
- OTP codes are stored **hashed** (SHA-256), single-use, and tied to the email.
- Uses OpenMage's transactional email system (sender identity + template are
  configurable in the admin).

## Requirements

- OpenMage LTS (19.x / 20.x) or Magento CE 1.9.x.
- A working transactional email configuration.

## Installation

1. Copy the contents of this package into your OpenMage root so that the
   folders merge:
   ```
   app/etc/modules/YSRTech_OtpLogin.xml
   app/code/local/YSRTech/OtpLogin/...
   app/design/frontend/base/default/layout/ysrtech_otplogin.xml
   app/design/frontend/base/default/template/ysrtech/otplogin/*.phtml
   app/locale/en_US/template/email/ysrtech_otplogin_otp.html
   app/locale/en_US/YSRTech_OtpLogin.csv
   skin/frontend/base/default/ysrtech_otplogin/...
   ```
2. Flush the cache: **System → Cache Management → Flush Magento Cache**
   (the install script creating the `ysrtech_email_otp` table runs automatically
   on the next request).
3. Configure under **System → Configuration → YSRTech → Email OTP Login**.
4. Make sure Magento's cron is running: a nightly job clears out spent codes,
   and without it the OTP table grows for the life of the store.

## Configuration

**System → Configuration → YSRTech → Email OTP Login**

| Setting | Description |
|---------|-------------|
| Enabled | Turns the popups and routes on/off. |
| Allow Registration via OTP | If yes, unknown emails can create an account after verifying an OTP. |
| OTP Type | Numeric / Alphabetic / Alphanumeric. |
| OTP Length | Number of characters in the code. |
| Expiry Time | Seconds the code stays valid. |
| Maximum Verification Attempts | Wrong codes allowed before the code is discarded (default 5). |
| Maximum Codes Per Address | Codes that may be sent to one address per window (default 5). |
| Sending Window | The period that limit is measured over, in seconds (default 3600). |
| Email Sender | Which store email identity sends the OTP. |
| Email Template | The transactional template used (defaults to the bundled one). |

## How it works

1. The customer clicks **Log In** (or **Create an Account**). The theme link is
   intercepted and a popup opens.
2. They enter their email (plus name/password for registration) and request an OTP.
   - `otplogin/account/otploginpost` validates, generates and emails the code.
3. They enter the code in the verification popup.
   - `otplogin/account/otppost` checks the hash + expiry, then logs them in or
     creates the account.
4. `otplogin/account/resendotp` issues a fresh code if needed.

## Security notes

- **Codes are generated with `random_int()`** and stored as an HMAC-SHA256
  keyed with the installation's crypt key and bound to the address. A bare
  digest of a six-digit code is reversed instantly from a table dump; a keyed
  one is not, and a hash lifted from one row cannot be replayed against another
  address.
- **Every endpoint requires the session's form key.** They are unauthenticated
  by nature — one sends mail, one signs a visitor in — so without it any page
  on the internet could drive them on a visitor's behalf.
- **Wrong codes are counted.** After *Maximum Verification Attempts* the code is
  discarded and a new one has to be requested. Without that cap a six-digit code
  is simply guessed.
- **Sends are capped per address.** The send endpoint puts a message in whatever
  inbox the caller names, so the limit is what stops it being pointed at a
  stranger.
- **Codes are timed in UTC.** The row's `created_at` is written explicitly
  rather than left to the column default, so expiry does not depend on the
  database server's time zone matching PHP's.
- **The address is proved before the account exists**, so accounts created this
  way are saved with no confirmation pending — a confirmation email the customer
  never asked for would otherwise lock them out of the account they just made.
- **Whether an address is registered is disclosed** by design: the sign-in tab
  says so, because a customer who mistyped their address needs to know. If you
  would rather not disclose it, make `otploginpost` answer "an OTP has been
  sent" either way and let the verification step fail.
- **Watch what else logs your outgoing mail.** Modules that keep a copy of every
  message — Mailgun's tracking table, for instance — store the rendered email,
  and the code is in it in plain text. Hashing the code in this module's own
  table does not help if another table holds the email body.

## Theme note

The popups are injected into the `before_body_end` layout block, which exists in
the OpenMage RWD theme. If your theme does not render that block, edit
`app/design/frontend/base/default/layout/ysrtech_otplogin.xml` and change the
reference from `before_body_end` to `content`. The popups stay hidden until
opened, so the placement is purely cosmetic.

## Google Sign-In (optional)

A "Sign in with Google" button can appear in the login and registration popups.
Account matching is **by verified email address only**: Google returns the
verified email, the store finds the matching customer and logs them in, or
creates one if registration is allowed.

**Setup in Google Cloud (one-time):**

1. Create a project at <https://console.cloud.google.com/>.
2. Configure the OAuth consent screen.
3. Create an **OAuth 2.0 Client ID** (type: Web application).
4. Under **Authorized redirect URIs**, add exactly:
   `https://<your-domain>/otplogin/google/callback`
5. Copy the **Client ID** and **Client Secret**.

**Setup in Magento:**

Under **System → Configuration → YSRTech → Email OTP Login → Google Sign-In**,
set *Enable Google Sign-In* to Yes and paste the Client ID and Client Secret
(the secret is stored encrypted). HTTPS is required.

Notes:
- Uses OpenMage's bundled `Varien_Http_Client` — no Composer package needed.
- Only logins where Google reports `email_verified = true` are accepted.
- A CSRF `state` token is generated per attempt and checked on callback.
- If a customer changes their Google email later, they will be treated as a new
  customer (this is the simple email-only matching mode).

## Removed from the original M2 module

- Twilio SDK dependency and all SMS sending.
- `intlTelInput` library, flag images and country configuration.
- The `mobile_number` customer EAV attribute and mobile-based lookups.
