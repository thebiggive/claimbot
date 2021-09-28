<?php

namespace ClaimBot\Messenger;

/**
 * One possibility is we publish this whole app and then load this class into MatchBot, to determine
 * the format for SQS messages to be serialised when publishing. TODO think more about this.
 *
 * TODO how do we pass through which charity it's for? Does that belong in an element 'higher' in the queued message
 * or is it cleaner for every donation to get its own standalone message and to aggregate them inside ClaimBot before
 * pushing to HMRC?
 */
class Donation
{
    /** @var string Donation date, YYYY-MM-DD. */
    public string $donation_date;

    public string $title;
    public string $first_name;
    public string $last_name;
    public string $house_no;
    public string $postcode;
    public bool $overseas = false;
    public float $amount;
    public bool $sponsored = false;
    public ?string $org_hmrc_ref = null;
}
