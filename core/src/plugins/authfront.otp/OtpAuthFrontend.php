<?php
/*
 * Copyright 2007-2013 Charles du Jeu - Abstrium SAS <team (at) pyd.io>
 * This file is part of Pydio.
 *
 * Pydio is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Pydio is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with Pydio.  If not, see <http://www.gnu.org/licenses/>.
 *
 * The latest code can be found at <http://pyd.io/>.
 */
namespace Pydio\Auth\Frontend;

use Auth_Yubico;
use Exception;
use PEAR;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Pydio\Auth\Frontend\Core\AbstractAuthFrontend;
use Pydio\Core\Services\RolesService;
use Pydio\Core\Services\UsersService;

defined('AJXP_EXEC') or die('Access not allowed');

/**
 * Class OtpAuthFrontend
 * @package Pydio\Auth\Frontend
 */
class OtpAuthFrontend extends AbstractAuthFrontend
{

    private $yubicoSecretKey;
    private $yubicoClientId;
    private $google;
    private $googleLast;
    private $yubikey1;
    private $yubikey2;

    /**
     * Try to authenticate the user based on various external parameters
     * Return true if user is now logged.
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @param bool $isLast Whether this is is the last plugin called.
     * @return bool
     */
    function tryToLogUser(ServerRequestInterface &$request, ResponseInterface &$response, $isLast = false)
    {

        $httpVars = $request->getParsedBody();
        $exceptionMsg = "Login information is not correct! Please try again with the one-time-password at the end of your password";
        if (empty($httpVars) || empty($httpVars["userid"])) {
            return false;
        } else {
            $userid = $httpVars["userid"];
            $this->loadConfig($userid);
            // if there is no configuration for OTP, this means that this user don't have OTP
            if ((empty($this->google) &&
                empty($this->googleLast) &&
                empty($this->yubikey1) &&
                empty($this->yubikey2))
            ) {
                return false;
            }

            // load Yubico class
            if (!empty($this->yubikey1)) {
                require_once 'Auth/Yubico.php';
            }

            // cut off password and otp in pass field
            $cutPassword = false;
            if (isSet($httpVars["otp_code"]) && !empty($httpVars["otp_code"])) {
                $codeOTP = $httpVars["otp_code"];
            } else if (strlen($httpVars["password"]) > 6) {
                $codeOTP = substr($httpVars["password"], -6);
                $cutPassword = true;
            } else {
                $this->breakAndSendError($exceptionMsg);
            }

            //Just the Google Authenticator set
            if (!empty($this->google) &&
                empty($this->yubikey1) &&
                empty($this->yubikey2)
            ) {
                if ($this->checkGooglePass($userid, $codeOTP, $this->google, $this->googleLast)) {
                    $this->logDebug(__CLASS__, __FUNCTION__, "Check OTP: matched");
                    //return false and cut off otp from password for next authfront.
                    if ($cutPassword) {
                        $httpVars["password"] = substr($httpVars["password"], 0, strlen($httpVars["password"]) - 6);
                        $request = $request->withParsedBody($httpVars);
                    }
                    return false;
                } else {
                    $this->breakAndSendError($exceptionMsg);
                }
            } elseif
                // YubiKey1 or YubiKey2 set
            (empty($this->google) &&
                (!empty($this->yubikey1) || !empty($this->yubikey2))
            ) {
                if ($this->checkYubiOTP($httpVars["otp_code"], $this->yubikey1, $this->yubikey2)) {
                    return false;
                } else {
                    $this->breakAndSendError($exceptionMsg);
                }
            } elseif
                // Both Yubikey and Google Authenticator set
                // If the last character of the password is digit, it is Google Authenticator
            (ctype_digit(substr($httpVars["password"], -1))) {
                if ($this->checkGooglePass($userid, $codeOTP, $this->google, $this->googleLast)) {
                    if ($cutPassword) {
                        $httpVars["password"] = substr($httpVars["password"], 0, strlen($httpVars["password"]) - 6);
                        $request = $request->withParsedBody($httpVars);
                    }
                    return false;
                } else {
                    $this->breakAndSendError($exceptionMsg);
                }
            } else {
                if ($this->checkYubiOTP($httpVars["otp_code"], $this->yubikey1, $this->yubikey2)) {
                    return false;
                } else {
                    $this->breakAndSendError($exceptionMsg);
                }
            }
        }
    }

    /**
     * @param $exceptionMsg
     * @throws \Pydio\Core\Exception\AuthRequiredException
     */
    protected function breakAndSendError($exceptionMsg)
    {

        throw new \Pydio\Core\Exception\AuthRequiredException($exceptionMsg);

    }


    /**
     * @param $userid
     * @throws \Pydio\Core\Exception\UserNotFoundException
     */
    private function loadConfig($userid)
    {

        $userObject = UsersService::getUserById($userid);

        if ($userObject != null) {
            $this->google = $userObject->getMergedRole()->filterParameterValue("authfront.otp", "google", AJXP_REPO_SCOPE_ALL, '');
            $this->googleLast = $userObject->getMergedRole()->filterParameterValue("authfront.otp", "google_last", AJXP_REPO_SCOPE_ALL, '');
            $this->yubikey1 = $userObject->getMergedRole()->filterParameterValue("authfront.otp", "yubikey1", AJXP_REPO_SCOPE_ALL, '');
            $this->yubikey2 = $userObject->getMergedRole()->filterParameterValue("authfront.otp", "yubikey2", AJXP_REPO_SCOPE_ALL, '');
        }
        if (!empty($this->pluginConf["YUBICO_CLIENT_ID"])) {
            $this->yubicoClientId = trim($this->pluginConf["YUBICO_CLIENT_ID"]);
        }
        if (!empty($this->pluginConf["YUBICO_SECRET_KEY"])) {
            $this->yubicoSecretKey = trim($this->pluginConf["YUBICO_SECRET_KEY"]);
        }
    }

    // Google Authenticator

    /**
     * @param $key
     * @param $counter
     * @return string
     */
    public function oath_hotp($key, $counter)
    {
        $key = pack("H*", $key);
        $cur_counter = array(0, 0, 0, 0, 0, 0, 0, 0);
        for ($i = 7; $i >= 0; $i--) {
            $cur_counter[$i] = pack('C*', $counter);
            $counter = $counter >> 8;
        }
        $bin_counter = implode($cur_counter);
        // Pad to 8 chars
        if (strlen($bin_counter) < 8) {
            $bin_counter = str_repeat(chr(0), 8 - strlen($bin_counter)) . $bin_counter;
        }

        // HMAC
        $hash = hash_hmac('sha1', $bin_counter, $key);
        return str_pad($this->oath_truncate($hash), 6, "0", STR_PAD_LEFT);
    }

    /**
     * @param $hash
     * @param int $length
     * @return int
     */
    public function oath_truncate($hash, $length = 6)
    {
        // Convert to dec
        foreach (str_split($hash, 2) as $hex) {
            $hmac_result[] = hexdec($hex);
        }

        // Find offset
        $offset = $hmac_result[19] & 0xf;

        // Algorithm from RFC
        return
            (
                (($hmac_result[$offset + 0] & 0x7f) << 24) |
                (($hmac_result[$offset + 1] & 0xff) << 16) |
                (($hmac_result[$offset + 2] & 0xff) << 8) |
                ($hmac_result[$offset + 3] & 0xff)
            ) % pow(10, $length);
    }

    /**
     * @param $b32
     * @return string
     */
    public function base32ToHex($b32)
    {
        $alphabet = "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567";

        $out = "";
        $dous = "";

        for ($i = 0; $i < strlen($b32); $i++) {
            $in = strrpos($alphabet, $b32[$i]);
            $b = str_pad(base_convert($in, 10, 2), 5, "0", STR_PAD_LEFT);
            $out .= $b;
            $dous .= $b . ".";
        }

        $ar = str_split($out, 20);

        $out2 = "";
        foreach ($ar as $val) {
            $rv = str_pad(base_convert($val, 2, 16), 5, "0", STR_PAD_LEFT);
            $out2 .= $rv;

        }
        return $out2;
    }

    /**
     * @param $loginId
     * @param $codeOTP
     * @param $userToken
     * @param $userInvalid
     * @return bool
     * @throws Exception
     * @throws \Pydio\Core\Exception\UserNotFoundException
     */
    public function checkGooglePass($loginId, $codeOTP, $userToken, $userInvalid)
    {
        $userToken = $this->base32ToHex($userToken);

        $now = time();
        $totpSkew = 2; // how many tokens in either side we should check. 2 means +-1 min
        $tokenTimer = 30; // google authenticator support just 30s

        $earliest = $now - ($totpSkew * $tokenTimer);
        $latest = $now + ($totpSkew * $tokenTimer);

        $st = ((int)($earliest / $tokenTimer));
        $en = ((int)($latest / $tokenTimer));

        $valid = 0;
        $this->logDebug(__CLASS__, __FUNCTION__, "codeOTP " . $codeOTP);
        for ($i = $st; ($i <= $en && $valid == 0); $i++) {
            if ($i > $userInvalid) {
                $stest = $this->oath_hotp($userToken, $i);
                $this->logDebug(__CLASS__, __FUNCTION__, "stest " . $stest);
                if ($codeOTP == $stest) {
                    $valid = 1;
                    // save google_last
                    $userObject = UsersService::getUserById($loginId);
                    $role = $userObject->getPersonalRole();
                    if ($role === false) {
                        throw new Exception("Cant find role! ");
                    }
                    $role->setParameterValue("authfront.otp", "google_last", $i);
                    RolesService::updateRole($role, $userObject);

                    return true;
                }
            }
        }

        return false;
        //return ( AJXP_Utils::pbkdf2_validate_password($pass, $userStoredPass) && $valid == 1);
    }

    // YubiKey

    /**
     * @param $otp_code
     * @param $yubikey1
     * @param $yubikey2
     * @return bool
     */
    public function checkYubiOTP($otp_code, $yubikey1, $yubikey2)
    {

        // yubikey generates 44 character, identity is the first 12 character
        $yubi1_identity = substr($yubikey1, 0, 12);
        $yubi2_identity = substr($yubikey2, 0, 12);
        $otp_identity = substr($otp_code, -44, 12);
        if (($otp_identity != $yubi1_identity) and ($otp_identity != $yubi2_identity)) {
            // YubiKey not listed in account
            return false;
        }

        $yotp = substr($otp_code, -44);
        $otp_code = substr($otp_code, 0, strlen($otp_code) - 44);

        $yubi = new Auth_Yubico($this->yubicoClientId, $this->yubicoSecretKey);
        $auth = $yubi->verify($yotp);

        return (!PEAR::isError($auth));
    }

}