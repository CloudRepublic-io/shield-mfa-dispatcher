# Shield MFA Dispatcher

Shield's `Config\Auth::$actions['login']` only accepts a single class
name - so out of the box you have to pick one MFA method for your
whole app. This package works around that by giving Shield exactly one
class (the dispatcher), which then decides *at request time*, per
user, which real Action to delegate to.

This is what makes it possible to:

- offer several MFA methods at once (email, WhatsApp, an authenticator
  app, or anything else you write as a Shield `ActionInterface`)
- let a user change their preferred method any time from a settings
  page, with no admin/deploy step involved
- add or remove a method from the whole app by editing one config
  array - `Config\Auth::$actions` itself never changes again

It's designed to sit alongside (not replace) the
[shield-whatsapp-mfa](../shield-whatsapp-mfa) and
[shield-totp-mfa](../shield-totp-mfa) packages built earlier in this
series, plus Shield's own built-in `Email2FA` - but works with any
`ActionInterface` implementation, including ones you write yourself.

## If you get "MfaDispatcher: cannot get the pending login user"

Fixed in the current version - update if you're on an older copy.
This was a real, confirmed bug, not a config mistake on your end: an
earlier version of `getType()` called `getPendingUser()`, but Shield
calls `getType()` from *inside* its own `setAuthAction()` - the exact
method that decides whether to mark a login as pending in the first
place, called *before* that decision is made. `getPendingUser()` only
ever returns non-null once that decision has already happened, so it
was unconditionally null at the one moment Shield actually calls this
method - not something that depended on which user was logging in, or
what they'd already set up. `TotpMfa`/`PasskeyMfa` never hit this
because their own `getType()` implementations don't need to know who
the user is for the common case; `MfaDispatcher`'s whole job requires
it. `getType()` now uses Shield's own documented `auth()->user()`
instead - see that method's own doc comment for the full explanation,
including an honest note that this specific fix (unlike most others in
this series) hasn't been confirmed against Shield's actual source the
same rigorous way; if you hit this error again after updating, that's
the next thing worth checking rather than assuming is already ruled
out.

## If you get "MfaDispatcher::handle(): Return value must be of type Response, string returned"

Also fixed in the current version. A different bug from the one above,
surfaced by fixing that one and actually reaching a real MFA method for
the first time: `handle()`/`verify()` returned whatever the delegated
action produced directly, but Shield's own built-in `Email2FA` doesn't
appear to return a `Response` from its `handle()` the way this
package's own `getType()`'s error confirmed it must for THAT method -
the error naming `MfaDispatcher::handle()` (not `Email2FA::handle()`)
as the violated contract is the tell: if `Email2FA::handle()` itself
declared `: Response` and tried to return a string, PHP would throw
from inside that method, not this one.

`handle()`/`verify()` now pass their result through a new
`ensureResponse()` helper first, which wraps a plain string into a
real `Response` the same way `TotpMfa`/`PasskeyMfa` already wrap their
own `show()`-mirroring `handle()` bodies, and passes an actual
`Response` through unchanged. This makes `MfaDispatcher` tolerant of
either contract from whatever it delegates to, rather than assuming
every real and custom `ActionInterface` implementation - including
Shield's own built-in ones - honors `: Response` as strictly as `show()`
is confirmed to require elsewhere in this series.

## How it works

```
Config\Auth::$actions['login'] = MfaDispatcher::class   <- the only thing Shield knows about

MfaDispatcher::show()/handle()/verify()
    -> looks up the pending user's stored preference (or the configured default)
    -> looks that method key up in Config\MfaDispatcher::$methods
    -> instantiates the real Action class and delegates the call to it
```

The preference itself is stored per-user via CodeIgniter's own Settings
library (see `MfaPreference`), using a per-user context rather than a
new database table - see "Why the preference is stored via Settings,
not a Shield identity" further down for why this changed from an
earlier, identity-based approach that had a real, confirmed bug.

## Installation

1. `composer require cloudrepublic/shield-mfa-dispatcher`, then:

   ```
   php spark mfa-dispatcher:setup
   ```

   which publishes `Config/MfaDispatcher.php` and
   `Language/en/MfaDispatcher.php` into your app.

2. Edit `app/Config/MfaDispatcher.php` and register whichever methods
   you have installed:

   ```php
   public array $methods = [
       'email'    => \CodeIgniter\Shield\Authentication\Actions\Email2FA::class,
       'whatsapp' => \WhatsAppMfa\Authentication\Actions\WhatsAppMfa::class,
       'totp'     => \TotpMfa\Authentication\Actions\TotpMfa::class,
       'passkey'  => \PasskeyMfa\Authentication\Actions\PasskeyMfa::class, // login only - see note below
   ];
   public string $defaultMethod = 'email';
   ```

   **On `passkey`:** this only wires up the login-verification step -
   unlike `totp`, this package's own settings page doesn't currently
   have a dedicated passkey enrollment flow the way it does for TOTP
   (`totpEnroll()`/`totpConfirm()`/`totpDisable()`). Link users to
   `shield-passkey-mfa`'s own settings page (`account/passkeys`)
   separately if you offer this method.

3. Register **only the dispatcher** in `app/Config/Auth.php`:

   ```php
   public array $actions = [
       'register' => null,
       'login'    => \MfaDispatcher\Authentication\Actions\MfaDispatcher::class,
   ];
   ```

   Do not also list `Email2FA`, `WhatsAppMfa`, or `TotpMfa` directly
   here - the dispatcher is the only thing Shield should call; it
   handles picking between the others itself.

4. Add the routes from `routes-snippet.php` to `app/Config/Routes.php`,
   and link to `account/mfa` from your account settings page.

## Changing a user's method programmatically

The settings page (`MfaSettingsController`) is just a thin UI over
`MfaPreference`, which you can also call directly from anywhere in
your own code - a support tool, an admin panel, a migration script
run once for existing users, etc.:

```php
use MfaDispatcher\Libraries\MfaPreference;

$preference = new MfaPreference();

$preference->set($user, 'whatsapp'); // switch them to WhatsApp
$preference->get($user);              // 'whatsapp'
$preference->clear($user);            // back to Config\MfaDispatcher::$defaultMethod
```

## Why the preference is stored via Settings, not a Shield identity

**Fixed in the current version - update if you're on an older copy.**
An earlier version of `MfaPreference` stored each user's chosen method
as a Shield "identity" record, with the method key itself (e.g.
`'totp'`) in the `secret` column - reusing Shield's own
`auth_identities` table the same way every other package in this
series reuses it for its own per-user data.

That table's `UNIQUE` constraint, however, is on `(type, secret)` -
**not** `(user_id, type, secret)`. Two different users both choosing
the same method (say, both picking `'totp'`) produced two rows with
the identical `(type='mfa_preference', secret='totp')` pair, and the
second user's own, entirely unrelated choice failed with a duplicate-key
database error. This is Shield's own base schema, not something this
package should (or safely could) alter - and it's a real, confirmed
bug, not a hypothetical one.

`MfaPreference` now stores the preference through CodeIgniter's own
[Settings library](https://settings.codeigniter.com/) instead, using
its per-user **context** mechanism - documented there as the
first-class, recommended way to "save settings on a user-by-user
basis" (their own example is a per-user theme preference, structurally
identical to what this class needs):

```php
// Roughly what MfaPreference now does internally:
service('settings')->set('MfaDispatcher.preference', 'totp', 'user:' . $user->id);
service('settings')->get('MfaDispatcher.preference', 'user:' . $user->id); // 'totp'
```

Each user's value is stored and looked up under its own context
string, with no shared uniqueness constraint between different users'
rows at all - any number of users can hold the value `'totp'`
simultaneously without any collision, since they're entirely separate
rows scoped by context, not sharing a single `(type, secret)` pair the
way the identity-based approach did. Nothing about `MfaPreference`'s
own public API (`get()`/`set()`/`clear()`) changed - only its internal
storage mechanism and (simpler) constructor, which no longer takes a
`UserIdentityModel` argument.

## Role-based MFA: turning it on, or requiring a specific method, per group

Shield's own permissions system (`Config\AuthGroups`, `$user->inGroup()`)
and this package's method-resolution logic are otherwise completely
separate - Shield has no built-in concept of "require MFA for group X".
Two `Config\MfaDispatcher` properties connect them:

```php
// Switches MFA on for these groups even when $required (above) is
// false - a user not in any of these, with $required false, skips MFA
// entirely, same as if this property didn't exist.
public array $enabledForGroups = ['admin', 'superadmin'];

// group => method key (from $methods). A user in one of these groups
// MUST use that specific method - their own stored preference is
// overridden entirely, not just defaulted - and if they haven't set
// it up yet, they're routed into that method's own registration-time
// activator, inline, before their login can complete.
public array $requiredMethodsForGroups = [
    'admin' => 'totp',
    'user'  => 'whatsapp',
];
```

**Both are read via `setting()`, not `$this->config->` directly** -
this is CodeIgniter's own [Settings library](https://settings.codeigniter.com/)
(the exact same one backing Shield's own permissions matrix - already
installed and migrated in your app if Shield is, nothing extra to set
up). The values above are just the *defaults*; a developer can build
an admin page that calls:

```php
service('settings')->set('MfaDispatcher.enabledForGroups', ['admin', 'superadmin']);
service('settings')->set('MfaDispatcher.requiredMethodsForGroups', ['admin' => 'totp']);
```

and it takes effect immediately for every subsequent login - no
config file edit, no redeploy. `get()` checks the database first and
falls back to the config file's value if nothing's been set there yet,
so the plain PHP defaults above are exactly that: defaults, not the
only way to set these.

### Why "enabled" and "required" are different, and why that matters

`enabledForGroups` only switches MFA *on*; it says nothing about
*which* method. A user in an enabled-but-not-required group still
resolves through the normal preference logic above, which means
they can end up on `$defaultMethod` - almost always `'email'`, which
needs no setup at all. That's deliberate: turning MFA on for a group
shouldn't force every member through an enrollment flow they haven't
opted into.

`requiredMethodsForGroups` is stricter on purpose: it names an exact
method, not just "some method", and it's a mandate that overrides
whatever the user already had chosen - not a new default they could
switch away from. This is what makes "admins must use TOTP, not
email, not WhatsApp" actually enforceable, and it's also why it needs
real setup-flow support: an admin required to use TOTP who's never
touched an authenticator app can't be waved through on email the way
an "enabled but not required" user can. See the next section for
exactly how that gets handled.

### How the forced-setup flow actually works

A user in a `requiredMethodsForGroups` group who hasn't set up that
method yet is, at the moment they attempt to log in, routed straight
into that method's own **registration-time activator** -
`TotpActivator`, `WhatsAppActivator`, or `PasskeyActivator` - instead
of its normal login-verification action, via
`Config\MfaDispatcher::$activatorClasses`:

```php
public array $activatorClasses = [
    'totp'     => \TotpMfa\Authentication\Actions\TotpActivator::class,
    'whatsapp' => \WhatsAppMfa\Authentication\Actions\WhatsAppActivator::class,
    'passkey'  => \PasskeyMfa\Authentication\Actions\PasskeyActivator::class,
];
```

No new "forced setup" class was needed for this - the activators
already do exactly the right job (operate on Shield's pending user,
walk them through enrollment, complete the login on success), since
they're the exact same classes each package already ships for
registration-time signup. The only change needed to make this safe was
teaching those activators to tell the two contexts apart: `verify()`
in each one now checks whether the user was already active *before*
doing anything else. An inactive user means genuine first-time
registration (activate the account, send them to `registerRedirect()`).
An already-active user means this is the forced-setup reuse - there's
no inactive account to activate, and they should land wherever a
normal login sends them instead. See each package's own README
("Design decisions worth knowing about" in `shield-totp-mfa`, and the
matching section in `shield-passkey-mfa`/`shield-whatsapp-mfa`) for the
specifics.

Since exactly one method is required per group (not a choice among
several), there's never a "pick a method" screen in this flow - a
required user goes straight into that one method's own enrollment
ceremony.

### A promoted user's old method doesn't quietly count

If a regular user enrolled in WhatsApp, then gets promoted into a
group that requires TOTP specifically, their WhatsApp enrollment
doesn't satisfy the new requirement - `MethodEnrollmentChecker` (used
internally by `resolveAction()`) checks whether the user has set up
*the specific required method*, not "have they set up anything at
all". They'll be routed into TOTP's activator on their next login,
same as someone who'd never enrolled in anything.

### Multiple matching groups

If a user belongs to more than one group listed in
`requiredMethodsForGroups`, whichever entry appears **first** in that
array wins - this package doesn't try to infer which of your groups is
"stricter". List your most sensitive role first if a user could
realistically hold more than one (e.g. `'superadmin'` before
`'admin'`).

### The settings page reflects a required method, rather than silently ignoring it

`MfaSettingsController`'s own page (`account/mfa` in the sample routes)
lets a user choose their own method - but a user whose group requires
one specific method has that choice overridden entirely at login
regardless of what they pick here (see `resolveRequiredMethod()`
above). Without anything on the page saying so, a user could "choose"
a different method, see it reflected as their own stored preference,
and never realize it will never actually be used - a real, confirmed
source of confusion.

The page now resolves the required method (if any) via the same
`MfaMethodResolver::requiredMethodFor()` used at login, and:

- shows a banner explaining that a specific method is required and why
  the choices below won't change anything,
- marks that method's own row with a "Required" badge, and suppresses
  the ordinary "Active" badge everywhere while a required method
  applies - "Active" reflects the user's own *stored* preference,
  which never actually gets used at all once a required method
  overrides it, so showing it anywhere in that situation would be
  factually misleading, not just redundant with "Required" (a real,
  confirmed inconsistency: a stored preference of `'email'` alongside
  a required `'totp'` previously showed "Active" on the email row and
  "Required" on the totp row simultaneously - two different rows both
  implying "this is what's used"),
- visually disables every *other* method's own "use this method"
  action (and any not-yet-enrolled/setup messaging for those, which
  would otherwise be genuinely misleading to show alongside a disabled
  action),
- **also** disables the required method's own "use this method" button
  whenever it isn't already the user's stored preference - a real,
  confirmed follow-up inconsistency: clicking it wouldn't change
  anything about what's actually enforced at login either way (the
  required method already wins regardless of the stored preference),
  so leaving it clickable was just as misleading as leaving the other
  methods' buttons clickable. A short note explains why in this
  specific case, since the row itself isn't visually greyed out the way
  the overridden ones are,
- **moves the required method's own row to the front of the list** -
  a real, confirmed follow-up: that same note says the required method
  is "your effective verification method, regardless of your own
  preference below," which is only spatially accurate if it genuinely
  renders above the others. Without this, it renders wherever it
  happens to sit in `Config\MfaDispatcher::$methods`, which could
  easily put it below the very preferences the note claims are
  "below" it. `index()` moves it to the front via a plain array-union
  reorder, leaving every other method's relative order untouched -
  and leaving the *normal*, no-requirement case's ordering (plain
  config array order) completely alone, since this reordering only
  ever happens when a required method actually applies.

`choose()` also refuses server-side if posted a method other than the
required one - the view-level disabling above is a UX courtesy, not a
security boundary, since a user could otherwise still POST a different
method directly. Rejecting it isn't a security fix (the required
method already always wins over any stored preference regardless,
enforced independently by `MfaMethodResolver` at login) - it exists
purely so a user's own stored preference doesn't drift into a value
that will never actually be honored, which would still be confusing to
see reflected as "Active" on this same page later.

Deliberately **not** blocked: enrolling in, or disabling, a
non-required method's own setup (an authenticator app, a WhatsApp
number) - a user may reasonably want a method set up in advance for
when a policy changes, or simply prefer having options. Only the
*preference-choosing* action for a non-required method is disabled,
since that's the part that would silently have no effect.

### A method's own label can reflect something that changes at runtime

Every method's display label on this page normally comes from a static
string - `lang('MfaDispatcher.methodLabel_' . $key)`. That's fine for a
method that's always just "TOTP" or "Email code" - but a method whose
own delivery mechanism can change at runtime (shield-whatsapp-mfa's
`Config\WhatsAppMfa::$channel`, which can switch between WhatsApp and
plain SMS) would otherwise leave this one page still saying "WhatsApp
code" regardless of which channel is actually configured - a real,
user-visible inconsistency, since every one of that package's own pages
correctly reflect the current channel already.

`Config\MfaDispatcher::$methodLabelResolvers` is the fix - a method key
=> callable map, where the callable takes no arguments and returns that
method's current display label:

```php
public array $methodLabelResolvers = [
    'whatsapp' => [\WhatsAppMfa\Libraries\ChannelLabel::class, 'current'],
];
```

Only consulted for a method key that has an entry here - any method
without one keeps using the static label exactly as before this
property existed. This package remains completely ignorant of what
`ChannelLabel` (or WhatsApp, or SMS) even is - it only knows "a resolver
is registered for this key, so ask it for the label instead of using
the static one," the same design already used for
`$customEnrollmentCheckers` above (see that property's own doc comment
for why a `[ClassName::class, 'staticMethodName']` pair is recommended
over a Closure, and why this package deliberately never reaches into a
specific sibling package's own classes by name).

**A real, confirmed follow-up: the method's own label wasn't the only
place "WhatsApp" was hardcoded.** The self-service settings page's
"Remove WhatsApp number" disable button, its confirmation prompt, and
the separate WhatsApp enrollment sub-pages (`mfa-settings-whatsapp-enroll`/
`-verify`) all had their own, independent hardcoded "WhatsApp" text -
different language keys from `methodLabel_whatsapp`, missed by the
first pass of this fix since they're genuinely separate strings, not
just the same one reused. All of these now substitute a `{channel}`
placeholder via the same resolved label - `mfa_settings_index.php` via
the `methodLabels` map `index()` already builds, and the three
dedicated WhatsApp enrollment views via a new, single-method
`resolveWhatsAppLabel()` controller helper (since those views only ever
need this one method's label, not the full per-method map).
Deliberately left alone: the "package not installed" messages
(`whatsappNotInstalled`, and `mfa_settings_whatsapp_unavailable.php`'s
own heading) - those keep a plain, static "WhatsApp" fallback rather
than attempting to resolve a channel, since if the package genuinely
isn't installed, calling a resolver that likely references a class
from that same absent package would risk a class-not-found error
rather than a graceful fallback.

## Adding your own custom MFA method

Everything in this package is built around method *keys* you define
yourself in config, not a fixed list this package hardcodes - a
hardware security key, a push-notification approval flow, or anything
else that implements Shield's own `ActionInterface` can be added
alongside (or instead of) TOTP/WhatsApp/passkey, without touching this
package's own source.

1. **Write your own login action** - a normal Shield `ActionInterface`
   implementation (`getType()`, `createIdentity()`, `show()`,
   `handle()`, `verify()`), exactly as you would for direct use in
   `Config\Auth::$actions`. Nothing dispatcher-specific is needed here.

2. **Register it in `$methods`**:

   ```php
   public array $methods = [
       'email'   => \CodeIgniter\Shield\Authentication\Actions\Email2FA::class,
       'yubikey' => \App\Authentication\Actions\YubikeyMfa::class,
   ];
   ```

   This alone is enough for users to choose `'yubikey'` as their own
   preference (`MfaSettingsController`, or your own UI calling
   `MfaPreference::set()` directly) and have it work correctly through
   the plain, non-required, preference-based resolution path - no
   further steps needed if you're not also using
   `$requiredMethodsForGroups` for this method.

3. **If you also want registration-time (or forced-setup) enrollment**,
   write your own activator action too (mirroring
   `TotpActivator`/`WhatsAppActivator`/`PasskeyActivator` from the
   sibling packages - each implements
   `CodeIgniter\Shield\Authentication\Actions\ConditionalActionInterface`'s
   `appliesTo(User $user): bool`, returning `false` once the user's
   already enrolled - see any of those classes' own doc comments for
   why this specific interface matters, a confirmed, real bug this
   package's own sibling packages hit without it), then register it:

   ```php
   public array $activatorClasses = [
       'yubikey' => \App\Authentication\Actions\YubikeyActivator::class,
   ];
   ```

4. **If you're using `$requiredMethodsForGroups` for this method,
   register a custom enrollment checker too** - this step is easy to
   miss, and skipping it produces a real, confirmed failure mode: every
   affected user gets routed into forced setup on *every single login,
   forever*, even immediately after they've genuinely completed it,
   because nothing tells `MethodEnrollmentChecker` how to recognize
   that they already have:

   ```php
   public array $customEnrollmentCheckers = [
       'yubikey' => [\App\Libraries\YubikeyIdentityStore::class, 'checkEnrollment'],
   ];
   ```

   **CORRECTION - a real report from a developer following an earlier
   version of this guide:** it originally recommended a `Closure` here
   instead, as this property's own default value. That's wrong, and
   fails outright: PHP has never allowed a Closure (or any non-constant
   expression) as a class property's default - only a genuine
   compile-time constant, which a Closure is not. A `[ClassName::class,
   'staticMethodName']` pair, as above, IS a valid compile-time
   constant (just two strings) - usable directly as a default with no
   workaround needed. Since a store's own `hasEnrolled()`-style method
   is normally an instance method, not a static one, add a small static
   wrapper rather than trying to reference the instance method
   directly:

   ```php
   // In your own store class:
   public static function checkEnrollment(\CodeIgniter\Shield\Entities\User $user): bool
   {
       return (new self())->hasEnrolled($user);
   }
   ```

   A Closure still works technically if assigned to this property
   *after* construction (e.g. from your own config class's
   constructor) rather than as its default - but a real report also
   confirmed that specific workaround didn't resolve reliably in
   practice, for reasons not fully pinned down. The static-method form
   above is recommended specifically because it sidesteps the entire
   question, working safely and directly as this array's own default
   value.

5. **Optionally, add step-up support** - your own filter implementing
   `CodeIgniter\Filters\FilterInterface`, registered in
   `$stepUpFilterClasses` under the same method key, if you want
   `RequireFreshMfa` to be able to challenge for freshness on this
   method too.

None of steps 1-5 require modifying this package's own source at any
point - every extension point is a config array your own app populates.

## Diagnostic logging is gated to development only

`MfaDispatcher::show()`/`resolveRequiredMethod()` and
`MethodEnrollmentChecker::isEnrolled()` log detailed information
(`user_id`, method keys, resolved class names, and - for `show()` -
the current request URI) while resolving which action applies to a
given login. This was added while diagnosing a real, confirmed bug
(see "A user with an existing passkey was still routed into
enrollment" in `shield-passkey-mfa`'s own README for the fuller story)
and is left in permanently, since it's genuinely useful the next time
something in this resolution logic needs diagnosing.

**A real concern, worth addressing directly:** logging `user_id`
values (and, in `show()`'s case, full request URIs) on every
MFA-required login isn't something that should silently accumulate in
a production application's log just because a past investigation
needed the visibility. All of it is routed through
`MfaDispatcher\Libraries\DiagnosticLog::write()`, a thin wrapper around
`log_message()` that only actually writes when `ENVIRONMENT ===
'development'` - in any other environment (staging, production,
testing), these calls are silent no-ops. If you need this visibility
again on a production-like environment, the practical option is
reproducing the issue with `CI_ENVIRONMENT=development` set for that
specific session, not turning on logging that writes to your real
production log continuously.

## Step-up auth for sensitive pages (`RequireFreshMfa` filter)

`shield-totp-mfa`, `shield-whatsapp-mfa`, and `shield-passkey-mfa` each
ship their own step-up filter (`RequireFreshTotp`,
`RequireFreshWhatsApp`, `RequireFreshPasskey`) - a route filter that
forces a fresh MFA challenge before reaching a sensitive page, even for
an already-fully-logged-in user. Using one of those directly commits a
protected route to exactly one method, which doesn't fit an app using
this package specifically **because** it lets users choose between
several. `RequireFreshMfa` is this package's own counterpart - not a
new challenge UI of its own, but a thin router that resolves which
method applies and delegates the entire freshness check to that
method's own filter.

### Default: step-up follows whatever the user logs in with

No configuration needed - a user who logs in with WhatsApp gets
challenged with WhatsApp for step-up; a user in an
admin-group-requires-TOTP setup gets challenged with TOTP. This reuses
the exact same resolution `MfaDispatcher` itself uses at login
(`MfaMethodResolver`, extracted into its own class specifically so both
share one policy) - so step-up automatically stays in sync with
whatever login resolves to, without an admin needing to keep the two
in agreement by hand as preferences or group rules change.

### Override: require one specific method for step-up, regardless of login method

```php
// Config\MfaDispatcher - read via setting(), so editable at runtime
// the same way $enabledForGroups is:
public ?string $stepUpMethod = 'totp';
```

For a policy like "billing changes always require an authenticator
app, even for users who normally log in with WhatsApp." Only takes
effect if that method's package is actually installed - falls back to
the default (per-user login-method) resolution otherwise, rather than
erroring outright.

### Setup

1. Map each installed package's own step-up filter in
   `Config\MfaDispatcher::$stepUpFilterClasses`:

   ```php
   public array $stepUpFilterClasses = [
       'totp'     => \TotpMfa\Filters\RequireFreshTotp::class,
       'whatsapp' => \WhatsAppMfa\Filters\RequireFreshWhatsApp::class,
       'passkey'  => \PasskeyMfa\Filters\RequireFreshPasskey::class,
   ];
   ```

2. Register the filter alias in `app/Config/Filters.php`:

   ```php
   public array $aliases = [
       // ... your existing aliases
       'mfa-fresh' => \MfaDispatcher\Filters\RequireFreshMfa::class,
   ];
   ```

3. Apply it to whichever routes need protecting, alongside your normal
   login-required filter:

   ```php
   $routes->group('admin/billing', ['filter' => ['session', 'mfa-fresh']], static function ($routes) {
       $routes->get('stripe-settings', 'Admin\BillingController::index');
       $routes->post('stripe-settings', 'Admin\BillingController::update');
   });
   ```

**No new routes to add for this package specifically** - unlike the
three packages this one delegates to, `RequireFreshMfa` has no
challenge page of its own. A user challenged this way lands on
whichever underlying package's own existing step-up route (e.g.
`totp-step-up`) - already wired up if you followed that package's own
"Step-up auth" setup instructions.

### What happens for a method with no step-up filter at all

Including Shield's own built-in `Email2FA` - nothing in this whole
series builds a step-up filter for it. A user whose login resolves to
a method with no entry in `$stepUpFilterClasses` passes through
without a challenge, the same "nothing to challenge them with" policy
default every filter in this series uses elsewhere. This is a real,
documented limitation, not a security promise: if step-up matters for
your app, either don't offer email as a login method for the groups
that need it, or set `$stepUpMethod` to force a specific,
step-up-capable method regardless of login method.

## Why TOTP and WhatsApp get their own settings-page flows

Switching straight to `'email'` is a one-line `$preference->set()`
call - it "just works" the next time the user logs in, since it needs
nothing prepared in advance.

`'totp'` and `'whatsapp'` are different: an authenticator app has to
actually scan a QR code and prove it can produce a valid code, or a
phone number has to be verified via a real code sent to it, *before*
either becomes usable as the active method - you can't just flip a
switch. Both packages' login actions are written to only ever verify,
never set up on their own (see each package's own README for why), so
setup always happens somewhere else: either registration-time
(`TotpActivator`), or here, in this settings page, for existing users
opting in later - and, for WhatsApp specifically, also
`shield-whatsapp-mfa`'s own standalone `account/whatsapp` settings
page, if a user reaches that directly instead of through here.

All three routes into TOTP setup - and all three into WhatsApp
verification - go through the same shared class each: TOTP through
`TotpMfa\Libraries\TotpIdentityStore`, WhatsApp through
`WhatsAppMfa\Libraries\PhoneNumberStore`. So there's no duplicated
enrollment/verification logic between this controller and either
package itself; `MfaSettingsController` just calls their public
methods directly - `beginEnrollment()`/`confirmEnrollment()`/`disable()`
for TOTP, `beginVerification()`/`confirmVerification()`/`removeVerifiedPhoneNumber()`
for WhatsApp.

## A sender failure here left an orphaned pending record too - fixed

**Fixed in the current version.** `whatsappSend()` had the identical
bug already fixed in `shield-whatsapp-mfa` itself (see that package's
own README, "A sender failure left an orphaned pending record behind -
fixed", for the full account of the original report): `beginVerification()`
creates a pending record, then the configured sender is called with no
`try`/`catch` at all. A thrown exception - a real Twilio/Meta API
error, a network issue, a misconfigured API key - propagated straight
through uncaught, crashing the request and leaving that pending record
orphaned indefinitely, since `confirmVerification()` (the only code
that would otherwise delete it) never got a chance to run.

This package has its own, separate copy of the WhatsApp enrollment flow
specifically so a settings page exists even for an app that hasn't
wired `shield-whatsapp-mfa`'s own standalone settings page into its
routes - so fixing the other package alone never covered this one; the
bug lived independently in both places.

**Fixed:** `whatsappSend()` now wraps the sender call in
`try { ... } catch (\Throwable $e) { ... }`, calling
`PhoneNumberStore::cancelVerification()` to roll back the pending
record and showing `MfaDispatcher.whatsappSendFailedMessage` (itself
`{channel}`-aware, via the same `resolveWhatsAppLabel()` helper used
elsewhere on this page) instead of crashing.

**Not covered by this package's own test suite - a genuine, pre-existing
limitation, not something new here:** `shield-whatsapp-mfa` is listed
only under this package's `composer.json` `suggest` block, not as a
test dependency, so `whatsappLibraryAvailable()` always returns `false`
in this package's own isolated tests, and every WhatsApp-related
controller method (this one included) short-circuits before reaching
any of the logic this fix touches. The identical fix in
`shield-whatsapp-mfa` itself - where the real package genuinely is
present - does have full regression test coverage; if you want this
specific fix exercised directly, that would require adding
`shield-whatsapp-mfa` as a dev dependency of this package specifically
to test it, a larger change than the fix itself.

**A related follow-up, found via a real report after the channel-label
fix above went out: `whatsappAlreadyVerified` was still hardcoded.**
Shown when `whatsappEnroll()` is reached by a user who already has a
verified number - "A WhatsApp number is already verified on this
account," regardless of the configured channel. Missed in the first
pass specifically because a broad search for "WhatsApp" text across
this file's own hardcoded strings was run *before* this particular
message's own code path was traced through by hand - a reminder that a
text search alone doesn't guarantee every runtime-reachable string was
actually caught. Fixed the same way as the others: a `{channel}`
placeholder in the language string, substituted via
`resolveWhatsAppLabel()` at the one call site.

## Graceful degradation

If `Config\MfaDispatcher::$methods` lists `'totp'` or `'whatsapp'` but
the matching package isn't actually installed (or the reverse -
installed but not listed), the settings page and dispatcher both
notice and degrade sensibly rather than fatal-erroring: the settings
page shows "not available" instead of a broken setup button, and the
dispatcher falls back to `$defaultMethod` if a stored preference points
at a method that's no longer configured.

## If you get a "must implement getType, createIdentity" fatal error

Current Shield versions require `ActionInterface` implementations to
also provide `getType()` and `createIdentity(User $user): string`.
Since `MfaDispatcher` is just a delegator with no fixed identity type
of its own, both simply forward to whichever real Action gets resolved
for the current user - see `getType()`/`createIdentity()` in
`MfaDispatcher.php`. If you installed `WhatsAppMfa` or `TotpMfa` from
an older copy, update those too - both needed the same two methods
added.

Worth noting explicitly: when `resolveAction()` returns `null` (the
user's config says MFA isn't required and they have no method set),
`createIdentity()` correctly returns `''` here. That's not a bug - an
empty return is exactly how Shield expects "this action doesn't apply,
skip it" to be signaled (see the `TotpMfa` package's README for a case
where the same mechanism caused a real bug, by being used
*unintentionally* rather than as a deliberate "skip MFA" signal).

## Login completion: `completeLogin()`, not `login()`

`completePendingLogin()` (used for the "MFA not required, skip
straight through" path) calls `completeLogin($user)`, not `login($user)`
- confirmed against Shield v1.3.0's real `Session::attempt()` source.
`login()` is a stricter, separate public method that refuses if
leftover identities exist for the pending action type (which matters
for TOTP specifically, since its secret is kept permanently), with an
error like *"The user has identities for action, so cannot complete
login."* `completeLogin()` is what Shield's own `attempt()` actually
calls to finish a pending action. Still worth a quick sanity check
against whichever Shield version you're running if you hit login
issues after upgrading -
`vendor/codeigniter4/shield/src/Authentication/Authenticators/Session.php`.

## If a settings/enrollment page loads but shows nothing at all

Same issue as the other two packages in this series: views must wrap
their content in a section called `'main'` to match what Shield's own
layout actually renders (`login.php` uses the same name) - not
`'content'`, which an earlier version of these files used. That
mistake doesn't produce any error or log entry; the page just renders
with an empty content area, since the layout never looks for a section
by that name.

## Overriding views

The settings page's views are looked up through
`Config\MfaDispatcher::$views`, the same pattern Shield itself uses for
`Config\Auth::$views`. To use your own view instead of a default,
override its entry in your `app/Config/MfaDispatcher.php`:

```php
public array $views = [
    'mfa_settings_index' => 'App\Views\account\my_mfa_settings',
    // any key you don't list keeps using this package's default
];
```

Your replacement doesn't need to live under any particular namespace -
anywhere `view()` can resolve works. It does need to accept the same
variables the default expects; check the matching file under
`src/Views/` for exactly what's passed. Overridable keys:
`mfa_settings_index`, `mfa_settings_totp_enroll`,
`mfa_settings_totp_unavailable`, `mfa_settings_whatsapp_enroll`,
`mfa_settings_whatsapp_verify`, `mfa_settings_whatsapp_unavailable`.

## Tests

**Test-suite reliability fixes**, mirroring `shield-totp-mfa`'s
confirmed, diagnostic-backed findings for the identical architecture
(session leakage between test methods, and `resetServices()`'s own
route-wiping side effect - see that package's README and
`TotpMfaTest`/`TotpActivatorTest` class doc comments for the full
account; not repeated here in full since the mechanism is identical):
`MfaDispatcherTest` now calls `resetServices()`, `session()->destroy()`,
and `Services::routes()->loadRoutes()` in `setUp()`, alongside the
`Config\Auth::$actions['login']` forcing it already had.
`MfaSettingsControllerTest`, `MfaWhatsAppSettingsIntegrationTest`, and
`RequireFreshMfaTest` have the defensive `loadRoutes()` piece only,
since they use `actingAs()` rather than `attempt()` and were never
affected by the session/pending-state issues specifically. This
package has no registration-time activator of its own (`MfaDispatcher`
only ever occupies the `'login'` slot), so the reflection-based
pending-state fix `TotpActivatorTest`/`WhatsAppActivatorTest`/
`PasskeyActivatorTest` each needed doesn't apply here.

`tests/MfaDispatcher/` covers the dispatcher's own delegation logic
(including the role-based `$enabledForGroups`/`$requiredMethodsForGroups`
resolution), the preference storage class, and the settings-page
method-switching - using fake `ActionInterface` implementations rather
than depending on Email2FA, WhatsAppMfa, or TotpMfa actually being
installed, since this package's job is picking the right method, not
implementing one.

It also covers this package's own WhatsApp integration
(`whatsappEnroll()`/`whatsappSend()`/`whatsappVerify()`/`whatsappConfirm()`/`whatsappDisable()`,
and `choose()`'s redirect-to-enroll behavior) - unlike the rest of the
suite, this part genuinely does need `shield-whatsapp-mfa` installed,
so it's skipped gracefully rather than failing when that package isn't
present. The step-up filter (`RequireFreshMfa`) has the same
dependency for the same reason - see below.

```
tests/MfaDispatcher/
  Support/FakeAction.php                      <- minimal fake MFA method #1
  Support/FakeActionTwo.php                   <- minimal fake MFA method #2 (needed to prove
                                                  preference-based selection, not just "a configured one")
  Support/FakeActivator.php                   <- minimal fake activator, distinct from the above -
                                                  represents what a required-but-unenrolled method
                                                  routes to
  Support/FakeWhatsAppSender.php              <- records what would have been sent, no real network call
  Authentication/Actions/MfaDispatcherTest.php <- delegation, preference resolution, graceful fallback,
                                                   enabled/required-for-groups resolution
  Libraries/MfaPreferenceTest.php              <- preference storage in isolation, including
                                                   regression tests for the fixed duplicate-key bug
  Libraries/MfaMethodResolverTest.php          <- the shared resolution logic, tested directly and in
                                                   isolation from both MfaDispatcher and RequireFreshMfa
  Controllers/MfaSettingsControllerTest.php    <- method-switching only, not TOTP/WhatsApp-specific methods
  Controllers/MfaWhatsAppSettingsIntegrationTest.php <- this package's own WhatsApp integration -
                                                          skipped if shield-whatsapp-mfa isn't installed
  Filters/RequireFreshMfaTest.php              <- step-up delegation logic - skipped if
                                                   shield-whatsapp-mfa isn't installed
```

### Setup

1. Copy `tests/MfaDispatcher` into your app's own `tests/` folder, the
   same way `src/` gets installed - see "Installation" above.
2. Make sure your test database has Shield's own migrations applied -
   `protected $namespace = null;` in each test class triggers this
   automatically, equivalent to `php spark migrate --all`, as long as
   the connection itself works.
3. Run it the same way as the rest of your suite:

   ```
   vendor/bin/phpunit tests/MfaDispatcher
   ```

### Why two different testing patterns in this one suite

`MfaDispatcherTest` uses a real `Session::attempt()` call with real
credentials to put the authenticator into a genuinely *pending* login
state - the same approach worked out (through several rounds of trial
and error, documented in `shield-totp-mfa`'s own `TotpMfaTest`) for
testing a Shield login Action directly. `MfaSettingsControllerTest` and
`MfaWhatsAppSettingsIntegrationTest` use `actingAs()` instead, because
those controllers are for someone *already fully logged in* managing
their own settings - a genuinely different state (`auth()->user()`,
not `getPendingUser()`), and `actingAs()` is the correct tool for it.

### How the role-based tests avoid needing real packages installed

`FakeActivator` (distinct from `FakeAction`/`FakeActionTwo`) stands in
for whatever a real package's registration-time activator would be.
Since `MethodEnrollmentChecker` only recognizes real package method
keys (`'totp'`, `'whatsapp'`, `'passkey'`) - never a fake one like
`'fake'` - it always reports a fake method as "not enrolled", which
turns out to be exactly what's needed to test the forced-setup routing
path without any real package present: configure `$activatorClasses`
for the fake method, and resolution always takes that path. Proving
"first-listed group wins" when a user matches more than one required
group (`testMultipleMatchingGroupsUsesWhicheverIsListedFirst`) uses a
deliberate asymmetry instead - only the *first-listed* group's method
has an activator configured, so a specific, correctly-worded exception
naming that method is what proves ordering, not just that *some*
exception happened to occur.

The tests that actually toggle these settings
(`service('settings')->set('MfaDispatcher.enabledForGroups', [...])`)
are also what prove the "runtime editable, no config file change or
redeploy needed" claim in this README's "Role-based MFA" section is
real, not just a comment - `testEnabledForGroupsOverrideTakesEffectImmediatelyAtRuntime`
specifically checks behavior *before* and *after* calling `set()`, with
nothing else changing in between.

### Why `show()` is never called in the "nothing to delegate to" scenario

`MfaDispatcher::show()` calls `exit` directly when no MFA method
resolves for a user (see its own doc comment) - a deliberate design
choice for a method that's contractually typed to return `string`, not
a `Response`. Calling it under that specific condition inside a test
would kill the PHPUnit process, so `MfaDispatcherTest` exercises that
path through `getType()`/`createIdentity()` instead, which safely
return `''` rather than exiting.

### Why TOTP-specific settings methods aren't tested in `MfaSettingsControllerTest`

`totpEnroll()`, `totpConfirm()`, and `totpDisable()` all depend on the
`shield-totp-mfa` package's `TotpIdentityStore` being installed
alongside this one. Rather than make this test suite conditionally
depend on that package, those code paths are left to
`shield-totp-mfa`'s own test suite, which already exercises
`TotpIdentityStore` directly - the actual logic both controllers share.

### Why WhatsApp gets its own, separately-skippable test file instead

WhatsApp's self-service flow got the opposite treatment from TOTP's:
rather than leaving it entirely to `shield-whatsapp-mfa`'s own test
suite (which only exercises `PhoneNumberStore` and that package's
*standalone* settings controller, not this package's integration of
it), `MfaWhatsAppSettingsIntegrationTest` tests this package's own
`whatsappEnroll()`/`whatsappSend()`/`whatsappVerify()`/`whatsappConfirm()`/`whatsappDisable()`
methods directly - genuinely new logic that belongs to this package
(how it wires `PhoneNumberStore` into its own settings page and
preference system), not just a re-test of the shared class.

Since that logic only exists to integrate with `shield-whatsapp-mfa`,
the test file can't avoid depending on it the way `MfaSettingsControllerTest`
avoids depending on `shield-totp-mfa`. Its `setUp()` checks
`class_exists('\WhatsAppMfa\Libraries\PhoneNumberStore')` - the exact
same check `MfaSettingsController` itself uses internally - and calls
`markTestSkipped()` if it's not installed, rather than failing. It uses
the real `PhoneNumberStore` (no network dependency of its own) plus a
locally-duplicated `FakeWhatsAppSender` (see that class's own doc
comment for why it's a separate copy from `shield-whatsapp-mfa`'s
identically-named test double, rather than shared) to keep sending
fake rather than real.

### Why `RequireFreshMfaTest` also depends on `shield-whatsapp-mfa`, and `MfaMethodResolverTest` doesn't

`MfaMethodResolver` (the shared resolution logic both `MfaDispatcher`
and `RequireFreshMfa` depend on) is pure policy/config logic - which
method key applies to a user - with no dependency on any specific
method's package actually being installed, so `MfaMethodResolverTest`
uses fakes throughout, same as `MfaDispatcherTest`.

`RequireFreshMfa` itself is different: its entire job is delegating to
a REAL step-up filter once a method is resolved, so testing that
delegation meaningfully needs a real one to delegate to - there's
nothing to fake here the way `FakeActivator` stands in for a real
registration-time activator elsewhere in this suite, since the thing
under test is specifically "does calling a real filter's `before()`
work correctly," not "was the right key chosen" (already covered by
`MfaMethodResolverTest`). `RequireFreshMfaTest`'s `setUp()` checks
`class_exists('\WhatsAppMfa\Filters\RequireFreshWhatsApp')` and skips
gracefully if it's not installed, the same pattern as
`MfaWhatsAppSettingsIntegrationTest`.
