<?php

namespace Fleetbase\Storefront\Mail;

use Fleetbase\Storefront\Models\Network;
use Fleetbase\Models\Invite;
use Fleetbase\Models\User;
use Fleetbase\FleetOps\Support\Utils;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class StorefrontNetworkInvite extends Mailable
{
    use Queueable, SerializesModels;

    public Invite $invite;
    public Network $network;
    public User $sender;
    public string $url;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct(Invite $invite)
    {
        $this->invite = $invite;
        $this->network = $this->invite->subject;
        $this->sender = $this->invite->createdBy;
        $this->url = Utils::consoleUrl('join/network/' . $this->invite->uri);
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this
            ->subject('You have been invited to join ' . $this->invite->subject->name . '!')
            ->from('hello@fleetbase.io', $this->invite->subject->name)
            ->to($this->invite->recipients ?? [])
            ->markdown('emails.storefront-network-invite');
    }
}
