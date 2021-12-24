<?php

namespace ClaimBot\Commands;

use ClaimBot\Claimer;
use ClaimBot\Exception\ClaimException;
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
 *
 * @todo the command should lock similarly to MatchBot's `LockingCommand`s, but probably
 * doesn't need the same inheritance structure for now in a 1-command app.
 */
class ClaimCommand extends Command
{
    protected static $defaultName = 'claimbot:claim';
    protected ?LoggerInterface $logger = null;

    public function __construct(private Claimer $claimer, private GiftAid $giftAid, LoggerInterface $logger = null)
    {
        parent::__construct(static::$defaultName);

        if (!$logger) {
            $logger = new NullLogger();
        }

        $this->logger = $logger;
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        // Note for overseas donations – house_no is a misnomer and should contain a 'full' address up to 40 chars.
        // No need for country to be part of the request
        // "I can confirm that it is enough to confirm that they live outside of the UK and that the specific country
        // does not need to be provided."
        // We should add docs to this effect in the HMRC lib too.

        $sampleDonation = new Donation();
        $sampleDonation->donation_date = '2021-09-10';
        $sampleDonation->title = 'Ms';
        $sampleDonation->first_name = 'Mary';
        $sampleDonation->last_name = 'Moore';
        $sampleDonation->house_no = '1a';
        $sampleDonation->postcode = 'N1 1AA';
        $sampleDonation->amount = 123.45;
        $sampleDonation->org_hmrc_ref = 'AB12345';

        $sampleDonation2 = clone $sampleDonation;
        $sampleDonation2->org_hmrc_ref = 'CD12346';

        $this->giftAid->setClaimToDate($sampleDonation->donation_date); // date of most recent donation

        $officer = new AuthorisedOfficial(
            null,
            'Bob',
            'Smith',
            '01234 567890',
            'AB12 3CD',
        );

        $claimant = new ClaimingOrganisation(
            'A Fundraising Organisation',
            'AB12345', // The charity's own HMRC reference.
            'CCEW',
            '123456',
        );

        $claimant2 = new ClaimingOrganisation(
            'Another Charity',
            'CD12346',
            'CCNI',
            '654321',
        );

        $this->giftAid->setAuthorisedOfficial($officer);
        $this->giftAid->addClaimingOrganisation($claimant);
        $this->giftAid->addClaimingOrganisation($claimant2);

        // TODO replace dummy calls here – possibly even whole command if messenger built-in CLI consumer works as
        // needed with batches – with ClaimableDonationHandler use.

        try {
            $this->claimer->claim([$sampleDonation, $sampleDonation2]);
        } catch (ClaimException $exception) {
            // TODO rationalise error handling if keeping any version of this.
            $output->writeln($exception->getMessage());

            return 5;
        }

        $output->writeln('Success!');
        return 0;
    }
}
