<?php

namespace ClaimBot\Commands;

use ClaimBot\Messenger\Donation;
use GovTalk\GiftAid\AuthorisedOfficial;
use GovTalk\GiftAid\ClaimingOrganisation;
use GovTalk\GiftAid\GiftAid;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @todo Review in full! This is just a placeholder with hard-coded sample data for now.
 */
class ClaimCommand extends Command
{
    protected static $defaultName = 'claimbot:claim';
    protected ?LoggerInterface $logger = null;

    public function __construct(LoggerInterface $logger = null)
    {
        parent::__construct(static::$defaultName);

        if (!$logger) {
            $logger = new NullLogger();
        }

        $this->logger = $logger;
    }

    public function run(InputInterface $input, OutputInterface $output): void
    {
        $sampleDonation = new Donation();
        $sampleDonation->donation_date = '2021-09-10';
        $sampleDonation->title = 'Ms';
        $sampleDonation->first_name = 'Mary';
        $sampleDonation->last_name = 'Magdalene';
        $sampleDonation->house_no = '1a';
        $sampleDonation->postcode = 'N1 1AA';
        $sampleDonation->amount = 123.45;
        $sampleDonation->org_hmrc_ref = 'AB12345';

        /**
         * Password must be a govt gateway one in plain text. MD5 was supported before but retired.
         * @link https://www.gov.uk/government/publications/transaction-engine-document-submission-protocol
         */
        $ga = new GiftAid(
            getenv('MAIN_GATEWAY_SENDER_ID'),
            getenv('MAIN_GATEWAY_SENDER_PASSWORD'), // The charity's own Govt Gateway user ID + password? OR switch to multi-claim
            'https://github.com/thebiggive/claimbot',
            'The Big Give ClaimBot',
            getenv('APP_VERSION'),
            getenv('APP_ENV') !== 'production',
            null,
//            'http://host.docker.internal:5665/LTS/LTSPostServlet' // Uncomment to use LTS rather than ETS.
        );
        $ga->setLogger($this->logger);
        $ga->setVendorId(getenv('VENDOR_ID'));

        // Not auth'd with ETS (for now).
//        $ga->setAgentDetails(
//            '11111222223333',
//            'Agent Company',
//            [
//                'line' => ['Line 1', 'Line 2'],
//                'country' => 'United Kingdom',
//            ],
//            null,
//            'myAgentRef',
//        );

        // ETS returns an error if you set a GatewayTimestamp – can only use this for LTS.
//        $ga->setTimestamp(new \DateTime());

        $skipCompression = (bool) (getenv('SKIP_PAYLOAD_COMPRESSION') ?? false);
        $ga->setCompress(!$skipCompression);

        $ga->setClaimToDate($sampleDonation->donation_date); // date of most recent donation

        $officer = new AuthorisedOfficial(
            null,
            'Bob',
            'Smith',
            '01234 567890',
            'AB12 3CD',
        );

        $claimant = new ClaimingOrganisation(
            'A Fundraising Organisation',
            'AB12345', // The charity's own ID?
            'CCEW',
            '123456',
        );

        $ga->setAuthorisedOfficial($officer);
        $ga->addClaimingOrganisation($claimant);

        $response = $ga->giftAidSubmit([(array)$sampleDonation]);

        if (!empty($response['correlationid'])) {
            $output->writeln('Success!');
        } else if (!empty($response['errors'])) {
            $output->writeln('Errors!');

            // $response['errors'] is a 3D array:
            // top level keys: 'fatal', 'recoverable', 'business', 'warning'.
            // 2nd level when 'business' errors encountered was numeric-indexed starting at 1.
            // 3rd level inside 'business' error items had keys 'number', 'text' and 'location' – where 'text' was
            //   a human-readable, helpful error message and 'location' an XPath locator. Not sure what 'number' means.
            $output->write(print_r($response['errors'], true));
        } else {
            $output->writeln('Neither correlation ID *nor* errors(!) – is the endpoint valid?');
        }
    }
}
