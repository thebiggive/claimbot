<?php

namespace ClaimBot\Messenger;

/**
 * One possibility is we publish this whole app and then load this class into MatchBot, to determine
 * the format for SQS messages to be serialised when publishing. TODO think more about this.
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
}
