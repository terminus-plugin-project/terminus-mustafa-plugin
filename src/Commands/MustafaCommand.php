<?php

namespace Pantheon\TerminusMustafa\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Exceptions\TerminusNotFoundException;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;
use Pantheon\Terminus\Collections\Sites;
use Pantheon\Terminus\Exceptions\TerminusException;
use Aws\CloudFront\CloudFrontClient;
use Symfony\Component\Console\Question\ChoiceQuestion;

/**
 * Class MustafaCommand
 * Sets a Pantheon site up with a CDN
 */
class MustafaCommand extends TerminusCommand implements SiteAwareInterface {

  use SiteAwareTrait;

  /** @var  \Pantheon\Terminus\Models\Site $site */
  private $site;
  /** @var  \Pantheon\Terminus\Models\Environment $env */
  private $env;

  private $plan_options = [
    'basic' => 'Personal',
    'pro' => 'Pro',
    'business' => 'Business',
  ];

  /**
   * Sets a site up with a CDN
   *
   * @authorize
   *
   * @command site:mustafa
   * @aliases mustafa
   *
   * @param string $site_env Site & environment in the format `site-name.env`
   * @param string $provider CDN provider to use
   */
  public function mustafa($site_env, $provider) {
    if (empty($site_env)) {
      $message = "Usage: terminus site:mustafa|mustafa <site-name.env> <provider>";
      throw new TerminusNotFoundException($message);
    }

    // Determine site info.
    /** @var \Pantheon\Terminus\Models\Site $site */
    /** @var \Pantheon\Terminus\Models\Environment $env */
    list($site, $env) = $this->getSiteEnv($site_env);
    $this->site = $site;
    $this->env = $env;

    $payment_level = $site->get('service_level');

    if ($payment_level == "free") {
      $use_bo = $this->io()->confirm("Do you want to invite a business owner to pay for the site?");
      if ($use_bo) {
        $this->inviteBusinessOwner();
        $this->log()->notice("Once a business owner has paid for the site, you'll be able to run this command again to set up your CDN.");
        return;
      }

      $owner = $site->get('owner');
      $current_user = $this->getUser();

      if ($owner == $current_user->id) {
        $payment_methods = $current_user->getPaymentMethods()->fetch();

        if (count($payment_methods->serialize()) < 1) {
          $this->log()->error('In order to have a CDN to your site it needs to be on a paid plan.  You currently have no payment methods assicated with your account.  Please visit the Pantheon dashboard and add a payment method before proceeding.');
        }

        // Payment methods are available, let's figure out if we can promote this site.
        $payment_method_options = [];
        foreach ($payment_methods->serialize() as $pm) {
          $payment_method_options[] = $pm['label'];
        }

        $payment_method_question = new ChoiceQuestion(
          'In order to add a CDN to your site it needs to be on a paid plan. Please select one of your existing payment methods to pay for this site.',
          array_values($payment_method_options)
        );
        $plan_pm = $this->io()->askQuestion($payment_method_question);
        $payment_method = $payment_methods->get($plan_pm);
        $workflow = $site->addPaymentMethod($payment_method->id);
        while (!$workflow->checkProgress()) {
          // @TODO: Add Symfony progress bar to indicate that something is happening.
        }

        $plan_question = new ChoiceQuestion(
          'In order to add a CDN to your site it needs to be on a paid plan. Please select your desired plan level.',
          array_values($this->plan_options)
        );
        $plan = $this->io()->askQuestion($plan_question);
        $plan_options = array_flip($this->plan_options);
        $workflow = $site->updateServiceLevel($plan_options[$plan]);
        while (!$workflow->checkProgress()) {
          // @TODO: Add Symfony progress bar to indicate that something is happening.
        }
      }
      else {
        $this->log()->error('Only paid sites can have CDNs added to them. Please invite a business owner to pay for the site or have the site owner pay for the site.');
        return;
      }
    }

    // At this point we have a paid site and should be able to add domains to it.
    $domains = $env->getDomains()->fetch();
    $domain_names = [];
    foreach ($domains->serialize() as $domain) {
      $domain_names[] = [
        $domain['domain'],
      ];
    }
    $this->io()->text("You currently have the following domains associated with your site's environment.");
    $this->io()->table(['Domain Name'], $domain_names);

    $default = count($domains->serialize() >= 1);
    while ($this->io()->confirm('Would you like to add another domain?', $default)) {
      $new_domain = $this->io()->ask('What is the new domain you would like to add?');
      $domains->create($new_domain);
      $default = FALSE;
    }

    // Finally, get the domains again so we have them when configuring the CDN.
    $domains = $env->getDomains()->fetch()->serialize();
    $domain_names = [];
    foreach ($domains as $domain) {
      if (is_null($domain['dns_zone_name'])) {
        $domain_names[] = $domain['domain'];
      }
    }

    // We now have all of the domains added, we need to integrate with the CDN.
    switch ($provider) {

      case 'aws':
        $cloudfront = new CloudFrontClient([
          'version' => 'latest',
          'region' => 'us-east-1',
        ]);

        $config = [
          'DistributionConfig' => [
            'Aliases' => [
              'Items' => $domain_names,
              'Quantity' => count($domain_names),
            ],
            'CacheBehaviors' => [
              'Items' => [
                0 => [
                  'AllowedMethods' => [
                    'CachedMethods' => [
                      'Items' => [
                        'GET',
                        'HEAD',
                      ],
                      'Quantity' => 2,
                    ],
                    'Items' => [
                      'GET',
                      'HEAD',
                    ],
                    'Quantity' => 2,
                  ],
                  'Compress' => TRUE,
                  'ForwardedValues' => [
                    'Cookies' => [
                      'Forward' => 'all',
                    ],
                    'QueryString' => true,
                  ],
                  'MinTTL' => 0,
                  'PathPattern' => '/',
                  'TargetOriginId' => $env->id,
                  'TrustedSigners' => [
                    'Enabled' => FALSE,
                    'Quantity' => 0,
                  ],
                  'ViewerProtocolPolicy' => 'redirect-to-https',
                ],
              ],
              'Quantity' => 1,
            ],
            'CallerReference' => microtime(TRUE),
            'Comment' => 'Created by Terminus Mustafa Plugin',
            'DefaultCacheBehavior' => [
              'AllowedMethods' => [
                'CachedMethods' => [
                  'Items' => [
                    'GET',
                    'HEAD',
                  ],
                  'Quantity' => 2,
                ],
                'Items' => [
                  'GET',
                  'HEAD',
                ],
                'Quantity' => 2,
              ],
              'ForwardedValues' => [
                'Cookies' => [
                  'Forward' => 'all',
                ],
                'QueryString' => FALSE,
              ],
              'MinTTL' => 0,
              'TargetOriginId' => $env->id,
              'TrustedSigners' => [
                'Enabled' => FALSE,
                'Quantity' => 0,
              ],
              'ViewerProtocolPolicy' => 'redirect-to-https',
            ],
            'Enabled' => TRUE,
            'HttpVersion' => 'http2',
            'IsIPV6Enabled' => TRUE,
            'Origins' => [
              'Items' => [
                0 => [
                  'DomainName' => $env->domain(),
                  'Id' => $env->id,
                  'CustomOriginConfig' => [
                    'HTTPPort' => 80,
                    'HTTPSPort' => 443,
                    'OriginProtocolPolicy' => 'https-only',
                    'OriginSslProtocols' => [
                      'Items' => [
                        'tls1.2',
                        'tls1.1',
                      ],
                      'Quantity' => 2,
                    ],
                  ],
                ],
              ],
              'Quantity' => 1,
            ],
            'PriceClass' => 'PriceClass_All',
          ],
        ];

        $distribution = $cloudfront->createDistribution($config);



        break;

      case 'cloudflare':

        break;

      default:

        break;
    }

  }

  private function inviteBusinessOwner() {
    $site = $this->site;

    $bo_email_address = $this->ask("What is the email address of the business owner who should pay for this site?");

    $plan_question = new ChoiceQuestion(
      'Please select the plan level.',
      array_values($this->plan_options)
    );
    $plan = $this->io()->askQuestion($plan_question);

    $plan_options = array_flip($this->plan_options);

    $site->getWorkflows()->create('invite_to_pay', ['site' => $site->id, 'params' => [
      'email' => $bo_email_address,
      'service_level' => $plan_options[$plan],
      'invited_by' => '6a264990-6ae9-4105-bc28-f0b90faf408b',
      'invited_by_email' => 'admin@checkthelog.com',
      'invited_by_name' => 'Brian T',
      'invited_by_gravatar' => '',
    ]]);

    var_dump($bo_email_address);
    var_dump($plan_options[$plan]);
  }

}