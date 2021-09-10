<?php

namespace ClaimBot\Commands;

use ClaimBot\Messenger\Donation;
use GovTalk\GiftAid\AuthorisedOfficial;
use GovTalk\GiftAid\ClaimingOrganisation;
use GovTalk\GiftAid\GiftAid;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @todo Review in full! This is just a placeholder with hard-coded sample data for now.
 */
class ClaimCommand extends Command
{
    protected static $defaultName = 'claimbot:claim';

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

        /**
         * Password must be a govt gateway one in plain text. MD5 was supported before but retired.
         * @link https://www.gov.uk/government/publications/transaction-engine-document-submission-protocol
         */
        $ga = new GiftAid(
            getenv('MAIN_GATEWAY_SENDER_ID'),
            getenv('MAIN_GATEWAY_SENDER_PASSWORD'),
            'https://github.com/thebiggive/claimbot',
            'The Big Give ClaimBot',
            'v1.0',
            true, // TODO dynamically select test mode
            null,
            'http://host.docker.internal:5665/LTS/LTSPostServlet'
        );

        $officer = new AuthorisedOfficial(
            null,
            'Bob',
            'Smith',
            '01234 567890',
            'AB12 3CD',
        );

        $claimant = new ClaimingOrganisation(
            'A Fundraising Organisation',
            'AB12345',
            'CCEW',
            '123456',
        );

        $ga->setAuthorisedOfficial($officer);
        $ga->setClaimingOrganisation($claimant);

        $response = $ga->giftAidSubmit([(array)$sampleDonation]);

        if (!empty($response['correlationid'])) {
            $output->writeln('Success!');
        } else if (!empty($response['errors'])) {
            $output->writeln('Errors!');
            $output->write($response['errors']);
        } else {
            $output->writeln('Neither correlation ID *nor* errors(!) – is the endpoint valid?');
        }
    }
}
