# Check Cloudflare DNS

Check Cloudflare DNS is a script that checks if all your domains from ACE are correctly pointing to Cloudflare. It sends a report that shows if your domains DNS are correctly configured or not.

Checks if a Bare Domain points to Cloudflare IPs
Checks if a domain points to domain.cdn.cloudflare.net CNAME

## Installation

### composer install
```composer
composer install
```

### Setup credentials and configuration

* copy .creds.yml.example to .creds.yml and set Acquia Cloud V2 credentials
* config.yml.example to config.yml and set at least email addresses to send report to

## Usage

```php
php check-cloudflare-dns.php
```

You can setup a cronjob that run report on a regular basis.

## Contributing
Pull requests are welcome. For major changes, please open an issue first to discuss what you would like to change.

## Contact
For any information please send an email to thomas.lafon@acquia.com