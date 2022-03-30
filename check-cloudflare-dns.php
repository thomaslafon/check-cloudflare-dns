<?php
/*****
* for any information please send an email to thomas.lafon@acquia.com
* Installation instructions
* 1. checkout the project
* 2. run "composer install"
* 3. copy .creds.yml.example to .creds.yml
* 4. copy config.yml.example to config.yml
* 5. setup Acquia Cloud API v2 credentials in .creds.yml
* 6. setup email addresses and configuration in config.yml
* 7. setup a cronjob for the script to be executed regularly
*/ 

ini_set('display_errors',1);
error_reporting(E_ALL);

require __DIR__ . '/vendor/autoload.php';

use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use GuzzleHttp\Client;
use Symfony\Component\Yaml\Yaml;

// Config File
$config_file = __DIR__ . DIRECTORY_SEPARATOR . 'config.yml';
if (file_exists($config_file)) {
  $config_values = Yaml::parseFile($config_file);
  
  $debug_only = $config_values["debug_only"];
  $emails = $config_values["emails"];
  $patterns_to_ignore = $config_values["patterns_to_ignore"];
  $patterns_to_check = $config_values["patterns_to_check"];
}
else {
  print("config.yml config file doesn't exist. Please rename config.yml.example to config.yml and setup values if needed.");
  exit(1);
}


// Finding Method
// 1. TXT file
// 2. Acquia Cloud environment
if (!isset($_SERVER["AH_SITE_NAME"])) { // 1. Not in an Acquia Env, assuming TXT file domains.txt
    echo "NOT in an Acquia Environment, assuming TXT file\n\n";

    if(file_exists("./domains.txt")) {
        $domains = file("domains.txt", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    } else if(isset($argv[1]) && file_exists($argv[1])) {
        $domains = file($argv[1], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    } else {
        echo "You must use a 'domains.txt' file containing all the domains you want to check, or add a custom file as first argument: check-cloudflare-dns.php myfile.txt\n\n";
        exit(1);
    }
} else { // 2. In an Acquia env

    // Getting config
    $docroot_name = $_SERVER["AH_SITE_NAME"] . "." . $_SERVER["AH_SITE_ENVIRONMENT"];

    echo "Getting Configuration...\n";
    $creds_file = __DIR__ . DIRECTORY_SEPARATOR . '.creds.yml';
    if (file_exists($creds_file)) {
      $creds_values = Yaml::parseFile($creds_file);
      
      $ac_key = $creds_values["acquia_api_v2"]["key_id"];
      $ac_sec = $creds_values["acquia_api_v2"]["secret"];
    }
    else {
      print(".creds.yml config file doesn't exist. Please rename .creds.yml.example to .creds.yml and setup values");
      exit(1);
    }

    // Checks before executing script
    // 1. We need an Acquia Cloud application_id
    // We can retrieve it from $_SERVER["AH_APPLICATION_UUID"]
    if (isset($_SERVER["AH_APPLICATION_UUID"])) {
        $application_id = $_SERVER["AH_APPLICATION_UUID"];
    }
    elseif (isset($config_values["application_id"])) {
        $application_id = $config_values["application_id"];
    }
    else {
        exit("No Acquia Cloud application_id found or defined! Exiting...");
    }


    // Init Class
    $app = new AcquiaCloudApiV2($ac_key, $ac_sec);

    // Parsing environments
    echo "Parsing Environments\n";
    echo "Retrieving domains for " . $_SERVER["AH_SITE_NAME"] . "." . $_SERVER["AH_SITE_ENVIRONMENT"] . "\n";
    $environments = $app->get_acquia_environments($application_id);

    foreach ($environments as $environment) {

        $environment_ah_id = $environment[0];
        $environment_ah_site = $environment[1];
        $environment_domains = $environment[4];

        //echo $environment_ah_site ." / ". $_SERVER["AH_SITE_ENVIRONMENT"];

        if ($environment_ah_site == $_SERVER["AH_SITE_ENVIRONMENT"]) {
            $domains = $environment_domains;
        }
    }

    if (!isset($domains)) {
        exit("Script was not able to retrieve domains. Exiting...");
    }
}


// Build an aray of domains_to_check, forcing only-include ones, and removing excluded pattern
$domains_to_check = array();
foreach ($domains as $domain) {

    if(isset($patterns_to_check)) {
        $isdomain_included = 0;
        foreach ($patterns_to_check as $pattern) {
            if (strpos($domain, $pattern) !== false) {
                $isdomain_included = 1;
            }
        }
        #echo $isdomain_included == 1 ? "included\n" : "not included\n";
    } else {
        $isdomain_included = 1;
    }

    if(isset($patterns_to_ignore)) {
        $isdomain_excluded = 0;
        foreach ($patterns_to_ignore as $pattern) {
            if (strpos($domain, $pattern) !== false) {
                $isdomain_excluded = 1;
            }
        }
        #echo $isdomain_excluded == 1 ? "excluded\n" : "not excluded\n";
    } else {
        $isdomain_excluded = 0;
    }

    if($isdomain_included && !$isdomain_excluded) {
        $domains_to_check[] = $domain;
    }

}

$zones_cname_ok = array();
$zones_cname_nok = array();
$zones_cname_nok_nocname = array();
$zones_baredomain_ok = array();
$zones_baredomain_nok = array();
$zones_baredomain_nok_nocf = array();

foreach ($domains_to_check as $domain) {

    $total_domains[] = $domain;
    //echo "* Checking: " . $domain . "\n";

    if (TlTools::isBareDomain($domain)) { // is bare domain
        //echo "$domain\n";
        $bd_ips = array();
        $cf_ips = array();
        $DNS_A_domain = dns_get_record($domain, DNS_A);
        foreach ($DNS_A_domain as &$value) {
            $bd_ips[] = $value["ip"];
        }
        $DNS_A_cf = dns_get_record($domain.".cdn.cloudflare.net", DNS_A);
        if (count($DNS_A_cf)>0) {
            foreach ($DNS_A_cf as &$value) {
                $cf_ips[] = $value["ip"];
            }

            //var_dump($bd_ips,$cf_ips);
            $check_ip_difference = array_diff($cf_ips, $bd_ips);

            if (empty($check_ip_difference)) {
                $zones_baredomain_ok[$domain] = "Points correctly to Cloudflare IPs";
            } else {
                $zones_baredomain_nok[$domain] = "Should have A records to ".implode(',', $cf_ips). "\n  It's currently pointing to ".implode(',', $bd_ips);
            }
        } else {
           $zones_baredomain_nok_nocf[$domain] = "Looks like Cloudflare has no IP adresses on $domain".".cdn.cloudflare.net";
        }
    } 
    else { // is not bare domain
        $DNS_CNAME = dns_get_record($domain, DNS_CNAME);
        if (isset($DNS_CNAME) && empty($DNS_CNAME)) {
            $zones_cname_nok_nocname[$domain] = "doesn't have CNAME yet";
        }
        elseif (isset($DNS_CNAME[0]["target"]) && $DNS_CNAME[0]["target"] == $domain.'.cdn.cloudflare.net') {
            $zones_cname_ok[$domain] = "Points correctly to $domain"."cdn.cloudflare.net";
        } else {
            $zones_cname_nok[$domain] = "should have a CNAME to $domain".".cdn.cloudflare.net";
        }
    }

}

$email_body = "Hi everyone,\n\nPlease find current Cloudflare DNS configuration\n";

// INCORRECT CONF
$email_body .= "\n************ INCORRECT CONFIGURATION ************\n";

if(count($zones_baredomain_nok)) {
    $email_body .= "\n### All ZONES (baredomain) that DOESN'T have a correct configuration:\n";
    foreach ($zones_baredomain_nok as $domain => $msg) {
        $email_body .= "* $domain $msg\n";
    }
}

if(count($zones_baredomain_nok_nocf)) {
    $email_body .= "\n### All ZONES (baredomain) that DOESN'T have a Cloudflare conf yet:\n";
    foreach ($zones_baredomain_nok_nocf as $domain => $msg) {
        $email_body .= "* $domain $msg\n";
    }
}

if(count($zones_cname_nok)) {
    $email_body .= "\n### All ZONES (non-baredomain) that DOESN'T have a correct configuration:\n";
    foreach ($zones_cname_nok as $domain => $msg) {
        $email_body .= "* $domain $msg\n";
    }
}

if(count($zones_cname_nok_nocname)) {
    $email_body .= "\n### All ZONES (non-baredomain) that DOESN'T have a CNAME yet:\n";
    foreach ($zones_cname_nok_nocname as $domain => $msg) {
        $email_body .= "* $domain $msg\n";
    }
}

// CORRECT CONF
$email_body .= "\n************ CORRECT CONFIGURATION ************\n";

if(count($zones_baredomain_ok)) {
    $email_body .= "\n### All ZONES (baredomain) that DOES have a correct configuration:\n";
    foreach ($zones_baredomain_ok as $domain => $msg) {
        $email_body .= "* $domain $msg\n";
    }
}

if(count($zones_cname_ok)) {
    $email_body .= "\n### All ZONES (non-baredomain) that DOES have a correct configuration:\n";
    foreach ($zones_cname_ok as $domain => $msg) {
        $email_body .= "* $domain $msg\n";
    }
}


// SEND EMAIL
$email_to = implode(',', $emails);
if (isset($_SERVER["AH_SITE_NAME"])) {
    $email_subject = "[".$_SERVER["AH_SITE_NAME"] . "." . $_SERVER["AH_SITE_ENVIRONMENT"]."]"." - Cloudflare DNS configuration";
}

if($debug_only) {
    echo "!!! DEBUG ONLY !!! No email will be sent, it's only for debug purposes.\n\n";

    echo "recipients: $email_to\n\n";

    echo "email body: \n\n $email_body";

} else {
    if(mail($email_to, $email_subject, $email_body)) {
        echo "email sent to $email_to";
    } else {
        echo "could not send email";
    }    
}


exit(0);

# 
# Class AcquiaCloudApiV2
# 
class AcquiaCloudApiV2 {
    private $ac_key_id;
    private $ac_secret;
    private $provider;
    private $accessToken;

    public function __construct($ac_key_id,$ac_secret) {
        $this->ac_key_id = $ac_key_id;
        $this->ac_secret = $ac_secret;
        $this->provider = new GenericProvider([
            'clientId'                => $this->ac_key_id,
            'clientSecret'            => $this->ac_secret,
            'urlAuthorize'            => '',
            'urlAccessToken'          => 'https://accounts.acquia.com/api/auth/oauth/token',
            'urlResourceOwnerDetails' => '',
        ]);

        // Try to get an access token using the client credentials grant.
        $this->accessToken = $this->provider->getAccessToken('client_credentials');
    }

    public function get_acquia_environments($application_id) {
        try {

            // Generate a request object using the access token.
            $request = $this->provider->getAuthenticatedRequest(
                'GET',
                'https://cloud.acquia.com/api/applications/'. $application_id .'/environments',
                $this->accessToken
            );

            // Send the request.
            $client = new Client();
            
            $response = $client->send($request);

            $responseBody = $response->getBody();

            $array_total_values = json_decode($responseBody, true);

            foreach($array_total_values["_embedded"]["items"] as &$item) {
                $all_docroots[]=array($item["id"],$item["name"],$item["application"]["name"],$item["application"]["uuid"],$item["domains"]);
            }
            return $all_docroots;

        } catch (IdentityProviderException $e) {
            // Failed to get the access token.
            exit($e->getMessage());
        }
    }

    public function get_acquia_domains($env_id) {
        $provider = new GenericProvider([
            'clientId'                => $clientId,
            'clientSecret'            => $clientSecret,
            'urlAuthorize'            => '',
            'urlAccessToken'          => 'https://accounts.acquia.com/api/auth/oauth/token',
            'urlResourceOwnerDetails' => '',
        ]);

        try {

            // Generate a request object using the access token.
            $request = $provider->getAuthenticatedRequest(
                'GET',
                //'https://cloud.acquia.com/api/account',
                'https://cloud.acquia.com/api/environments/'. $env_id .'/domains',
                $this->accessToken
            );

            // Send the request.
            $client = new Client();
            $response = $client->send($request);

            $responseBody = $response->getBody();

            $array_total_values = json_decode($responseBody, true);

            print_r($array_total_values["_embedded"]["items"][0]["hostname"]);

            foreach($array_total_values["_embedded"]["items"] as &$item) {
                $all_domains[]=$item["hostname"];
            }
            #var_dump($all_domains);
            return $all_domains;

        } catch (IdentityProviderException $e) {
            // Failed to get the access token.
            exit($e->getMessage());
        }
    }

}

# 
# Class TlTool
# 
class TlTools {

    public static function isBareDomain($domain) {
        $re = '/^(([^.]*)(\.com(\..+)?|\.co(\.[^.]*$)+|\.in(\.[^.]*$)+|\.[^\.]*))$/m';
        preg_match_all($re, $domain, $matches, PREG_SET_ORDER, 0);

        return count($matches) ? 1: 0;
    }

}
