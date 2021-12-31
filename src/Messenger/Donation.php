<?php

namespace ClaimBot\Messenger;

/**
 * For now, MatchBot essentially has a copy of this model as `MatchBot\Application\Messenger\Donation`.
 * While it remains very simple, the complexity we'd add by having both apps load this class from a shared
 * library dependency doesn't seem worth it.
 */
class Donation
{
    public string $id;

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
    public ?string $org_name = null;
    public ?string $org_hmrc_ref = null;
}
