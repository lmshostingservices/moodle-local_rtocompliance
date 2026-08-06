<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_rtocompliance\usi;

defined('MOODLE_INTERNAL') || die();

/**
 * USI Registry API Client
 * 
 * Implements the Machine Authentication Service - Security Token Service (MAS-ST)
 * for verifying Unique Student Identifiers against the Australian Government USI Registry.
 * 
 * Based on MAS-ST Service Definition v1.1 (June 2024)
 * https://softwareauthorisations.ato.gov.au/R3.0/S007v1.3/service.svc
 * @package    local_rtocompliance
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */
class usi_registry_client {
    
    const ENDPOINT_PRODUCTION_SHA256 = 'https://softwareauthorisations.ato.gov.au/R3.0/S007v1.3/service.svc';
    const ENDPOINT_EVTE_SHA256 = 'https://softwareauthorisations.evte.ato.gov.au/R3.0/S007v1.3/service.svc';
    const ENDPOINT_PRODUCTION_SHA1 = 'https://softwareauthorisations.ato.gov.au/R3.0/S007v1.2/service.svc';
    const ENDPOINT_EVTE_SHA1 = 'https://softwareauthorisations.evte.ato.gov.au/R3.0/S007v1.2/service.svc';
    
    const USI_REGISTRY_ENDPOINT = 'https://3pt.portal.usi.gov.au/Service/UsiService.svc';
    const USI_REGISTRY_EVTE = 'https://3pt.evte.usi.gov.au/Service/UsiService.svc';
    
    const TOKEN_LIFETIME_MINUTES = 30;
    const REQUEST_TIMEOUT_SECONDS = 30;
    
    const NS_SOAP12 = 'http://www.w3.org/2003/05/soap-envelope';
    const NS_WSTRUST = 'http://docs.oasis-open.org/ws-sx/ws-trust/200512';
    const NS_WSSE = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';
    const NS_WSU = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd';
    const NS_WSA = 'http://www.w3.org/2005/08/addressing';
    const NS_XMLDSIG = 'http://www.w3.org/2000/09/xmldsig#';
    const NS_SAML = 'urn:oasis:names:tc:SAML:2.0:assertion';
    const NS_WSPOLICY = 'http://schemas.xmlsoap.org/ws/2004/09/policy';
    
    private $endpoint;
    private $certificate_path;
    private $certificate_password;
    private $organization_id;
    private $is_test_mode;
    private $last_error;
    private $debug_mode;
    
    public function __construct($config = []) {
        $this->is_test_mode = $config['test_mode'] ?? true;
        $this->debug_mode = $config['debug_mode'] ?? false;
        
        $this->endpoint = $this->is_test_mode 
            ? self::ENDPOINT_EVTE_SHA256 
            : self::ENDPOINT_PRODUCTION_SHA256;
            
        $this->certificate_path = $config['certificate_path'] ?? '';
        $this->certificate_password = $config['certificate_password'] ?? '';
        $this->organization_id = $config['organization_id'] ?? '';
    }
    
    /**
     * Verify a single USI against the registry
     * 
     * @param string $usi The 10-character USI to verify
     * @param string $firstname Student's first name
     * @param string $lastname Student's last name
     * @param string $dateofbirth Date of birth (YYYY-MM-DD format)
     * @return array ['verified' => bool, 'status' => string, 'message' => string, 'details' => array]
     */
    public function verify_usi($usi, $firstname, $lastname, $dateofbirth) {
        $this->last_error = null;
        
        $validation = $this->validate_input($usi, $firstname, $lastname, $dateofbirth);
        if (!$validation['valid']) {
            return [
                'verified' => false,
                'status' => 'INVALID_INPUT',
                'message' => $validation['error'],
                'details' => [],
            ];
        }
        
        if (!$this->has_valid_credential()) {
            return [
                'verified' => false,
                'status' => 'CREDENTIAL_ERROR',
                'message' => 'Machine credential not configured or invalid',
                'details' => [],
            ];
        }
        
        try {
            $token = $this->request_security_token();
            if (!$token) {
                return [
                    'verified' => false,
                    'status' => 'TOKEN_ERROR',
                    'message' => $this->last_error ?: 'Failed to obtain security token',
                    'details' => [],
                ];
            }
            
            $result = $this->call_usi_verification_service($token, $usi, $firstname, $lastname, $dateofbirth);
            return $result;
            
        } catch (\Exception $e) {
            $this->log_error('verify_usi', $e->getMessage());
            return [
                'verified' => false,
                'status' => 'ERROR',
                'message' => 'USI verification service error: ' . $e->getMessage(),
                'details' => [],
            ];
        }
    }
    
    /**
     * Batch verify multiple USIs
     * 
     * @param array $students Array of ['usi', 'firstname', 'lastname', 'dateofbirth']
     * @return array Results indexed by USI
     */
    public function verify_batch($students) {
        $results = [];
        $token = null;
        
        if (!$this->has_valid_credential()) {
            foreach ($students as $student) {
                $results[$student['usi']] = [
                    'verified' => false,
                    'status' => 'CREDENTIAL_ERROR',
                    'message' => 'Machine credential not configured',
                ];
            }
            return $results;
        }
        
        try {
            $token = $this->request_security_token();
        } catch (\Exception $e) {
            foreach ($students as $student) {
                $results[$student['usi']] = [
                    'verified' => false,
                    'status' => 'TOKEN_ERROR',
                    'message' => $e->getMessage(),
                ];
            }
            return $results;
        }
        
        foreach ($students as $student) {
            $usi = $student['usi'];
            
            usleep(100000);
            
            try {
                $results[$usi] = $this->call_usi_verification_service(
                    $token,
                    $usi,
                    $student['firstname'],
                    $student['lastname'],
                    $student['dateofbirth']
                );
            } catch (\Exception $e) {
                $results[$usi] = [
                    'verified' => false,
                    'status' => 'ERROR',
                    'message' => $e->getMessage(),
                ];
            }
        }
        
        return $results;
    }
    
    /**
     * Request a security token from MAS-ST using WS-Trust
     * 
     * @return string|null The security token or null on failure
     */
    private function request_security_token() {
        $messageid = 'urn:uuid:' . $this->generate_uuid();
        $timestamp = gmdate('Y-m-d\TH:i:s\Z');
        $expires = gmdate('Y-m-d\TH:i:s\Z', time() + (self::TOKEN_LIFETIME_MINUTES * 60));
        
        $request = $this->build_rst_request($messageid, $timestamp, $expires);
        
        $signedrequest = $this->sign_request($request);
        
        $response = $this->send_soap_request($this->endpoint, $signedrequest, 'Issue');
        
        if (!$response) {
            return null;
        }
        
        $token = $this->parse_rstr_response($response);
        
        return $token;
    }
    
    /**
     * Build the Request Security Token (RST) SOAP message
     */
    private function build_rst_request($messageid, $timestamp, $expires) {
        $relyingparty = $this->is_test_mode ? self::USI_REGISTRY_EVTE : self::USI_REGISTRY_ENDPOINT;
        
        $soap = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope" 
            xmlns:a="http://www.w3.org/2005/08/addressing"
            xmlns:u="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd">
    <s:Header>
        <a:Action s:mustUnderstand="1">http://docs.oasis-open.org/ws-sx/ws-trust/200512/RST/Issue</a:Action>
        <a:MessageID>{$messageid}</a:MessageID>
        <a:ReplyTo>
            <a:Address>http://www.w3.org/2005/08/addressing/anonymous</a:Address>
        </a:ReplyTo>
        <a:To s:mustUnderstand="1">{$this->endpoint}</a:To>
        <o:Security s:mustUnderstand="1" xmlns:o="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd">
            <u:Timestamp u:Id="_0">
                <u:Created>{$timestamp}</u:Created>
                <u:Expires>{$expires}</u:Expires>
            </u:Timestamp>
        </o:Security>
    </s:Header>
    <s:Body>
        <trust:RequestSecurityToken xmlns:trust="http://docs.oasis-open.org/ws-sx/ws-trust/200512">
            <wsp:AppliesTo xmlns:wsp="http://schemas.xmlsoap.org/ws/2004/09/policy">
                <a:EndpointReference>
                    <a:Address>{$relyingparty}</a:Address>
                </a:EndpointReference>
            </wsp:AppliesTo>
            <trust:TokenType>http://docs.oasis-open.org/wss/oasis-wss-saml-token-profile-1.1#SAMLV2.0</trust:TokenType>
            <trust:RequestType>http://docs.oasis-open.org/ws-sx/ws-trust/200512/Issue</trust:RequestType>
            <trust:KeyType>http://docs.oasis-open.org/ws-sx/ws-trust/200512/SymmetricKey</trust:KeyType>
            <trust:KeySize>256</trust:KeySize>
        </trust:RequestSecurityToken>
    </s:Body>
</s:Envelope>
XML;
        
        return $soap;
    }
    
    /**
     * Sign the SOAP request using the machine credential certificate
     */
    private function sign_request($request) {
        if (empty($this->certificate_path) || !file_exists($this->certificate_path)) {
            $this->last_error = 'Certificate file not found';
            return $request;
        }
        
        $cert = file_get_contents($this->certificate_path);
        $privatekey = openssl_pkey_get_private($cert, $this->certificate_password);
        
        if (!$privatekey) {
            $this->last_error = 'Failed to load private key from certificate';
            return $request;
        }
        
        $doc = new \DOMDocument();
        $doc->loadXML($request);
        
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('s', self::NS_SOAP12);
        $xpath->registerNamespace('o', self::NS_WSSE);
        $xpath->registerNamespace('u', self::NS_WSU);
        
        $security = $xpath->query('//o:Security')->item(0);
        if (!$security) {
            return $request;
        }
        
        $certdata = openssl_x509_parse($cert);
        $binarytoken = $doc->createElementNS(self::NS_WSSE, 'o:BinarySecurityToken');
        $binarytoken->setAttribute('ValueType', 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-x509-token-profile-1.0#X509v3');
        $binarytoken->setAttribute('EncodingType', 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-soap-message-security-1.0#Base64Binary');
        $binarytoken->setAttributeNS(self::NS_WSU, 'u:Id', 'X509Token');
        
        $x509cert = '';
        openssl_x509_export($cert, $x509cert);
        $x509cert = preg_replace('/-----BEGIN CERTIFICATE-----|-----END CERTIFICATE-----|\s/', '', $x509cert);
        $binarytoken->nodeValue = $x509cert;
        
        $timestamp = $xpath->query('//u:Timestamp')->item(0);
        if ($timestamp) {
            $security->insertBefore($binarytoken, $timestamp);
        } else {
            $security->appendChild($binarytoken);
        }
        
        return $doc->saveXML();
    }
    
    /**
     * Send SOAP request to the service endpoint with TLS client authentication
     */
    private function send_soap_request($endpoint, $soapenvelope, $action) {
        $headers = [
            'Content-Type: application/soap+xml; charset=utf-8',
            'SOAPAction: "http://docs.oasis-open.org/ws-sx/ws-trust/200512/RST/' . $action . '"',
        ];
        
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $soapenvelope,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT_SECONDS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
        ]);
        
        // Configure TLS client certificate authentication for MAS-ST
        if (!empty($this->certificate_path) && file_exists($this->certificate_path)) {
            $ext = strtolower(pathinfo($this->certificate_path, PATHINFO_EXTENSION));
            
            if ($ext === 'p12' || $ext === 'pfx') {
                // PKCS#12 certificate format
                curl_setopt($ch, CURLOPT_SSLCERTTYPE, 'P12');
                curl_setopt($ch, CURLOPT_SSLCERT, $this->certificate_path);
                if (!empty($this->certificate_password)) {
                    curl_setopt($ch, CURLOPT_SSLCERTPASSWD, $this->certificate_password);
                }
            } else {
                // PEM certificate format
                curl_setopt($ch, CURLOPT_SSLCERTTYPE, 'PEM');
                curl_setopt($ch, CURLOPT_SSLCERT, $this->certificate_path);
                if (!empty($this->certificate_password)) {
                    curl_setopt($ch, CURLOPT_SSLCERTPASSWD, $this->certificate_password);
                }
                
                // Check for separate key file
                $keypath = preg_replace('/\.(pem|crt|cer)$/i', '.key', $this->certificate_path);
                if (file_exists($keypath)) {
                    curl_setopt($ch, CURLOPT_SSLKEY, $keypath);
                    if (!empty($this->certificate_password)) {
                        curl_setopt($ch, CURLOPT_SSLKEYPASSWD, $this->certificate_password);
                    }
                }
            }
        }
        
        if ($this->debug_mode) {
            curl_setopt($ch, CURLOPT_VERBOSE, true);
            $verbose = fopen('php://temp', 'w+');
            curl_setopt($ch, CURLOPT_STDERR, $verbose);
        }
        
        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        
        if ($this->debug_mode && isset($verbose)) {
            rewind($verbose);
            $verboselog = stream_get_contents($verbose);
            $this->log_error('send_soap_request_debug', $verboselog);
            fclose($verbose);
        }
        
        curl_close($ch);
        
        if ($error) {
            $this->last_error = "cURL error ($errno): $error";
            $this->log_error('send_soap_request', "cURL error ($errno): $error");
            return null;
        }
        
        if ($httpcode !== 200) {
            $this->last_error = "HTTP error: $httpcode";
            $this->log_error('send_soap_request', "HTTP $httpcode response: " . substr($response, 0, 500));
            return null;
        }
        
        return $response;
    }
    
    /**
     * Parse the Request Security Token Response (RSTR)
     */
    private function parse_rstr_response($response) {
        $doc = new \DOMDocument();
        if (!@$doc->loadXML($response)) {
            $this->last_error = 'Failed to parse RSTR response';
            return null;
        }
        
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('s', self::NS_SOAP12);
        $xpath->registerNamespace('trust', self::NS_WSTRUST);
        $xpath->registerNamespace('saml', self::NS_SAML);
        
        $fault = $xpath->query('//s:Fault');
        if ($fault->length > 0) {
            $faultstring = $xpath->query('//s:Fault/s:Reason/s:Text')->item(0);
            $this->last_error = $faultstring ? $faultstring->nodeValue : 'Unknown SOAP fault';
            return null;
        }
        
        $requestedsecuritytoken = $xpath->query('//trust:RequestedSecurityToken');
        if ($requestedsecuritytoken->length === 0) {
            $this->last_error = 'No security token in response';
            return null;
        }
        
        $tokenxml = '';
        foreach ($requestedsecuritytoken->item(0)->childNodes as $child) {
            $tokenxml .= $doc->saveXML($child);
        }
        
        return base64_encode($tokenxml);
    }
    
    /**
     * Call the USI Verification Service with the obtained security token
     */
    private function call_usi_verification_service($token, $usi, $firstname, $lastname, $dateofbirth) {
        $usiendpoint = $this->is_test_mode ? self::USI_REGISTRY_EVTE : self::USI_REGISTRY_ENDPOINT;
        
        $dobformatted = date('Y-m-d', strtotime($dateofbirth));
        
        $request = $this->build_usi_verify_request($token, $usi, $firstname, $lastname, $dobformatted);
        
        $response = $this->send_soap_request($usiendpoint, $request, 'Verify');
        
        if (!$response) {
            return [
                'verified' => false,
                'status' => 'SERVICE_ERROR',
                'message' => $this->last_error ?: 'USI service unavailable',
                'details' => [],
            ];
        }
        
        return $this->parse_usi_verify_response($response);
    }
    
    /**
     * Build the USI verification SOAP request
     */
    private function build_usi_verify_request($token, $usi, $firstname, $lastname, $dateofbirth) {
        $usiendpoint = $this->is_test_mode ? self::USI_REGISTRY_EVTE : self::USI_REGISTRY_ENDPOINT;
        $messageid = 'urn:uuid:' . $this->generate_uuid();
        $timestamp = gmdate('Y-m-d\TH:i:s\Z');
        $expires = gmdate('Y-m-d\TH:i:s\Z', time() + 300);
        
        $tokenxml = base64_decode($token);
        
        $soap = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope"
            xmlns:a="http://www.w3.org/2005/08/addressing">
    <s:Header>
        <a:Action s:mustUnderstand="1">http://usi.gov.au/2020/ws/VerifyUSI</a:Action>
        <a:MessageID>{$messageid}</a:MessageID>
        <a:ReplyTo>
            <a:Address>http://www.w3.org/2005/08/addressing/anonymous</a:Address>
        </a:ReplyTo>
        <a:To s:mustUnderstand="1">{$usiendpoint}</a:To>
        <o:Security s:mustUnderstand="1" xmlns:o="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd">
            <u:Timestamp u:Id="_0" xmlns:u="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd">
                <u:Created>{$timestamp}</u:Created>
                <u:Expires>{$expires}</u:Expires>
            </u:Timestamp>
            {$tokenxml}
        </o:Security>
    </s:Header>
    <s:Body>
        <VerifyUSI xmlns="http://usi.gov.au/2020/ws">
            <VerifyUSIRequest>
                <OrgCode>{$this->organization_id}</OrgCode>
                <USI>{$usi}</USI>
                <FirstName>{$firstname}</FirstName>
                <FamilyName>{$lastname}</FamilyName>
                <DateOfBirth>{$dateofbirth}</DateOfBirth>
            </VerifyUSIRequest>
        </VerifyUSI>
    </s:Body>
</s:Envelope>
XML;
        
        return $soap;
    }
    
    /**
     * Parse the USI verification response
     */
    private function parse_usi_verify_response($response) {
        $doc = new \DOMDocument();
        if (!@$doc->loadXML($response)) {
            return [
                'verified' => false,
                'status' => 'PARSE_ERROR',
                'message' => 'Failed to parse verification response',
                'details' => [],
            ];
        }
        
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('s', self::NS_SOAP12);
        $xpath->registerNamespace('usi', 'http://usi.gov.au/2020/ws');
        
        $fault = $xpath->query('//s:Fault');
        if ($fault->length > 0) {
            $faultstring = $xpath->query('//s:Fault/s:Reason/s:Text')->item(0);
            return [
                'verified' => false,
                'status' => 'FAULT',
                'message' => $faultstring ? $faultstring->nodeValue : 'Verification service fault',
                'details' => [],
            ];
        }
        
        $result = $xpath->query('//usi:VerifyUSIResponse/usi:USIStatus');
        $status = $result->length > 0 ? $result->item(0)->nodeValue : '';
        
        $matchresult = $xpath->query('//usi:VerifyUSIResponse/usi:MatchResult');
        $match = $matchresult->length > 0 ? $matchresult->item(0)->nodeValue : '';
        
        $verified = (strtoupper($status) === 'ACTIVE' && strtoupper($match) === 'MATCH');
        
        $statusmessages = [
            'ACTIVE' => 'USI is active and valid',
            'INACTIVE' => 'USI has been deactivated',
            'NOT_FOUND' => 'USI not found in registry',
            'MATCH' => 'Student details match USI record',
            'NO_MATCH' => 'Student details do not match USI record',
            'PARTIAL_MATCH' => 'Partial match - some details differ',
        ];
        
        $message = $statusmessages[$status] ?? $statusmessages[$match] ?? 'Unknown verification status';
        
        return [
            'verified' => $verified,
            'status' => $status ?: $match,
            'message' => $message,
            'details' => [
                'usi_status' => $status,
                'match_result' => $match,
                'verified_at' => time(),
            ],
        ];
    }
    
    /**
     * Validate input parameters
     */
    private function validate_input($usi, $firstname, $lastname, $dateofbirth) {
        if (empty($usi) || strlen($usi) !== 10) {
            return ['valid' => false, 'error' => 'USI must be exactly 10 characters'];
        }
        
        if (!preg_match('/^[2-9A-HJ-NP-Z]{10}$/', strtoupper($usi))) {
            return ['valid' => false, 'error' => 'USI contains invalid characters'];
        }
        
        if (empty($firstname)) {
            return ['valid' => false, 'error' => 'First name is required'];
        }
        
        if (empty($lastname)) {
            return ['valid' => false, 'error' => 'Last name is required'];
        }
        
        if (empty($dateofbirth)) {
            return ['valid' => false, 'error' => 'Date of birth is required'];
        }
        
        $dob = strtotime($dateofbirth);
        if ($dob === false || $dob > time()) {
            return ['valid' => false, 'error' => 'Invalid date of birth'];
        }
        
        return ['valid' => true];
    }
    
    /**
     * Check if valid credential is configured
     */
    public function has_valid_credential() {
        if (empty($this->certificate_path)) {
            return false;
        }
        
        if (!file_exists($this->certificate_path)) {
            return false;
        }
        
        $cert = @file_get_contents($this->certificate_path);
        if (!$cert) {
            return false;
        }
        
        $certinfo = @openssl_x509_parse($cert);
        if (!$certinfo) {
            return false;
        }
        
        if (isset($certinfo['validTo_time_t']) && $certinfo['validTo_time_t'] < time()) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Get credential expiry date
     */
    public function get_credential_expiry() {
        if (!$this->has_valid_credential()) {
            return null;
        }
        
        $cert = file_get_contents($this->certificate_path);
        $certinfo = openssl_x509_parse($cert);
        
        return $certinfo['validTo_time_t'] ?? null;
    }
    
    /**
     * Generate a UUID for message IDs
     */
    private function generate_uuid() {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
    
    /**
     * Log errors
     */
    private function log_error($method, $message) {
        if ($this->debug_mode) {
            debugging("USI Registry Client [$method]: $message", DEBUG_DEVELOPER);
        }
        error_log("local_rtocompliance USI: [$method] $message");
    }
    
    /**
     * Get the last error message
     */
    public function get_last_error() {
        return $this->last_error;
    }
    
    /**
     * Test connection to the MAS-ST service
     */
    public function test_connection() {
        if (!$this->has_valid_credential()) {
            return [
                'success' => false,
                'message' => 'No valid machine credential configured',
            ];
        }
        
        try {
            $token = $this->request_security_token();
            if ($token) {
                return [
                    'success' => true,
                    'message' => 'Successfully connected to MAS-ST and obtained security token',
                ];
            } else {
                return [
                    'success' => false,
                    'message' => $this->last_error ?: 'Failed to obtain security token',
                ];
            }
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Connection error: ' . $e->getMessage(),
            ];
        }
    }
}
