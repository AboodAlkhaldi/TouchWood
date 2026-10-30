<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Access\Public\Events\CustomerAnonymized;
use Modules\Access\Public\Events\CustomerBlocked;
use Modules\Access\Public\Events\CustomerDeletionCancelled;
use Modules\Access\Public\Events\CustomerDeletionScheduled;
use Modules\Access\Public\Events\CustomerLocaleChanged;
use Modules\Access\Public\Events\MarketingConsentChanged;
use Modules\Ops\Application\Command\PruneWrittenMessages\PruneWrittenMessages;
use Modules\Ops\Application\Command\PruneWrittenMessages\PruneWrittenMessagesHandler;
use Modules\Ops\Application\Command\RemoveSubscriber\RemoveSubscriber;
use Modules\Ops\Application\Command\RemoveSubscriber\RemoveSubscriberHandler;
use Modules\Ops\Application\Command\SendStaffMessage\SendStaffMessage;
use Modules\Ops\Application\Command\SendStaffMessage\SendStaffMessageHandler;
use Modules\Ops\Application\Messages\ProviderFailed;
use Modules\Ops\Application\Newsletter\CustomerSubscriptions;
use Modules\Ops\Application\Newsletter\NewsletterSignUps;
use Modules\Ops\Application\Query\ListSubscribers\ListSubscribers;
use Modules\Ops\Application\Query\ListSubscribers\ListSubscribersHandler;
use Modules\Ops\Domain\Exception\ConfirmationLinkInvalid;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Modules\Ops\Domain\Exception\SubscribeInYourAccount;
use Modules\Ops\Domain\Exception\SubscriberNotFound;
use Modules\Ops\Domain\Model\Subscriber;
use Modules\Ops\Domain\Repository\Subscribers;
use Modules\Ops\Presentation\Http\Resource\WrittenPages;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\StoreDto;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Ops\Support\CapturedEmails;
use Tests\Modules\Ops\Support\CapturedNewsletterList;
use Tests\Modules\Ops\Support\OpsFixtures as Ox;
use Tests\Modules\Ops\Support\WrittenFixtures as Wx;

use function Pest\Laravel\seed;

/*
| The newsletter's lists (ops.md §1.9, §1.3, §6): a guest's double opt-in; customers who may receive
| offers by email, on their home store's list in their language, kept current from Access's events
| (each handled once); the provider's copy moved and removed with them; an anonymized account
| forgotten; unconfirmed sign-ups gone after seven days. The queue is `sync` in tests.
|
| Every helper here is named after this file's subject: a Pest file's functions are global.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    CapturedEmails::install();
    CapturedNewsletterList::install();
});

function newsletterStore(string $code = 'sa'): StoreDto
{
    return app(PlatformApi::class)->store(StoreId::fromString(Fx::storeId($code))) ?? throw new LogicException('No store.');
}

/** The token in the confirmation email's link. */
function newsletterToken(CapturedEmails $emails): string
{
    preg_match('#/newsletter/confirm/([A-Za-z0-9]+)#', $emails->last()->text, $match);

    return $match[1] ?? throw new LogicException('No confirmation link.');
}

/**
 * A confirmed guest row the provider holds, written straight in: since a registered email is refused
 * at the box and its guest rows dropped at registration, only rows from before can share a customer's
 * email - which is what these tests need.
 */
function newsletterGuestRow(string $storeCode, string $email): void
{
    DB::table('ops.subscribers')->insert([
        'id' => strtolower((string) Str::ulid()), 'store_id' => Fx::storeId($storeCode), 'email' => $email, 'locale' => 'en', 'kind' => 'GUEST',
        'customer_id' => null, 'token_hash' => null, 'created_at' => now(), 'confirmed_at' => now(),
        'brevo_email' => $email, 'brevo_locale' => 'en', 'brevo_synced_at' => now(),
    ]);
}

describe('a guest', function () {
    it('signs up, gets a link, and is on the list only once they confirm it', function () {
        app(NewsletterSignUps::class)->subscribe(newsletterStore(), 'ar', '  Guest@Example.TEST ');

        $row = DB::table('ops.subscribers')->first();
        expect($row)->toMatchArray(['email' => 'guest@example.test', 'locale' => 'ar', 'kind' => 'GUEST', 'confirmed_at' => null])
            ->and(CapturedEmails::installed()->to())->toBe(['guest@example.test'])
            ->and(CapturedEmails::installed()->sent[0]->subject)->toContain('أكّد اشتراكك')
            ->and(CapturedNewsletterList::installed()->calls)->toBe([])
            // Only the hash is kept, never the token itself; the sent record names nobody.
            ->and(DB::table('ops.subscribers')->value('token_hash'))->toBe(hash('sha256', newsletterToken(CapturedEmails::installed())))
            ->and(DB::table('ops.sent_messages')->where('kind', 'newsletter_confirmation')->first())->toMatchArray(['customer_id' => null, 'status' => 'SENT', 'channel' => 'EMAIL']);

        app(NewsletterSignUps::class)->confirm(newsletterToken(CapturedEmails::installed()));

        expect(DB::table('ops.subscribers')->first())->toMatchArray(['token_hash' => null, 'brevo_email' => 'guest@example.test', 'brevo_locale' => 'ar'])
            ->and(DB::table('ops.subscribers')->value('confirmed_at'))->not->toBeNull()
            ->and(CapturedNewsletterList::installed()->calls)->toBe(['add guest@example.test '.Fx::storeId('sa').' ar', 'allow guest@example.test'])
            // Used once: the same link again confirms nothing.
            ->and(fn () => app(NewsletterSignUps::class)->confirm(newsletterToken(CapturedEmails::installed())))->toThrow(ConfirmationLinkInvalid::class);
    });

    it('answers the same when the email is on the list already, and sends nothing then', function () {
        app(NewsletterSignUps::class)->subscribe(newsletterStore(), 'en', 'guest@example.test');
        $first = newsletterToken(CapturedEmails::installed());
        // A second sign-up before confirming: a new link, the old one dead.
        app(NewsletterSignUps::class)->subscribe(newsletterStore(), 'en', 'guest@example.test');
        $second = newsletterToken(CapturedEmails::installed());

        expect(fn () => app(NewsletterSignUps::class)->confirm($first))->toThrow(ConfirmationLinkInvalid::class);
        app(NewsletterSignUps::class)->confirm($second);

        app(NewsletterSignUps::class)->subscribe(newsletterStore(), 'en', 'guest@example.test');
        expect(CapturedEmails::installed()->sent)->toHaveCount(2)
            ->and(DB::table('ops.subscribers')->count())->toBe(1);
    });

    it('is refused an email that belongs to a customer account, whatever its case', function () {
        Fx::customer('sara@example.test', 'ae');

        expect(fn () => app(NewsletterSignUps::class)->subscribe(newsletterStore(), 'en', 'SARA@example.test'))->toThrow(SubscribeInYourAccount::class)
            ->and(CapturedEmails::installed()->sent)->toBe([])
            ->and(DB::table('ops.subscribers')->count())->toBe(0);
    });

    it('leaves by the signed link of the confirmation email, once confirmed or not', function () {
        app(NewsletterSignUps::class)->subscribe(newsletterStore(), 'en', 'guest@example.test');
        app(NewsletterSignUps::class)->confirm(newsletterToken(CapturedEmails::installed()));
        $id = (string) DB::table('ops.subscribers')->value('id');

        expect(CapturedEmails::installed()->last()->text)->toContain('/sa/en/newsletter/leave/'.$id.'?signature=');

        app(NewsletterSignUps::class)->unsubscribeGuest($id);
        app(NewsletterSignUps::class)->unsubscribeGuest($id);

        expect(DB::table('ops.subscribers')->count())->toBe(0)
            ->and(CapturedNewsletterList::installed()->calls)->toBe(['add guest@example.test '.Fx::storeId('sa').' en', 'allow guest@example.test', 'remove guest@example.test '.Fx::storeId('sa').' en']);
    });

    it('never takes a customer off by a guest\'s link', function () {
        $sara = Wx::agreeing('sara@example.test');
        event(new MarketingConsentChanged((string) Str::uuid(), $sara, CarbonImmutable::now()));

        app(NewsletterSignUps::class)->unsubscribeGuest((string) DB::table('ops.subscribers')->value('id'));

        expect(DB::table('ops.subscribers')->count())->toBe(1);
    });

    it('refuses what is not an email, and a link older than seven days', function () {
        expect(fn () => app(NewsletterSignUps::class)->subscribe(newsletterStore(), 'en', 'not an email'))->toThrow(InvalidOpsInput::class, 'Invalid email');

        app(NewsletterSignUps::class)->subscribe(newsletterStore(), 'en', 'guest@example.test');
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(7)->addMinute());

        try {
            expect(fn () => app(NewsletterSignUps::class)->confirm(newsletterToken(CapturedEmails::installed())))->toThrow(ConfirmationLinkInvalid::class)
                ->and(fn () => app(NewsletterSignUps::class)->confirm(''))->toThrow(ConfirmationLinkInvalid::class);

            // The nightly removal takes the unconfirmed sign-up; a confirmed one stays.
            Fx::asSystem(fn () => app(PruneWrittenMessagesHandler::class)->handle(new PruneWrittenMessages));
        } finally {
            CarbonImmutable::setTestNow();
        }

        expect(DB::table('ops.subscribers')->count())->toBe(0);
    });

    it('is recorded as failed when the confirmation could not be sent, and may try again', function () {
        CapturedEmails::installed()->fails = true;

        expect(fn () => app(NewsletterSignUps::class)->subscribe(newsletterStore(), 'en', 'guest@example.test'))->toThrow(ProviderFailed::class)
            ->and(DB::table('ops.sent_messages')->value('status'))->toBe('FAILED');
    });
});

describe('a customer', function () {
    it('joins their home store\'s list in their language when they agree, moves with their language, leaves when they withdraw', function () {
        $sara = Wx::agreeing('sara@example.test', 'ae', 'en');
        event(new MarketingConsentChanged((string) Str::uuid(), $sara, CarbonImmutable::now()));

        $ae = Fx::storeId('ae');
        expect(DB::table('ops.subscribers')->first())->toMatchArray(['customer_id' => $sara, 'store_id' => $ae, 'locale' => 'en', 'kind' => 'CUSTOMER'])
            ->and(CapturedNewsletterList::installed()->calls)->toBe(["add sara@example.test {$ae} en", 'allow sara@example.test']);

        Wx::set($sara, ['locale' => 'ar']);
        event(new CustomerLocaleChanged((string) Str::uuid(), $sara, CarbonImmutable::now()));
        expect(CapturedNewsletterList::installed()->calls)->toBe(["add sara@example.test {$ae} en", 'allow sara@example.test', "remove sara@example.test {$ae} en", "add sara@example.test {$ae} ar"]);

        Wx::set($sara, ['marketing_consent' => false]);
        event(new MarketingConsentChanged((string) Str::uuid(), $sara, CarbonImmutable::now()));
        expect(DB::table('ops.subscribers')->count())->toBe(0)
            ->and(end(CapturedNewsletterList::installed()->calls))->toBe("remove sara@example.test {$ae} ar");
    });

    it('handles each event once', function () {
        $sara = Wx::agreeing('sara@example.test');
        $event = new MarketingConsentChanged((string) Str::uuid(), $sara, CarbonImmutable::now());
        event($event);
        DB::table('ops.subscribers')->delete();
        event($event);

        expect(DB::table('ops.subscribers')->count())->toBe(0)
            ->and(DB::table('ops.handled_events')->where('event_id', $event->eventId)->count())->toBe(1);
    });

    it('is not on the list while they may not receive offers: email unverified, blocked, deletion pending', function () {
        $sara = Wx::agreeing('sara@example.test');
        Wx::set($sara, ['email_verified_at' => null]);
        event(new MarketingConsentChanged((string) Str::uuid(), $sara, CarbonImmutable::now()));
        expect(DB::table('ops.subscribers')->count())->toBe(0);

        Wx::set($sara, ['email_verified_at' => now()->toIso8601String()]);
        event(new MarketingConsentChanged((string) Str::uuid(), $sara, CarbonImmutable::now()));
        expect(DB::table('ops.subscribers')->count())->toBe(1);

        Wx::set($sara, ['status' => 'BLOCKED']);
        event(new CustomerBlocked((string) Str::uuid(), $sara, CarbonImmutable::now()));
        expect(DB::table('ops.subscribers')->count())->toBe(0);
    });

    it('takes over a guest row of the same email in their store, with what the provider holds', function () {
        $sara = Wx::agreeing('sara@example.test');
        newsletterGuestRow('sa', 'sara@example.test');

        event(new MarketingConsentChanged((string) Str::uuid(), $sara, CarbonImmutable::now()));

        expect(DB::table('ops.subscribers')->get()->map(fn ($row) => [$row->kind, $row->customer_id])->all())->toBe([['CUSTOMER', $sara]])
            // Held already, in the same list: nothing more to tell the provider.
            ->and(CapturedNewsletterList::installed()->calls)->toBe([]);
    });

    it('is forgotten when anonymized: the row, the provider\'s contact, their bell and the messages to them', function () {
        $sara = Wx::agreeing('sara@example.test');
        event(new MarketingConsentChanged((string) Str::uuid(), $sara, CarbonImmutable::now()));
        Ox::customerEntry($sara);
        $omar = Fx::customer('omar@example.test');
        $kept = Ox::customerEntry($omar);
        DB::table('ops.sent_messages')->insert(['id' => strtolower((string) Str::ulid()), 'kind' => 'order_taken', 'customer_id' => $sara, 'store_id' => null, 'channel' => 'EMAIL', 'provider_id' => null, 'status' => 'SENT', 'created_at' => now()]);
        Fx::actAsStaff(Fx::staffWith(['ops.message.send_one', 'access.customer.view'], ['sa']));
        app(SendStaffMessageHandler::class)->handle(new SendStaffMessage(Fx::storeId('sa'), $sara, 'ع', 'T', 'ن', 'B', true, false));

        $event = new CustomerAnonymized((string) Str::uuid(), $sara, CarbonImmutable::now());
        event($event);
        event($event);

        expect(DB::table('ops.subscribers')->count())->toBe(0)
            ->and(array_slice(CapturedNewsletterList::installed()->calls, -2))->toBe(['remove sara@example.test '.Fx::storeId('sa').' en', 'forget sara@example.test'])
            ->and(DB::table('ops.inbox_entries')->pluck('id')->all())->toBe([$kept])
            ->and(DB::table('ops.sent_messages')->where('customer_id', $sara)->count())->toBe(0)
            ->and(DB::table('ops.staff_messages')->count())->toBe(0);
    });
});

describe('the provider\'s unsubscribe', function () {
    it('takes a guest off, and withdraws a customer\'s consent through Access', function () {
        $sara = Wx::agreeing('sara@example.test');
        newsletterGuestRow('ae', 'sara@example.test');
        event(new MarketingConsentChanged((string) Str::uuid(), $sara, CarbonImmutable::now()));
        expect(DB::table('ops.subscribers')->count())->toBe(2);

        app(CustomerSubscriptions::class)->unsubscribedAtProvider('sara@example.test');

        expect(DB::table('ops.subscribers')->count())->toBe(0)
            ->and(DB::table('access.customers')->where('id', $sara)->first())->toMatchArray(['marketing_consent' => false, 'marketing_consent_source' => 'NEWSLETTER_PROVIDER']);
    });
});

describe('staff', function () {
    it('list a store\'s subscribers and remove a guest, under ops.newsletter.manage; never a customer', function () {
        app(NewsletterSignUps::class)->subscribe(newsletterStore(), 'en', 'guest@example.test');
        app(NewsletterSignUps::class)->confirm(newsletterToken(CapturedEmails::installed()));
        $sara = Wx::agreeing('sara@example.test');
        event(new MarketingConsentChanged((string) Str::uuid(), $sara, CarbonImmutable::now()));
        $guest = (string) DB::table('ops.subscribers')->where('kind', 'GUEST')->value('id');
        $customer = (string) DB::table('ops.subscribers')->where('kind', 'CUSTOMER')->value('id');

        Fx::actAsStaff(Fx::staffWith(['ops.notice.manage'], ['sa']));
        expect(fn () => app(ListSubscribersHandler::class)->handle(new ListSubscribers(Fx::storeId('sa'))))->toThrow(Unauthorized::class)
            ->and(fn () => app(RemoveSubscriberHandler::class)->handle(new RemoveSubscriber($guest)))->toThrow(Unauthorized::class);

        Fx::actAsStaff(Fx::staffWith(['ops.newsletter.manage'], ['sa']));
        $page = app(ListSubscribersHandler::class)->handle(new ListSubscribers(Fx::storeId('sa')));
        expect(array_map(static fn ($row): string => $row->email, $page->subscribers))->toEqualCanonicalizing(['guest@example.test', 'sara@example.test'])
            ->and(fn () => app(RemoveSubscriberHandler::class)->handle(new RemoveSubscriber($customer)))->toThrow(SubscriberNotFound::class);

        app(RemoveSubscriberHandler::class)->handle(new RemoveSubscriber($guest));
        expect(DB::table('ops.subscribers')->pluck('id')->all())->toBe([$customer])
            ->and(end(CapturedNewsletterList::installed()->calls))->toBe('remove guest@example.test '.Fx::storeId('sa').' en');
    });
});

describe('fix round 2', function () {
    it('deletes the provider\'s contact as soon as a deletion is pending, puts it back if cancelled, and finds nothing left when anonymized', function () {
        $sara = Wx::agreeing('sara@example.test');
        $sa = Fx::storeId('sa');
        event(new MarketingConsentChanged((string) Str::uuid(), $sara, CarbonImmutable::now()));

        Wx::set($sara, ['deletion_scheduled_for' => CarbonImmutable::now()->addDays(14)->toIso8601String()]);
        event(new CustomerDeletionScheduled((string) Str::uuid(), $sara, CarbonImmutable::now()));
        expect(DB::table('ops.subscribers')->count())->toBe(0)
            ->and(CapturedNewsletterList::installed()->calls)->toBe(["add sara@example.test {$sa} en", 'allow sara@example.test', "remove sara@example.test {$sa} en", 'forget sara@example.test']);

        Wx::set($sara, ['deletion_scheduled_for' => null]);
        event(new CustomerDeletionCancelled((string) Str::uuid(), $sara, CarbonImmutable::now()));
        expect(DB::table('ops.subscribers')->count())->toBe(1)
            ->and(array_slice(CapturedNewsletterList::installed()->calls, -2))->toBe(["add sara@example.test {$sa} en", 'allow sara@example.test']);

        Wx::set($sara, ['deletion_scheduled_for' => CarbonImmutable::now()->addDays(14)->toIso8601String()]);
        event(new CustomerDeletionScheduled((string) Str::uuid(), $sara, CarbonImmutable::now()));
        $before = count(CapturedNewsletterList::installed()->calls);
        Wx::set($sara, ['anonymized_at' => now()->toIso8601String()]);
        event(new CustomerAnonymized((string) Str::uuid(), $sara, CarbonImmutable::now()));

        expect(CapturedNewsletterList::installed()->last())->toBe('forget sara@example.test')
            ->and(count(CapturedNewsletterList::installed()->calls))->toBe($before)
            ->and(DB::table('ops.subscribers')->count())->toBe(0);
    });

    it('drops every guest row of a customer\'s email, in every store, when they register without agreeing', function () {
        foreach (['sa', 'ae'] as $code) {
            app(NewsletterSignUps::class)->subscribe(newsletterStore($code), 'en', 'sara@example.test');
            app(NewsletterSignUps::class)->confirm(newsletterToken(CapturedEmails::installed()));
        }
        app(NewsletterSignUps::class)->subscribe(newsletterStore(), 'en', 'omar@example.test');
        expect(DB::table('ops.subscribers')->count())->toBe(3);

        // Access's registration publishes CustomerRegistered: consent is off by default.
        Fx::customer('sara@example.test');

        expect(DB::table('ops.subscribers')->pluck('email')->all())->toBe(['omar@example.test'])
            ->and(CapturedNewsletterList::installed()->calls)->toContain('remove sara@example.test '.Fx::storeId('ae').' en');
    });

    it('drops a customer\'s guest rows when their account is anonymized', function () {
        $sara = Wx::agreeing('sara@example.test');
        newsletterGuestRow('ae', 'sara@example.test');
        event(new MarketingConsentChanged((string) Str::uuid(), $sara, CarbonImmutable::now()));
        expect(DB::table('ops.subscribers')->count())->toBe(2);

        event(new CustomerAnonymized((string) Str::uuid(), $sara, CarbonImmutable::now()));

        expect(DB::table('ops.subscribers')->count())->toBe(0)
            ->and(CapturedNewsletterList::installed()->last())->toBe('forget sara@example.test');
    });

    it('shows a customer\'s email in the list only to a reader who may view that customer', function () {
        app(NewsletterSignUps::class)->subscribe(newsletterStore(), 'en', 'guest@example.test');
        $sara = Wx::agreeing('sara@example.test');
        event(new MarketingConsentChanged((string) Str::uuid(), $sara, CarbonImmutable::now()));

        Fx::actAsStaff(Fx::staffWith(['ops.newsletter.manage'], ['sa']));
        $shown = app(WrittenPages::class)->subscribers(app(ListSubscribersHandler::class)->handle(new ListSubscribers(Fx::storeId('sa'))))->subscribers;
        expect(array_map(static fn ($row): ?string => $row->email, $shown))->toEqualCanonicalizing(['guest@example.test', null]);

        Fx::actAsStaff(Fx::staffWith(['ops.newsletter.manage', 'access.customer.view'], ['sa']));
        $shown = app(WrittenPages::class)->subscribers(app(ListSubscribersHandler::class)->handle(new ListSubscribers(Fx::storeId('sa'))))->subscribers;
        expect(array_map(static fn ($row): ?string => $row->email, $shown))->toEqualCanonicalizing(['guest@example.test', 'sara@example.test']);
    });

    it('meets a sign-up of the same new email made at the same moment without an error', function () {
        app(NewsletterSignUps::class)->subscribe(newsletterStore(), 'en', 'guest@example.test');
        $real = app(Subscribers::class);
        // The other request's row is not there yet when this one looks - the first time only.
        app()->instance(Subscribers::class, new NewsletterTestLateSubscribers($real));

        app(NewsletterSignUps::class)->subscribe(newsletterStore(), 'en', 'guest@example.test');

        expect(DB::table('ops.subscribers')->count())->toBe(1)
            ->and(CapturedEmails::installed()->sent)->toHaveCount(2)
            ->and(DB::table('ops.subscribers')->value('token_hash'))->toBe(hash('sha256', newsletterToken(CapturedEmails::installed())));
    });
});

/** Subscribers whose first look by email finds nothing: what a sign-up sees when another commits after it looked. */
final class NewsletterTestLateSubscribers implements Subscribers
{
    private bool $looked = false;

    public function __construct(private readonly Subscribers $real) {}

    public function lockByEmail(StoreId $store, string $email): ?Subscriber
    {
        if (! $this->looked) {
            $this->looked = true;

            return null;
        }

        return $this->real->lockByEmail($store, $email);
    }

    public function find(string $id): ?Subscriber
    {
        return $this->real->find($id);
    }

    public function lock(string $id): ?Subscriber
    {
        return $this->real->lock($id);
    }

    public function lockByTokenHash(string $tokenHash): ?Subscriber
    {
        return $this->real->lockByTokenHash($tokenHash);
    }

    public function lockByCustomer(string $customerId): ?Subscriber
    {
        return $this->real->lockByCustomer($customerId);
    }

    public function withEmail(string $email): array
    {
        return $this->real->withEmail($email);
    }

    public function save(Subscriber $subscriber): void
    {
        $this->real->save($subscriber);
    }

    public function delete(string $id): void
    {
        $this->real->delete($id);
    }

    public function page(StoreId $store, ?string $afterId, int $limit): array
    {
        return $this->real->page($store, $afterId, $limit);
    }
}
